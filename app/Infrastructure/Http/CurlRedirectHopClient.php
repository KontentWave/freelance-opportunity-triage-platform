<?php

namespace App\Infrastructure\Http;

use App\Domain\Opportunities\Contracts\RedirectHopClient;
use App\Domain\Opportunities\Data\PublicIpAddress;
use App\Domain\Opportunities\Data\RedirectHopRequest;
use App\Domain\Opportunities\Data\RedirectHopResponse;
use App\Domain\Opportunities\Enums\RedirectResolutionErrorCode;
use App\Domain\Opportunities\Exceptions\RedirectResolutionException;
use Closure;
use CurlHandle;
use RuntimeException;
use Throwable;

final class CurlRedirectHopClient implements RedirectHopClient
{
    private const MAXIMUM_CONNECT_TIMEOUT_MILLISECONDS = 2_000;

    private const MAXIMUM_TIMEOUT_MILLISECONDS = 5_000;

    private const MAXIMUM_HEADER_BYTES = 8_192;

    /** @var Closure(string, array<int, mixed>): array{successful: bool, status: int, error_number: int} */
    private Closure $executor;

    public function __construct(?Closure $executor = null)
    {
        $this->executor = $executor ?? $this->executeCurl(...);
    }

    public function head(RedirectHopRequest $request): RedirectHopResponse
    {
        $this->assertValidRequest($request);

        $headerBytes = 0;
        $headerLimitExceeded = false;
        $location = null;
        $locationCount = 0;

        $headerCallback = static function (CurlHandle $handle, string $line) use (
            &$headerBytes,
            &$headerLimitExceeded,
            &$location,
            &$locationCount,
            $request,
        ): int {
            $headerBytes += strlen($line);

            if ($headerBytes > $request->maximumHeaderBytes) {
                $headerLimitExceeded = true;

                return 0;
            }

            if (preg_match('#^HTTP/\d(?:\.\d)?\s#i', $line) === 1) {
                $location = null;
                $locationCount = 0;
            }

            if (str_starts_with(strtolower($line), 'location:')) {
                $locationCount++;
                $location = trim(substr($line, strlen('location:')));
            }

            return strlen($line);
        };

        try {
            $result = ($this->executor)($request->url, [
                CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutMilliseconds,
                CURLOPT_TIMEOUT_MS => $request->timeoutMilliseconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_REFERER => '',
                CURLOPT_HTTPHEADER => ['Accept:', 'Cookie:', 'Authorization:', 'Referer:'],
                CURLOPT_RESOLVE => [$this->resolveEntry($request)],
                CURLOPT_HEADERFUNCTION => $headerCallback,
            ]);
        } catch (Throwable) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::ResponseInvalid);
        }

        if ($headerLimitExceeded || $locationCount > 1) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::ResponseInvalid);
        }

        if (! $result['successful']) {
            $errorCode = $result['error_number'] === CURLE_OPERATION_TIMEDOUT
                ? RedirectResolutionErrorCode::Timeout
                : RedirectResolutionErrorCode::ResponseInvalid;

            throw new RedirectResolutionException($errorCode);
        }

        return new RedirectHopResponse(
            status: $result['status'],
            location: $location,
        );
    }

    private function resolveEntry(RedirectHopRequest $request): string
    {
        $address = str_contains($request->address, ':') ? '['.$request->address.']' : $request->address;

        return $request->host.':443:'.$address;
    }

    private function assertValidRequest(RedirectHopRequest $request): void
    {
        $parts = parse_url($request->url);

        if (strlen($request->url) > self::MAXIMUM_HEADER_BYTES
            || preg_match('/[^\x21-\x7e]|\\\\/', $request->url) === 1
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $request->host
            || $request->host !== strtolower($request->host)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::UrlRejected);
        }

        if (PublicIpAddress::from($request->address) === null) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::AddressRejected);
        }

        if ($request->connectTimeoutMilliseconds < 1
            || $request->connectTimeoutMilliseconds > self::MAXIMUM_CONNECT_TIMEOUT_MILLISECONDS
            || $request->timeoutMilliseconds < 1
            || $request->timeoutMilliseconds > self::MAXIMUM_TIMEOUT_MILLISECONDS
            || $request->maximumHeaderBytes < 1
            || $request->maximumHeaderBytes > self::MAXIMUM_HEADER_BYTES) {
            throw new RedirectResolutionException(RedirectResolutionErrorCode::ResponseInvalid);
        }
    }

    /** @return array{successful: bool, status: int, error_number: int} */
    private function executeCurl(string $url, array $options): array
    {
        $handle = curl_init($url);

        if (! $handle instanceof CurlHandle) {
            throw new RuntimeException('Unable to initialize redirect transport.');
        }

        try {
            if (! curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Unable to configure redirect transport.');
            }

            $successful = curl_exec($handle) !== false;

            return [
                'successful' => $successful,
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'error_number' => curl_errno($handle),
            ];
        } finally {
            curl_close($handle);
        }
    }
}
