<?php

namespace App\Infrastructure\Email;

use App\Domain\DemoAi\DemoAiException;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use Closure;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;

final class BoundedDemoMailbox
{
    /** @param null|Closure(): ImapProtocol $protocolFactory */
    public function __construct(
        private readonly DemoEmailNormalizer $normalizer,
        private readonly MonotonicClock $clock,
        private readonly ?Closure $protocolFactory = null,
    ) {}

    /** @return array{mailbox_key: string, uidvalidity: int, uidnext: int, scanned: int, bodies: int, bytes: int, duplicates: int, rejected: int, sources: list<array<string, mixed>>} */
    public function discover(): array
    {
        foreach (['mailbox_key', 'mailbox_host', 'mailbox_username', 'mailbox_password', 'mailbox_folder'] as $key) {
            if (! is_string(config('demo_ai.'.$key)) || trim(config('demo_ai.'.$key)) === '') {
                throw new DemoAiException('demo_ai.mailbox_configuration');
            }
        }
        if (config('opportunity_review.mode') !== 'demo'
            || config('demo_ai.mailbox_key') === config('opportunity_mailbox.mailbox_key')
            || (config('demo_ai.mailbox_host') === config('opportunity_mailbox.host')
                && config('demo_ai.mailbox_username') === config('opportunity_mailbox.username'))) {
            throw new DemoAiException('demo_ai.mailbox_configuration');
        }
        $started = $this->clock->now();
        $deadline = $started + 20;
        $protocol = $this->protocolFactory !== null
            ? ($this->protocolFactory)()
            : new DemoImapProtocol(Config::make(['options' => ['debug' => false, 'uid_cache' => false]]), true, 'ssl');
        try {
            $protocol->disableDebug();
            $protocol->disableUidCache();
            $protocol->setConnectionTimeout(5);
            if ($protocol instanceof DeadlineAwareImapProtocol) {
                $protocol->useDeadline($this->clock, $started + 5);
            }
            $protocol->connect((string) config('demo_ai.mailbox_host'), (int) config('demo_ai.mailbox_port'));
            if ($protocol instanceof DeadlineAwareImapProtocol) {
                $protocol->useDeadline($this->clock, $deadline);
            }
            $protocol->login((string) config('demo_ai.mailbox_username'), (string) config('demo_ai.mailbox_password'))->validate();
            $snapshot = $protocol->examineFolder((string) config('demo_ai.mailbox_folder'))->validatedData();
            $validity = $this->positiveInteger($snapshot['uidvalidity'] ?? null);
            $next = $this->positiveInteger($snapshot['uidnext'] ?? null);
            $result = [
                'mailbox_key' => (string) config('demo_ai.mailbox_key'),
                'uidvalidity' => $validity, 'uidnext' => $next,
                'scanned' => 0, 'bodies' => 0, 'bytes' => 0, 'duplicates' => 0, 'rejected' => 0, 'sources' => [],
            ];
            $seen = [];
            for ($uid = $next - 1; $uid >= max(1, $next - 100) && count($result['sources']) < 20; $uid--) {
                $this->checkDeadline($deadline);
                $result['scanned']++;
                $metadata = $protocol->fetch(['UID', 'RFC822.SIZE'], [$uid], null, IMAP::ST_UID)->validatedData();
                if (! isset($metadata[$uid])) {
                    continue;
                }
                $values = array_change_key_case($metadata[$uid], CASE_UPPER);
                if ($this->positiveInteger($values['UID'] ?? null) !== $uid) {
                    throw new DemoAiException('demo_ai.transport_identity');
                }
                $size = $this->positiveInteger($values['RFC822.SIZE'] ?? null);
                if ($size > 65_536) {
                    $result['rejected']++;

                    continue;
                }
                $this->checkDeadline($deadline);
                $body = $protocol->fetch(['UID', 'RFC822.SIZE', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
                $bodyValues = array_change_key_case($body[$uid] ?? [], CASE_UPPER);
                $raw = $bodyValues['BODY[]'] ?? null;
                if (($bodyValues['UID'] ?? null) != $uid || ! is_string($raw)
                    || strlen($raw) !== $size) {
                    throw new DemoAiException('demo_ai.transport_size');
                }
                $result['bodies']++;
                $result['bytes'] += strlen($raw);
                if ($result['bodies'] > 100 || $result['bytes'] > 6_553_600) {
                    throw new DemoAiException('demo_ai.scan_limit');
                }
                try {
                    $source = $this->normalizer->parse($raw);
                } catch (DemoAiException) {
                    $result['rejected']++;

                    continue;
                }
                if (isset($seen[$source['source_id']])) {
                    if ($seen[$source['source_id']] !== $source['body_sha256']) {
                        throw new DemoAiException('demo_ai.source_conflict');
                    }
                    $result['duplicates']++;

                    continue;
                }
                $seen[$source['source_id']] = $source['body_sha256'];
                $result['sources'][] = $source + ['uid' => $uid, 'uidvalidity' => $validity, 'mailbox_key' => $result['mailbox_key']];
            }
            $this->checkDeadline($deadline);
            $end = $protocol->examineFolder((string) config('demo_ai.mailbox_folder'))->validatedData();
            if ($this->positiveInteger($end['uidvalidity'] ?? null) !== $validity) {
                throw new DemoAiException('demo_ai.uidvalidity_changed');
            }
            $this->checkDeadline($deadline);

            return $result;
        } catch (DemoAiException $exception) {
            throw $exception;
        } catch (MailboxIntakeException $exception) {
            throw new DemoAiException($exception->errorCode === MailboxIntakeErrorCode::PollBudgetExhausted
                ? 'demo_ai.intake_timeout' : 'demo_ai.intake_failed');
        } catch (Throwable) {
            throw new DemoAiException('demo_ai.intake_failed');
        } finally {
            // Reset closes the socket without CLOSE/EXPUNGE or additional blocking protocol work.
            $protocol->reset();
        }
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($integer)) {
            throw new DemoAiException('demo_ai.transport_metadata');
        }

        return $integer;
    }

    private function checkDeadline(float $deadline): void
    {
        if ($this->clock->now() >= $deadline) {
            throw new DemoAiException('demo_ai.intake_timeout');
        }
    }
}
