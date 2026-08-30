<?php

namespace Tests\Support\Fakes;

use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\DiscoveredMailboxBatch;
use App\Domain\Mailbox\Data\MailboxCursor;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Data\MailboxProbeResult;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use Closure;
use RuntimeException;
use Throwable;

final class FakeMailboxClient implements MailboxClient
{
    /** @var list<DiscoveredMailboxBatch|Throwable> */
    private array $discoveryBatches = [];

    /** @var array<int, string> */
    private array $rawMessages = [];

    private ?Closure $beforeFetch = null;

    /** @var array<int, int> */
    private array $fetchFailuresRemaining = [];

    /** @var array<int, Throwable> */
    private array $fetchFailureExceptions = [];

    private ?Throwable $probeFailure = null;

    /** @var list<MailboxCursor> */
    public array $discoveryCursors = [];

    /** @var list<int> */
    public array $discoveryLimits = [];

    /** @var list<int> */
    public array $fetchedUids = [];

    public int $probeCallCount = 0;

    public int $closeCallCount = 0;

    public function queueDiscovery(DiscoveredMailboxBatch $batch): self
    {
        $this->discoveryBatches[] = $batch;

        return $this;
    }

    public function queueDiscoveryFailure(Throwable $exception): self
    {
        $this->discoveryBatches[] = $exception;

        return $this;
    }

    public function withRawMessage(int $uid, string $rawMessage): self
    {
        $this->rawMessages[$uid] = $rawMessage;

        return $this;
    }

    public function beforeFetch(Closure $callback): self
    {
        $this->beforeFetch = $callback;

        return $this;
    }

    public function failFetchTimes(int $uid, int $times): self
    {
        $this->fetchFailuresRemaining[$uid] = $times;
        $this->fetchFailureExceptions[$uid] = new MailboxIntakeException(MailboxIntakeErrorCode::MessageFetchFailed);

        return $this;
    }

    public function failFetchWith(int $uid, Throwable $exception, int $times = 1): self
    {
        $this->fetchFailuresRemaining[$uid] = $times;
        $this->fetchFailureExceptions[$uid] = $exception;

        return $this;
    }

    public function failProbeWith(Throwable $exception): self
    {
        $this->probeFailure = $exception;

        return $this;
    }

    public function probe(): MailboxProbeResult
    {
        $this->probeCallCount++;

        if ($this->probeFailure !== null) {
            throw $this->probeFailure;
        }

        return new MailboxProbeResult(successful: true);
    }

    public function discover(MailboxCursor $cursor, int $limit): DiscoveredMailboxBatch
    {
        $this->discoveryCursors[] = $cursor;
        $this->discoveryLimits[] = $limit;

        $batch = array_shift($this->discoveryBatches);

        if ($batch instanceof Throwable) {
            throw $batch;
        }

        if (! $batch instanceof DiscoveredMailboxBatch) {
            throw new RuntimeException('No fake discovery batch was queued.');
        }

        return $batch;
    }

    public function fetchRaw(MailboxMessageReference $message, int $maximumBytes): string
    {
        $this->fetchedUids[] = $message->uid;
        ($this->beforeFetch) && ($this->beforeFetch)($message);

        if (($this->fetchFailuresRemaining[$message->uid] ?? 0) > 0) {
            $this->fetchFailuresRemaining[$message->uid]--;

            throw $this->fetchFailureExceptions[$message->uid];
        }

        return $this->rawMessages[$message->uid]
            ?? throw new RuntimeException('No fake raw message was configured for the requested UID.');
    }

    public function close(): void
    {
        $this->closeCallCount++;
    }
}
