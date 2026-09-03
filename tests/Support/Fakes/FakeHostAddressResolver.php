<?php

namespace Tests\Support\Fakes;

use App\Domain\Opportunities\Contracts\HostAddressResolver;
use RuntimeException;
use Throwable;

final class FakeHostAddressResolver implements HostAddressResolver
{
    /** @var array<string, list<string>> */
    private array $addresses = [];

    /** @var list<string> */
    public array $resolvedHosts = [];

    private ?Throwable $failure = null;

    /** @var list<list<string>|Throwable> */
    private array $queuedOutcomes = [];

    /** @var list<int> */
    public array $timeouts = [];

    /** @param list<string> $addresses */
    public function withAddresses(string $host, array $addresses): self
    {
        $this->addresses[$host] = $addresses;

        return $this;
    }

    public function failWith(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /** @param list<string> $addresses */
    public function queueAddresses(array $addresses): self
    {
        $this->queuedOutcomes[] = $addresses;

        return $this;
    }

    public function queueFailure(Throwable $failure): self
    {
        $this->queuedOutcomes[] = $failure;

        return $this;
    }

    public function resolve(string $host, int $timeoutMilliseconds): array
    {
        $this->resolvedHosts[] = $host;
        $this->timeouts[] = $timeoutMilliseconds;

        if ($this->queuedOutcomes !== []) {
            $outcome = array_shift($this->queuedOutcomes);

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->addresses[$host]
            ?? throw new RuntimeException('No fake addresses were configured for the requested host.');
    }
}
