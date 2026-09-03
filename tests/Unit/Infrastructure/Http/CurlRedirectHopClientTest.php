<?php

namespace Tests\Unit\Infrastructure\Http;

use App\Domain\Opportunities\Data\RedirectHopRequest;
use App\Domain\Opportunities\Enums\RedirectResolutionErrorCode;
use App\Domain\Opportunities\Exceptions\RedirectResolutionException;
use App\Infrastructure\Http\CurlRedirectHopClient;
use CurlHandle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CurlRedirectHopClientTest extends TestCase
{
    #[Test]
    public function it_builds_a_pinned_bodyless_request_without_redirects_proxies_or_sensitive_headers(): void
    {
        $capturedUrl = null;
        $capturedOptions = [];
        $executor = static function (string $url, array $options) use (&$capturedUrl, &$capturedOptions): array {
            $capturedUrl = $url;
            $capturedOptions = $options;
            self::emitHeaders($options, [
                "HTTP/1.1 302 Found\r\n",
                "Location: https://www.upwork.com/jobs/~200000000000000000001\r\n",
                "\r\n",
            ]);

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        };
        $client = new CurlRedirectHopClient($executor);

        $response = $client->head($this->request());

        $this->assertSame('https://link.t.upwork.com/ls/click?synthetic-token', $capturedUrl);
        $this->assertFalse($capturedOptions[CURLOPT_FOLLOWLOCATION]);
        $this->assertTrue($capturedOptions[CURLOPT_NOBODY]);
        $this->assertTrue($capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $capturedOptions[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame('', $capturedOptions[CURLOPT_PROXY]);
        $this->assertSame('*', $capturedOptions[CURLOPT_NOPROXY]);
        $this->assertSame(['link.t.upwork.com:443:93.184.216.34'], $capturedOptions[CURLOPT_RESOLVE]);
        $this->assertSame(['Accept:', 'Cookie:', 'Authorization:', 'Referer:'], $capturedOptions[CURLOPT_HTTPHEADER]);
        $this->assertArrayNotHasKey(CURLOPT_COOKIEFILE, $capturedOptions);
        $this->assertSame(302, $response->status);
        $this->assertSame('https://www.upwork.com/jobs/~200000000000000000001', $response->location);
    }

    #[Test]
    public function it_formats_an_ipv6_pinned_address_for_curl(): void
    {
        $resolveEntries = [];
        $executor = static function (string $url, array $options) use (&$resolveEntries): array {
            $resolveEntries = $options[CURLOPT_RESOLVE];
            self::emitHeaders($options, ["HTTP/1.1 302 Found\r\n", "Location: https://www.upwork.com/jobs/~1\r\n", "\r\n"]);

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        };
        $client = new CurlRedirectHopClient($executor);
        $request = new RedirectHopRequest(
            url: 'https://link.t.upwork.com/ls/click?token',
            host: 'link.t.upwork.com',
            address: '2606:2800:220:1:248:1893:25c8:1946',
            connectTimeoutMilliseconds: 2_000,
            timeoutMilliseconds: 5_000,
            maximumHeaderBytes: 8_192,
        );

        $client->head($request);

        $this->assertSame(
            ['link.t.upwork.com:443:[2606:2800:220:1:248:1893:25c8:1946]'],
            $resolveEntries,
        );
    }

    #[Test]
    public function it_aborts_when_raw_headers_exceed_the_limit(): void
    {
        $executor = static function (string $url, array $options): array {
            self::emitHeaders($options, [str_repeat('A', 8_193)]);

            return ['successful' => false, 'status' => 0, 'error_number' => CURLE_WRITE_ERROR];
        };
        $client = new CurlRedirectHopClient($executor);

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $client->head($this->request());
    }

    #[Test]
    public function it_maps_a_transport_timeout_to_a_stable_code(): void
    {
        $executor = static fn (string $url, array $options): array => [
            'successful' => false,
            'status' => 0,
            'error_number' => CURLE_OPERATION_TIMEDOUT,
        ];
        $client = new CurlRedirectHopClient($executor);

        $this->expectStableFailure(RedirectResolutionErrorCode::Timeout);

        $client->head($this->request());
    }

    #[Test]
    public function it_maps_other_transport_failures_without_exposing_native_errors(): void
    {
        $executor = static fn (string $url, array $options): array => [
            'successful' => false,
            'status' => 0,
            'error_number' => CURLE_SSL_CONNECT_ERROR,
        ];
        $client = new CurlRedirectHopClient($executor);

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $client->head($this->request());
    }

    #[Test]
    public function it_rejects_multiple_location_headers(): void
    {
        $executor = static function (string $url, array $options): array {
            self::emitHeaders($options, [
                "HTTP/1.1 302 Found\r\n",
                "Location: https://www.upwork.com/jobs/~1\r\n",
                "Location: https://www.upwork.com/jobs/~2\r\n",
                "\r\n",
            ]);

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        };
        $client = new CurlRedirectHopClient($executor);

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $client->head($this->request());
    }

    #[Test]
    public function it_does_not_reuse_a_location_from_an_earlier_response_block(): void
    {
        $executor = static function (string $url, array $options): array {
            self::emitHeaders($options, [
                "HTTP/1.1 103 Early Hints\r\n",
                "Location: https://www.upwork.com/jobs/~1\r\n",
                "\r\n",
                "HTTP/1.1 302 Found\r\n",
                "\r\n",
            ]);

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        };
        $client = new CurlRedirectHopClient($executor);

        $response = $client->head($this->request());

        $this->assertNull($response->location);
    }

    #[Test]
    public function it_maps_executor_exceptions_without_exposing_native_errors(): void
    {
        $executor = static function (string $url, array $options): array {
            throw new RuntimeException('sensitive native detail');
        };
        $client = new CurlRedirectHopClient($executor);

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $client->head($this->request());
    }

    #[Test]
    public function it_rejects_a_url_and_host_mismatch_before_executing_curl(): void
    {
        $executed = false;
        $client = new CurlRedirectHopClient(static function () use (&$executed): array {
            $executed = true;

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        });
        $request = new RedirectHopRequest(
            url: 'https://link.t.upwork.com/ls/click?token',
            host: 'www.upwork.com',
            address: '93.184.216.34',
            connectTimeoutMilliseconds: 2_000,
            timeoutMilliseconds: 5_000,
            maximumHeaderBytes: 8_192,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::UrlRejected);

        try {
            $client->head($request);
        } finally {
            $this->assertFalse($executed);
        }
    }

    #[Test]
    public function it_rejects_an_unsafe_url_before_executing_curl(): void
    {
        $executed = false;
        $client = new CurlRedirectHopClient(static function () use (&$executed): array {
            $executed = true;

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        });
        $request = new RedirectHopRequest(
            url: "https://link.t.upwork.com/ls/click?token\nvalue",
            host: 'link.t.upwork.com',
            address: '93.184.216.34',
            connectTimeoutMilliseconds: 2_000,
            timeoutMilliseconds: 5_000,
            maximumHeaderBytes: 8_192,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::UrlRejected);

        try {
            $client->head($request);
        } finally {
            $this->assertFalse($executed);
        }
    }

    #[Test]
    public function it_rejects_a_non_global_address_before_executing_curl(): void
    {
        $executed = false;
        $client = new CurlRedirectHopClient(static function () use (&$executed): array {
            $executed = true;

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        });
        $request = new RedirectHopRequest(
            url: 'https://link.t.upwork.com/ls/click?token',
            host: 'link.t.upwork.com',
            address: '100.64.0.1',
            connectTimeoutMilliseconds: 2_000,
            timeoutMilliseconds: 5_000,
            maximumHeaderBytes: 8_192,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::AddressRejected);

        try {
            $client->head($request);
        } finally {
            $this->assertFalse($executed);
        }
    }

    #[Test]
    public function it_rejects_limits_outside_the_approved_budget_before_executing_curl(): void
    {
        $executed = false;
        $client = new CurlRedirectHopClient(static function () use (&$executed): array {
            $executed = true;

            return ['successful' => true, 'status' => 302, 'error_number' => 0];
        });
        $request = new RedirectHopRequest(
            url: 'https://link.t.upwork.com/ls/click?token',
            host: 'link.t.upwork.com',
            address: '93.184.216.34',
            connectTimeoutMilliseconds: 2_001,
            timeoutMilliseconds: 5_001,
            maximumHeaderBytes: 8_193,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        try {
            $client->head($request);
        } finally {
            $this->assertFalse($executed);
        }
    }

    private function request(): RedirectHopRequest
    {
        return new RedirectHopRequest(
            url: 'https://link.t.upwork.com/ls/click?synthetic-token',
            host: 'link.t.upwork.com',
            address: '93.184.216.34',
            connectTimeoutMilliseconds: 2_000,
            timeoutMilliseconds: 5_000,
            maximumHeaderBytes: 8_192,
        );
    }

    /** @param array<int, mixed> $options */
    private static function emitHeaders(array $options, array $lines): void
    {
        $handle = curl_init();
        self::assertInstanceOf(CurlHandle::class, $handle);
        $callback = $options[CURLOPT_HEADERFUNCTION];

        try {
            foreach ($lines as $line) {
                $callback($handle, $line);
            }
        } finally {
            curl_close($handle);
        }
    }

    private function expectStableFailure(RedirectResolutionErrorCode $errorCode): void
    {
        $this->expectException(RedirectResolutionException::class);
        $this->expectExceptionMessage($errorCode->value);
    }
}
