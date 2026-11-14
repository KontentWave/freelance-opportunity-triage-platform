<?php

namespace App\Infrastructure\Email;

use App\Domain\DemoAi\DemoAiException;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

final class DemoImapProtocol extends DeadlineAwareImapProtocol
{
    private int $receivedBytes = 0;

    private int $commandBytes = 0;

    private int $maxReceivedBytes = 7_000_000;

    private int $maxCommandBytes = 70_000;

    public function useByteLimits(int $maxReceivedBytes, int $maxCommandBytes): void
    {
        $this->maxReceivedBytes = $maxReceivedBytes;
        $this->maxCommandBytes = $maxCommandBytes;
    }

    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response
    {
        $this->commandBytes = 0;

        return parent::fetch($items, $from, $to, $uid);
    }

    protected function readByte(): string|false
    {
        if (++$this->receivedBytes > $this->maxReceivedBytes || ++$this->commandBytes > $this->maxCommandBytes) {
            throw new DemoAiException('demo_ai.transport_size');
        }

        return parent::readByte();
    }

    protected function applyTimeoutToStream(float $remainingSeconds): void
    {
        parent::applyTimeoutToStream(min(5.0, $remainingSeconds));
    }
}
