<?php

namespace Tests\Support\Fakes;

use App\Domain\Opportunities\Contracts\RedirectHopClient;
use App\Domain\Opportunities\Data\RedirectHopRequest;
use App\Domain\Opportunities\Data\RedirectHopResponse;
use RuntimeException;
use Throwable;

final class FakeRedirectHopClient implements RedirectHopClient
{
    /** @var list<RedirectHopResponse|Throwable> */
    private array $outcomes = [];

    /** @var list<RedirectHopRequest> */
    public array $requests = [];

    public function queueResponse(int $status, ?string $location): self
    {
        $this->outcomes[] = new RedirectHopResponse($status, $location);

        return $this;
    }

    public function queueFailure(Throwable $exception): self
    {
        $this->outcomes[] = $exception;

        return $this;
    }

    public function head(RedirectHopRequest $request): RedirectHopResponse
    {
        $this->requests[] = $request;
        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome
            ?? throw new RuntimeException('No fake redirect response was queued.');
    }
}
