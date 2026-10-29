<?php

namespace App\Application\DemoAi;

use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\EvaluationProvider;
use App\Domain\DemoAi\ProviderOutcome;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Infrastructure\Email\BoundedDemoMailbox;
use App\Models\DemoAiItem;
use Illuminate\Support\Sleep;

final class ProcessDemoAi
{
    public function __construct(
        private readonly DemoAiLedger $ledger,
        private readonly BoundedDemoMailbox $mailbox,
        private readonly EvaluationProvider $provider,
        private readonly MonotonicClock $clock,
    ) {}

    public function execute(string $runId): void
    {
        $deadline = $this->clock->now() + 40;
        $run = $this->ledger->start($runId);
        if ($run === null) {
            return;
        }
        if ($run->source_snapshot === null) {
            try {
                $this->ledger->select($runId, $this->mailbox->discover(), $run->discovery_token);
            } catch (DemoAiException $exception) {
                $this->ledger->fail($runId, $exception->failureCode, $run->discovery_token);
            }

            return;
        }
        $item = DemoAiItem::query()->where('run_id', $runId)->where('workspace_id', $run->workspace_id)
            ->whereIn('status', ['pending', 'retry_wait'])
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now('UTC')))
            ->orderBy('ordinal')->first();
        if ($item === null) {
            return;
        }
        if ($item->input_snapshot === null || $run->approved_snapshot === null) {
            $this->ledger->fail($runId, 'demo_ai.snapshot_or_allocation_invalid');

            return;
        }
        try {
            $request = $this->provider->prepare($item->input_snapshot, $run->approved_snapshot);
        } catch (DemoAiException $exception) {
            $this->ledger->fail($runId, $exception->failureCode);

            return;
        }
        $attempt = $this->ledger->claim($item->id, $request);
        if ($attempt === null) {
            return;
        }
        if (! $this->ledger->authorizeDispatch($attempt->id)) {
            $this->ledger->settle($attempt->id, new ProviderOutcome(null, [], 0, 'demo_ai.dispatch_cancelled', retryable: true));

            return;
        }
        // The committed claim is the durable point after which process loss may mean billing.
        $outcome = $this->provider->evaluate(
            $request, $item->input_snapshot, $run->approved_snapshot,
            function (array $count) use ($attempt, $deadline): bool {
                if ($this->clock->now() + 7 >= $deadline) {
                    return false;
                }
                Sleep::for(2)->seconds();

                return $this->ledger->authorizeDispatch($attempt->id, $count);
            },
            $deadline,
        );
        $this->ledger->settle($attempt->id, $outcome);
    }
}
