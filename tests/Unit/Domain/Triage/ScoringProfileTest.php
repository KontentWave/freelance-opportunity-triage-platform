<?php

namespace Tests\Unit\Domain\Triage;

use App\Domain\Triage\Data\ScoringProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScoringProfileTest extends TestCase
{
    #[Test]
    public function it_normalizes_the_complete_profile_and_produces_a_stable_content_version(): void
    {
        $profile = ScoringProfile::fromArray([
            'thresholds' => ['apply_at' => 70, 'skip_below' => 35],
            'minimum_client_rating' => '4.50',
            'preferred_skills' => [" Quality\u{00A0} Assurance ", 'Django', 'project   management'],
            'weights' => [
                'client_rating' => 10,
                'payment_verified' => 20,
                'rate' => 30,
                'skill_match' => 40,
            ],
            'minimum_hourly_usd' => '20.00',
            'purpose' => 'demo',
            'label' => 'Synthetic demo profile',
            'schema_version' => 1,
        ]);

        $expectedDefinition = [
            'schema_version' => 1,
            'label' => 'Synthetic demo profile',
            'purpose' => 'demo',
            'minimum_hourly_usd' => '20.00',
            'preferred_skills' => ['django', 'project management', 'quality assurance'],
            'minimum_client_rating' => '4.50',
            'weights' => [
                'skill_match' => 40,
                'rate' => 30,
                'payment_verified' => 20,
                'client_rating' => 10,
            ],
            'thresholds' => ['skip_below' => 35, 'apply_at' => 70],
        ];
        $expectedCanonicalJson = '{"label":"Synthetic demo profile","minimum_client_rating":"4.50","minimum_hourly_usd":"20.00","preferred_skills":["django","project management","quality assurance"],"purpose":"demo","schema_version":1,"thresholds":{"apply_at":70,"skip_below":35},"weights":{"client_rating":10,"payment_verified":20,"rate":30,"skill_match":40}}';

        $this->assertSame($expectedDefinition, $profile->definition);
        $this->assertSame($expectedCanonicalJson, $profile->canonicalJson);
        $this->assertSame(hash('sha256', $expectedCanonicalJson), $profile->version);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidProfiles(): iterable
    {
        $valid = self::validDefinition();

        yield 'unknown top-level key' => [$valid + ['unexpected' => true]];
        yield 'wrong schema version' => [array_replace($valid, ['schema_version' => 2])];
        yield 'empty normalized preferred skill' => [array_replace($valid, ['preferred_skills' => ['django', '  ']])];
        yield 'duplicate normalized preferred skill' => [array_replace($valid, ['preferred_skills' => ['Django', ' django ']])];
        yield 'weights do not total 100' => [array_replace($valid, ['weights' => array_replace($valid['weights'], ['rate' => 29])])];
        yield 'thresholds overlap' => [array_replace($valid, ['thresholds' => ['skip_below' => 70, 'apply_at' => 70]])];
        yield 'hourly floor has more than two decimals' => [array_replace($valid, ['minimum_hourly_usd' => '20.001'])];
        yield 'rating exceeds five' => [array_replace($valid, ['minimum_client_rating' => '5.01'])];
    }

    #[Test]
    #[DataProvider('invalidProfiles')]
    public function it_rejects_invalid_profile_definitions(array $definition): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScoringProfile::fromArray($definition);
    }

    /** @return array<string, mixed> */
    private static function validDefinition(): array
    {
        return [
            'schema_version' => 1,
            'label' => 'Synthetic demo profile',
            'purpose' => 'demo',
            'minimum_hourly_usd' => '20.00',
            'preferred_skills' => ['django'],
            'minimum_client_rating' => '4.50',
            'weights' => [
                'skill_match' => 40,
                'rate' => 30,
                'payment_verified' => 20,
                'client_rating' => 10,
            ],
            'thresholds' => ['skip_below' => 35, 'apply_at' => 70],
        ];
    }
}
