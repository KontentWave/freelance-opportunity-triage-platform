<?php

namespace Tests\Unit\Application\Opportunities;

use App\Application\Opportunities\RedirectDestinationResolver;
use App\Domain\Opportunities\Enums\RedirectResolutionErrorCode;
use App\Domain\Opportunities\Exceptions\RedirectResolutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Fakes\FakeHostAddressResolver;
use Tests\Support\Fakes\FakeRedirectHopClient;

final class RedirectDestinationResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_an_allowlisted_redirect_without_requesting_the_destination(): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = (new FakeRedirectHopClient)
            ->queueResponse(302, 'https://www.upwork.com/jobs/~200000000000000000001');

        $destination = (new RedirectDestinationResolver($addresses, $hops))->resolve(
            'https://link.t.upwork.com/ls/click?synthetic-token',
        );

        $this->assertSame('200000000000000000001', $destination->externalJobId);
        $this->assertSame('https://www.upwork.com/jobs/~200000000000000000001', $destination->canonicalUrl);
        $this->assertSame(['link.t.upwork.com'], $addresses->resolvedHosts);
        $this->assertSame([2_000], $addresses->timeouts);
        $this->assertCount(1, $hops->requests);
        $this->assertSame('93.184.216.34', $hops->requests[0]->address);
        $this->assertSame(2_000, $hops->requests[0]->connectTimeoutMilliseconds);
        $this->assertLessThanOrEqual(5_000, $hops->requests[0]->timeoutMilliseconds);
        $this->assertSame(8_192, $hops->requests[0]->maximumHeaderBytes);
    }

    #[Test]
    #[DataProvider('rejectedUrlProvider')]
    public function it_rejects_an_unsafe_initial_url(string $url): void
    {
        $resolver = new RedirectDestinationResolver(
            new FakeHostAddressResolver,
            new FakeRedirectHopClient,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::UrlRejected);

        $resolver->resolve($url);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedUrlProvider(): iterable
    {
        yield 'HTTP' => ['http://link.t.upwork.com/ls/click?token'];
        yield 'userinfo' => ['https://user@link.t.upwork.com/ls/click?token'];
        yield 'explicit port' => ['https://link.t.upwork.com:443/ls/click?token'];
        yield 'IP literal' => ['https://127.0.0.1/ls/click?token'];
        yield 'fragment' => ['https://link.t.upwork.com/ls/click?token#fragment'];
        yield 'lookalike host' => ['https://link.t.upwork.com.evil.example/ls/click?token'];
        yield 'uppercase host' => ['https://LINK.T.UPWORK.COM/ls/click?token'];
        yield 'protocol relative' => ['//link.t.upwork.com/ls/click?token'];
        yield 'control character' => ["https://link.t.upwork.com/ls/click?token\nvalue"];
        yield 'backslash confusion' => ['https://link.t.upwork.com\\@evil.example/ls/click?token'];
        yield 'oversized' => ['https://link.t.upwork.com/ls/click?'.str_repeat('a', 8_193)];
    }

    #[Test]
    #[DataProvider('rejectedAddressProvider')]
    public function it_rejects_non_global_or_mixed_dns_answers(array $resolvedAddresses): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', $resolvedAddresses);
        $hops = new FakeRedirectHopClient;
        $resolver = new RedirectDestinationResolver($addresses, $hops);

        $this->expectStableFailure(RedirectResolutionErrorCode::AddressRejected);

        try {
            $resolver->resolve('https://link.t.upwork.com/ls/click?token');
        } finally {
            $this->assertSame([], $hops->requests);
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function rejectedAddressProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'IPv4 loopback' => [['127.0.0.1']];
        yield 'IPv4 private' => [['10.0.0.1']];
        yield 'IPv4 link local' => [['169.254.1.1']];
        yield 'carrier-grade NAT' => [['100.64.0.1']];
        yield 'documentation range' => [['192.0.2.1']];
        yield 'IPv6 loopback' => [['::1']];
        yield 'IPv6 private' => [['fd00::1']];
        yield 'IPv6 link local' => [['fe80::1']];
        yield 'IPv6 protocol assignment' => [['2001::1']];
        yield 'IPv6 6to4' => [['2002::1']];
        yield 'mixed answers' => [['93.184.216.34', '127.0.0.1']];
    }

    #[Test]
    public function it_revalidates_dns_on_every_tracking_hop(): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = (new FakeRedirectHopClient)
            ->queueResponse(302, 'https://link.t.upwork.com/ls/click?next-token')
            ->queueResponse(302, 'https://www.upwork.com/jobs/~200000000000000000002');

        $destination = (new RedirectDestinationResolver($addresses, $hops))->resolve(
            'https://link.t.upwork.com/ls/click?token',
        );

        $this->assertSame('200000000000000000002', $destination->externalJobId);
        $this->assertSame(['link.t.upwork.com', 'link.t.upwork.com'], $addresses->resolvedHosts);
        $this->assertCount(2, $hops->requests);
    }

    #[Test]
    public function it_rejects_dns_rebinding_to_a_non_global_address_before_the_second_request(): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->queueAddresses(['93.184.216.34'])
            ->queueAddresses(['127.0.0.1']);
        $hops = (new FakeRedirectHopClient)
            ->queueResponse(302, 'https://link.t.upwork.com/ls/click?next-token');
        $resolver = new RedirectDestinationResolver($addresses, $hops);

        $this->expectStableFailure(RedirectResolutionErrorCode::AddressRejected);

        try {
            $resolver->resolve('https://link.t.upwork.com/ls/click?token');
        } finally {
            $this->assertCount(1, $hops->requests);
            $this->assertSame(['link.t.upwork.com', 'link.t.upwork.com'], $addresses->resolvedHosts);
        }
    }

    #[Test]
    public function it_stops_after_three_redirect_responses(): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = (new FakeRedirectHopClient)
            ->queueResponse(302, 'https://link.t.upwork.com/ls/click?one')
            ->queueResponse(302, 'https://link.t.upwork.com/ls/click?two')
            ->queueResponse(302, 'https://link.t.upwork.com/ls/click?three');
        $resolver = new RedirectDestinationResolver($addresses, $hops);

        $this->expectStableFailure(RedirectResolutionErrorCode::LimitExceeded);

        try {
            $resolver->resolve('https://link.t.upwork.com/ls/click?token');
        } finally {
            $this->assertCount(3, $hops->requests);
        }
    }

    #[Test]
    #[DataProvider('invalidDestinationProvider')]
    public function it_rejects_an_invalid_destination(string $location, RedirectResolutionErrorCode $errorCode): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = (new FakeRedirectHopClient)->queueResponse(302, $location);
        $resolver = new RedirectDestinationResolver($addresses, $hops);

        $this->expectStableFailure($errorCode);

        $resolver->resolve('https://link.t.upwork.com/ls/click?token');
    }

    /** @return iterable<string, array{string, RedirectResolutionErrorCode}> */
    public static function invalidDestinationProvider(): iterable
    {
        yield 'lookalike' => [
            'https://www.upwork.com.evil.example/jobs/~200000000000000000001',
            RedirectResolutionErrorCode::UrlRejected,
        ];
        yield 'encoded path' => [
            'https://www.upwork.com/jobs/%7E200000000000000000001',
            RedirectResolutionErrorCode::DestinationInvalid,
        ];
        yield 'query' => [
            'https://www.upwork.com/jobs/~200000000000000000001?token=value',
            RedirectResolutionErrorCode::DestinationInvalid,
        ];
        yield 'non-job path' => [
            'https://www.upwork.com/nx/search/jobs/',
            RedirectResolutionErrorCode::DestinationInvalid,
        ];
    }

    #[Test]
    #[DataProvider('invalidResponseProvider')]
    public function it_rejects_an_invalid_redirect_response(int $status, ?string $location): void
    {
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = (new FakeRedirectHopClient)->queueResponse($status, $location);
        $resolver = new RedirectDestinationResolver($addresses, $hops);

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $resolver->resolve('https://link.t.upwork.com/ls/click?token');
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function invalidResponseProvider(): iterable
    {
        yield 'success status' => [200, null];
        yield 'missing location' => [302, null];
        yield 'empty location' => [302, ''];
        yield 'unsupported redirect status' => [300, 'https://www.upwork.com/jobs/~200000000000000000001'];
    }

    #[Test]
    public function it_enforces_the_absolute_deadline_before_a_request(): void
    {
        $clockValues = [0.0, 5_001.0];
        $clock = static function () use (&$clockValues): float {
            return array_shift($clockValues) ?? 5_001.0;
        };
        $hops = new FakeRedirectHopClient;
        $resolver = new RedirectDestinationResolver(
            (new FakeHostAddressResolver)->withAddresses('link.t.upwork.com', ['93.184.216.34']),
            $hops,
            $clock,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::Timeout);

        try {
            $resolver->resolve('https://link.t.upwork.com/ls/click?token');
        } finally {
            $this->assertSame([], $hops->requests);
        }
    }

    #[Test]
    public function it_enforces_the_absolute_deadline_after_dns_resolution(): void
    {
        $clockValues = [0.0, 0.0, 5_001.0];
        $clock = static function () use (&$clockValues): float {
            return array_shift($clockValues) ?? 5_001.0;
        };
        $addresses = (new FakeHostAddressResolver)
            ->withAddresses('link.t.upwork.com', ['93.184.216.34']);
        $hops = new FakeRedirectHopClient;
        $resolver = new RedirectDestinationResolver($addresses, $hops, $clock);

        $this->expectStableFailure(RedirectResolutionErrorCode::Timeout);

        try {
            $resolver->resolve('https://link.t.upwork.com/ls/click?token');
        } finally {
            $this->assertSame([2_000], $addresses->timeouts);
            $this->assertSame([], $hops->requests);
        }
    }

    #[Test]
    public function it_maps_dns_failures_without_exposing_the_native_message(): void
    {
        $resolver = new RedirectDestinationResolver(
            (new FakeHostAddressResolver)->failWith(new RuntimeException('sensitive DNS detail')),
            new FakeRedirectHopClient,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::AddressRejected);

        $resolver->resolve('https://link.t.upwork.com/ls/click?token');
    }

    #[Test]
    public function it_preserves_a_typed_dns_timeout(): void
    {
        $resolver = new RedirectDestinationResolver(
            (new FakeHostAddressResolver)->queueFailure(
                new RedirectResolutionException(RedirectResolutionErrorCode::Timeout),
            ),
            new FakeRedirectHopClient,
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::Timeout);

        $resolver->resolve('https://link.t.upwork.com/ls/click?token');
    }

    #[Test]
    public function it_maps_unexpected_hop_failures_without_exposing_the_native_message(): void
    {
        $resolver = new RedirectDestinationResolver(
            (new FakeHostAddressResolver)->withAddresses('link.t.upwork.com', ['93.184.216.34']),
            (new FakeRedirectHopClient)->queueFailure(new RuntimeException('sensitive transport detail')),
        );

        $this->expectStableFailure(RedirectResolutionErrorCode::ResponseInvalid);

        $resolver->resolve('https://link.t.upwork.com/ls/click?token');
    }

    private function expectStableFailure(RedirectResolutionErrorCode $errorCode): void
    {
        $this->expectException(RedirectResolutionException::class);
        $this->expectExceptionMessage($errorCode->value);
    }
}
