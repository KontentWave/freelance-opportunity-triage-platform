<?php

namespace App\Domain\Opportunities\Data;

final readonly class PublicIpAddress
{
    /** @var list<string> */
    private const DISALLOWED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    private function __construct(public string $value) {}

    public static function from(string $value): ?self
    {
        if (filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false || self::isInDisallowedRange($value)) {
            return null;
        }

        return new self($value);
    }

    private static function isInDisallowedRange(string $address): bool
    {
        $packedAddress = inet_pton($address);

        if ($packedAddress === false) {
            return true;
        }

        foreach (self::DISALLOWED_RANGES as $range) {
            [$network, $prefixLength] = explode('/', $range, 2);
            $packedNetwork = inet_pton($network);

            if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
                continue;
            }

            $prefixBits = (int) $prefixLength;
            $wholeBytes = intdiv($prefixBits, 8);
            $remainingBits = $prefixBits % 8;

            if (substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

            if ((ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
