<?php

namespace App\Application\DemoAi;

use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use Illuminate\Support\Arr;

final class DemoAiReport
{
    /** @return array<string, mixed> */
    public function snapshot(DemoAiRun $run): array
    {
        $items = DemoAiItem::query()->where('run_id', $run->id)->where('workspace_id', $run->workspace_id)
            ->orderBy('ordinal')->get();
        $attempts = DemoAiAttempt::query()->whereIn('item_id', $items->modelKeys())
            ->orderBy('attempt_number')->get()->groupBy('item_id');

        return [
            'representation_version' => 'demo-report-v1',
            'id' => $run->id, 'status' => $run->status,
            'admitted_at' => $run->admitted_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'selected_count' => $run->selected_count, 'succeeded_count' => $run->succeeded_count,
            'failed_count' => $run->failed_count,
            'explanation' => self::explanation($run->failure_code),
            'approved' => $run->approved_snapshot,
            'approved_sha256' => $run->approved_snapshot_sha256,
            'version_hash' => $run->version_hash,
            'scan' => Arr::only($run->source_snapshot ?? [], ['scanned', 'bodies', 'duplicates', 'rejected']),
            'items' => $items->map(fn (DemoAiItem $item): array => [
                'source_id' => $item->source_id, 'status' => $item->status,
                'input' => $item->input_snapshot, 'input_sha256' => $item->input_sha256,
                'result' => $item->result, 'explanation' => self::explanation($item->failure_code),
                'attempts' => ($attempts->get($item->id) ?? collect())->map(fn (DemoAiAttempt $attempt): array => [
                    'number' => $attempt->attempt_number, 'status' => $attempt->status,
                    'evaluation_started_at' => $attempt->inference_authorized_at?->toIso8601String(),
                    'settled_at' => $attempt->settled_at?->toIso8601String(),
                    'provider' => $attempt->request_provenance['provider'] ?? null,
                    'requested_model' => $attempt->request_provenance['requested_model'] ?? null,
                    'returned_model' => $attempt->returned_model,
                    'provider_request_id' => $attempt->provider_request_id,
                    'request_sha256' => $attempt->request_provenance['request_sha256'] ?? null,
                    'settings' => Arr::only($attempt->request_provenance, [
                        'reasoning_effort', 'store', 'service_tier', 'cache_lifetime', 'max_input_tokens', 'max_output_tokens',
                    ]),
                ])->all(),
            ])->all(),
        ];
    }

    public static function explanation(?string $code): ?string
    {
        return match ($code) {
            null => null,
            'demo_ai.capacity' => 'The demo queue is full. No evaluation was started.',
            'demo_ai.admission_rate' => 'The request limit was reached. No evaluation was started.',
            'demo_ai.budget_exhausted' => 'The demo usage allowance is currently unavailable. No evaluation was started.',
            'demo_ai.disabled' => 'Live evaluation is disabled. Unstarted work was cancelled.',
            'demo_ai.deadline', 'demo_ai.cancelled' => 'The evaluation window ended before this work completed.',
            'demo_ai.accounting_blocked', 'demo_ai.usage_uncertain', 'demo_ai.stale_attempt',
            'demo_ai.provider_uncertain', 'demo_ai.dispatch_day_uncertain' => 'An evaluation outcome could not be confirmed. It is not a recommendation.',
            default => 'This work could not be evaluated safely. No recommendation is available for unsuccessful items.',
        };
    }
}
