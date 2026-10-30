<?php

namespace App\Application\DemoAi;

use App\Domain\DemoAi\DemoAiException;
use App\Domain\Triage\Support\CanonicalJson;
use App\Models\DemoAiRun;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OwnerReportDelivery
{
    public function __construct(
        private readonly DemoAiConfiguration $configuration,
        private readonly MailManager $mail,
    ) {}

    public function send(string $id): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new DemoAiException('demo_ai.delivery_transaction_active');
        }
        $workspace = $this->configuration->workspaceId();
        $recipient = $this->recipient();
        $identity = $this->recipientIdentity($recipient);

        [$snapshot, $state] = DB::transaction(function () use ($id, $workspace, $identity): array {
            $run = $this->report($id, $workspace);
            $delivery = DB::table('demo_ai_deliveries')->where('run_id', $id)->first();
            if ($delivery !== null) {
                return [null, $delivery->status];
            }
            DB::table('demo_ai_deliveries')->insert([
                'run_id' => $id,
                'report_sha256' => hash('sha256', CanonicalJson::encode($run->report_snapshot)),
                'recipient_identity' => $identity,
                'recipient_config_version' => config('demo_ai.owner_delivery.version'),
                'verification_reference_sha256' => hash('sha256', (string) config('demo_ai.owner_delivery.verification_reference')),
                'status' => 'prepared',
                'prepared_at' => now('UTC'),
            ]);

            return [$run->report_snapshot, 'prepared'];
        });

        if ($snapshot === null) {
            return $state;
        }

        try {
            $html = view('review.ai-report', ['report' => $snapshot])->render();
            $smtp = config('mail.mailers.smtp');
            $from = config('mail.from.address');
            if (! is_array($smtp) || ($smtp['transport'] ?? null) !== 'smtp'
                || ! is_string($smtp['host'] ?? null) || trim($smtp['host']) === ''
                || ! in_array((int) ($smtp['port'] ?? 0), [465, 587], true)
                || ! is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                throw new DemoAiException('demo_ai.delivery_transport_configuration');
            }

            // Build an isolated Laravel mailer: no log/failover transport or global recipient override.
            $mailer = $this->mail->build([
                'transport' => 'smtp',
                'scheme' => (int) $smtp['port'] === 465 ? 'smtps' : 'smtp',
                'host' => $smtp['host'], 'port' => (int) $smtp['port'],
                'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null,
                'timeout' => 10, 'require_tls' => true,
            ]);
        } catch (Throwable) {
            DB::table('demo_ai_deliveries')->where('run_id', $id)->where('status', 'prepared')
                ->update(['status' => 'failed', 'failure_code' => 'demo_ai.delivery_preparation_failed']);

            return 'failed';
        }

        $claimed = DB::transaction(function () use ($id, $workspace, $identity, $html): bool {
            $run = $this->report($id, $workspace);
            if (! hash_equals($identity, $this->recipientIdentity($this->recipient()))) {
                throw new DemoAiException('demo_ai.delivery_configuration_changed');
            }

            return DB::table('demo_ai_deliveries')->where('run_id', $id)->where('status', 'prepared')
                ->where('recipient_identity', $identity)
                ->where('report_sha256', hash('sha256', CanonicalJson::encode($run->report_snapshot)))
                ->update(['status' => 'sending', 'sending_at' => now('UTC'), 'html_sha256' => hash('sha256', $html)]) === 1;
        });
        if (! $claimed) {
            return 'not_claimed';
        }

        try {
            $sent = $mailer->html($html, function (Message $message) use ($recipient, $from, $html, $id): void {
                $message->from($from)->to($recipient)->subject('Fictional demo evaluation report')
                    ->attachData($html, 'fictional-demo-'.$id.'.html', ['mime' => 'text/html']);
            });
        } catch (Throwable) {
            // SMTP can accept DATA before a connection fails. Never infer non-delivery from an exception.
            DB::table('demo_ai_deliveries')->where('run_id', $id)->where('status', 'sending')
                ->update(['status' => 'uncertain', 'failure_code' => 'demo_ai.delivery_transport_uncertain']);

            return 'uncertain';
        }

        $status = $sent === null ? 'uncertain' : 'accepted';
        $recorded = DB::table('demo_ai_deliveries')->where('run_id', $id)->where('status', 'sending')->update([
            'status' => $status, 'accepted_at' => $sent === null ? null : now('UTC'),
            'failure_code' => $sent === null ? 'demo_ai.delivery_acceptance_unconfirmed' : null,
        ]);

        return $recorded === 1 ? $status : 'uncertain';
    }

    public function recover(string $id): string
    {
        $workspace = $this->configuration->workspaceId();

        return DB::transaction(function () use ($id, $workspace): string {
            $this->owned($id, $workspace);
            $delivery = DB::table('demo_ai_deliveries')->where('run_id', $id)->lockForUpdate()->first();
            if ($delivery === null) {
                throw new DemoAiException('demo_ai.delivery_unavailable');
            }
            if ($delivery->status === 'sending' && $delivery->sending_at <= now('UTC')->subSeconds(150)->toDateTimeString()) {
                DB::table('demo_ai_deliveries')->where('run_id', $id)->update([
                    'status' => 'uncertain', 'failure_code' => 'demo_ai.delivery_process_uncertain',
                ]);

                return 'uncertain';
            }
            if ($delivery->status === 'prepared') {
                DB::table('demo_ai_deliveries')->where('run_id', $id)->update([
                    'status' => 'failed', 'failure_code' => 'demo_ai.delivery_preparation_interrupted',
                ]);

                return 'failed';
            }

            return $delivery->status;
        });
    }

    public function receipt(string $id, string $reference): string
    {
        $workspace = $this->configuration->workspaceId();
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,95}\z/D', $reference)) {
            throw new DemoAiException('demo_ai.delivery_receipt_reference');
        }

        return DB::transaction(function () use ($id, $workspace, $reference): string {
            $this->owned($id, $workspace);
            $delivery = DB::table('demo_ai_deliveries')->where('run_id', $id)->lockForUpdate()->first();
            if ($delivery === null || ! in_array($delivery->status, ['accepted', 'uncertain'], true)) {
                throw new DemoAiException('demo_ai.delivery_receipt_state');
            }
            if ($delivery->receipt_reported_at === null) {
                DB::table('demo_ai_deliveries')->where('run_id', $id)->update([
                    'receipt_reported_at' => now('UTC'), 'receipt_reference_sha256' => hash('sha256', $reference),
                ]);
            }

            return 'receipt_operator_reported';
        });
    }

    private function recipient(): string
    {
        $recipient = config('demo_ai.owner_delivery.recipient');
        $allowlist = config('demo_ai.owner_delivery.allowlist_sha256');
        $version = config('demo_ai.owner_delivery.version');
        $verification = config('demo_ai.owner_delivery.verification_reference');
        if (config('demo_ai.owner_delivery.enabled') !== true
            || ! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($allowlist) || ! hash_equals(hash('sha256', $recipient), $allowlist)
            || ! is_string($version) || ! preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/D', $version)
            || ! is_string($verification) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,95}\z/D', $verification)) {
            throw new DemoAiException('demo_ai.delivery_recipient_configuration');
        }

        return $recipient;
    }

    private function recipientIdentity(string $recipient): string
    {
        return hash_hmac('sha256', implode('|', [
            $recipient, config('demo_ai.owner_delivery.version'), config('demo_ai.owner_delivery.verification_reference'),
        ]), (string) config('app.key'));
    }

    private function owned(string $id, string $workspace): DemoAiRun
    {
        $run = DemoAiRun::query()->whereKey($id)->where('workspace_id', $workspace)->lockForUpdate()->first();
        if ($run === null) {
            throw new DemoAiException('demo_ai.delivery_report_unavailable');
        }

        return $run;
    }

    private function report(string $id, string $workspace): DemoAiRun
    {
        $run = $this->owned($id, $workspace);
        if ($run->finalized_at === null || ! in_array($run->status, ['completed', 'partial', 'failed', 'rejected'], true)
            || $run->purged_at !== null || $run->expires_at === null || $run->expires_at->lessThanOrEqualTo(now())
            || $run->report_snapshot === null || ($run->report_snapshot['id'] ?? null) !== $run->id
            || ($run->report_snapshot['representation_version'] ?? null) !== 'demo-report-v1'
            || ($run->report_snapshot['approved']['profile_version'] ?? null) !== 'public-profile-v2'
            || ($run->report_snapshot['approved']['prompt_version'] ?? null) !== 'public-evaluation-v2') {
            throw new DemoAiException('demo_ai.delivery_report_unavailable');
        }

        return $run;
    }
}
