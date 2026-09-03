<?php

namespace App\Application\Opportunities;

use App\Domain\Opportunities\Contracts\HostAddressResolver;
use App\Domain\Opportunities\Contracts\RedirectHopClient;
use App\Domain\Opportunities\Data\PublicIpAddress;
use App\Domain\Opportunities\Data\RedirectHopRequest;
use App\Domain\Opportunities\Data\ResolvedJobDestination;
use App\Domain\Opportunities\Enums\RedirectResolutionErrorCode;
use App\Domain\Opportunities\Exceptions\RedirectResolutionException;
use Closure;
use Throwable;

final class RedirectDestinationResolver
{
    private const INITIAL_HOST = 'link.t.upwork.com';

    private const CANONICAL_HOST = 'www.upwork.com';

    private const MAXIMUM_REDIRECTS = 3;

    private const CONNECT_TIMEOUT_MILLISECONDS = 2_000;

    private const DNS_TIMEOUT_MILLISECONDS = 2_000;

    private const TOTAL_TIMEOUT_MILLISECONDS = 5_000;

    private const MAXIMUM_HEADER_BYTES = 8_192;

    /** @var Closure(): float */
    private Closure $clock;

    public function __construct(
        private readonly HostAddressResolver $addressResolver,
        private readonly RedirectHopClient $hopClient,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    /** @throws RedirectResolutionException */
    public function resolve(string $trackingUrl): ResolvedJobDestination
    {
        $currentUrl = $this->validateUrl($trackingUrl, [self::INITIAL_HOST]);
        $deadline = ($this->clock)() + self::TOTAL_TIMEOUT_MILLISECONDS;

        for ($redirectCount = 0; $redirectCount < self::MAXIMUM_REDIRECTS; $redirectCount++) {
            $remainingMilliseconds = $this->remainingMilliseconds($deadline);

            $host = (string) parse_url($currentUrl, PHP_URL_HOST);
            try {
                $addresses = $this->addressResolver->resolve(
                    $host,
                    min(self::DNS_TIMEOUT_MILLISECONDS, $remainingMilliseconds),
                );
            } catch (RedirectResolutionException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new RedirectResolutionException(RedirectResolutionErrorCode::AddressRejected);
            }

            $address = $this->firstAddressIfAllPublic($addresses);

            if ($address === null) {
                throw new RedirectResolutionException(RedirectResolutionErrorCode::AddressRejected);
            }

            $remainingMilliseconds = $this->remainingMilliseconds($deadline);

            try {
                $response = $this->hopClient->head(new RedirectHopRequest(
                    url: $currentUrl,
                    host: $host,
                    address: $address,
                    connectTimeoutMilliseconds: min(self::CONNECT_TIMEOUT_MILLISECONDS, $remainingMilliseconds),
                    timeoutMilliseconds: $remainingMilliseconds,
                    maximumHeaderBytes: self::MAXIMUM_HEADER_BYTES,
                ));
            } catch (RedirectResolutionException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new RedirectResolutionException(RedirectResolutionErrorCode::ResponseInvalid);
            }

            if (! in_array($response->status, [301, 302, 303, 307, 308], true)
                || $response->location === null
                || $response->location === '') {
                throw new RedirectResolutionException(RedirectResolutionErrorCode::ResponseInvalid);
            }

            $nextUrl = $this->validateUrl($response->location, [self::INITIAL_HOST, self::CANONICAL_HOST]);
            $destination = $this->canonicalDestination($nextUrl);

            if ($destination !== null) {
                return $destination;
            }

            if (parse_url($nextUrl, PHP_URL_HOST) !== self::INITIAL_HOST) {
                throw new RedirectResolutionException(RedirectResolutionErrorCode::DestinationInvalid);
            }

            $currentUrl = $nextUrl;
        }

        throw new RedirectResolutionException(RedirectResolutionErrorCode::LimitExceeded);
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function validateUrl(string $url, array $allowedHosts): string
    {
        $parts = parse_url($url);

        if (strlen($url) > self::MAXIMUM_HEADER_BYTES
            || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::UrlRejected);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! in_array($host, $allowedHosts, true)
            || $host !== (string) ($parts['host'] ?? '')) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::UrlRejected);
        }

        return $url;
    }

    private function remainingMilliseconds(float $deadline): int
    {
        $remainingMilliseconds = (int) floor($deadline - ($this->clock)());

        if ($remainingMilliseconds < 1) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::Timeout);
        }

        return $remainingMilliseconds;
    }

    /** @param list<string> $addresses */
    private function firstAddressIfAllPublic(array $addresses): ?string
    {
        $firstAddress = null;

        foreach ($addresses as $address) {
            $publicAddress = PublicIpAddress::from($address);

            if ($publicAddress === null) {
                return null;
            }

            $firstAddress ??= $publicAddress->value;
        }

        return $firstAddress;
    }

    private function canonicalDestination(string $url): ?ResolvedJobDestination
    {
        if (parse_url($url, PHP_URL_HOST) !== self::CANONICAL_HOST
            || parse_url($url, PHP_URL_QUERY) !== null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/jobs/~(\d+)$#', $path, $matches) !== 1) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::DestinationInvalid);
        }

        return new ResolvedJobDestination(
            externalJobId: $matches[1],
            canonicalUrl: 'https://'.self::CANONICAL_HOST.'/jobs/~'.$matches[1],
        );
    }
}
