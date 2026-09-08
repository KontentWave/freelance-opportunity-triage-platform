<?php

namespace App\Infrastructure\Email;

use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Mailbox\Data\MailboxPollBudget;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

class DeadlineAwareImapProtocol extends ImapProtocol
{
    private ?MonotonicClock $clock = null;

    private ?float $deadline = null;

    public function __destruct()
    {
        $this->reset();
    }

    public function usePollBudget(MailboxPollBudget $budget): void
    {
        $this->useDeadline($budget->clock(), $budget->workDeadline());
    }

    public function useDeadline(MonotonicClock $clock, float $deadline): void
    {
        $this->clock = $clock;
        $this->deadline = $deadline;
    }

    /** @throws MailboxIntakeException */
    public function connect(string $host, ?int $port = null): bool
    {
        $remainingSeconds = $this->remainingSeconds();
        if ($remainingSeconds < 1.0) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::PollBudgetExhausted);
        }

        $this->setConnectionTimeout((int) floor($remainingSeconds));

        $connected = parent::connect($host, $port);
        $this->throwIfDeadlineExhausted();

        return $connected;
    }

    /** @throws MailboxIntakeException */
    public function nextLine(Response $response): string
    {
        $line = '';
        $nextCharacter = null;

        while (true) {
            $this->prepareBlockingOperation();
            $nextCharacter = $this->readByte();
            $this->throwIfDeadlineExhausted();

            if ($nextCharacter === false || $nextCharacter === '' || $nextCharacter === "\n") {
                break;
            }

            $line .= $nextCharacter;
        }

        if ($line === '' && ($nextCharacter === false || $nextCharacter === '')) {
            throw new RuntimeException('empty response');
        }

        $line .= "\n";
        $response->setResponse([...$response->getResponse(), $line]);

        return $line;
    }

    /** @throws MailboxIntakeException */
    public function write(Response $response, string $data): void
    {
        $command = $data."\r\n";
        $response->addCommand($command);
        $this->prepareBlockingOperation();

        if ($this->writeBytes($command) === false) {
            throw new RuntimeException('failed to write - connection closed?');
        }

        $this->throwIfDeadlineExhausted();
    }

    protected function readByte(): string|false
    {
        return fread($this->stream, 1);
    }

    protected function writeBytes(string $data): int|false
    {
        return fwrite($this->stream, $data);
    }

    protected function applyTimeoutToStream(float $remainingSeconds): void
    {
        $seconds = (int) floor($remainingSeconds);
        $microseconds = (int) ceil(($remainingSeconds - $seconds) * 1_000_000);

        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds = 0;
        }

        if (! is_resource($this->stream)
            || ! stream_set_timeout($this->stream, $seconds, max(1, $microseconds))) {
            throw new RuntimeException('failed to set stream timeout');
        }
    }

    private function prepareBlockingOperation(): void
    {
        $remainingSeconds = $this->remainingSeconds();
        $this->applyTimeoutToStream($remainingSeconds);
    }

    private function remainingSeconds(): float
    {
        if ($this->clock === null || $this->deadline === null) {
            return $this->getConnectionTimeout();
        }

        $remainingSeconds = $this->deadline - $this->clock->now();
        if ($remainingSeconds <= 0.0) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::PollBudgetExhausted);
        }

        return $remainingSeconds;
    }

    private function throwIfDeadlineExhausted(): void
    {
        $this->remainingSeconds();

        if (is_resource($this->stream) && $this->streamTimedOut()) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::PollBudgetExhausted);
        }
    }

    protected function streamTimedOut(): bool
    {
        return stream_get_meta_data($this->stream)['timed_out'];
    }
}
