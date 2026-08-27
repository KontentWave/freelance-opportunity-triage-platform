<?php

namespace Tests\Feature;

use App\Application\Opportunities\ImportOpportunityEmail;
use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Models\EmailImport;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImportOpportunityEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_an_opportunity_and_ordered_skills_for_a_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $result = app(ImportOpportunityEmail::class)->execute($workspace->id, $this->fixture('hourly-client-success.eml'));

        $this->assertSame(EmailImportStatus::Imported, $result->status);
        $this->assertNotNull($result->opportunityId);

        $opportunity = Opportunity::query()->whereKey($result->opportunityId)->firstOrFail();

        $this->assertSame($workspace->id, $opportunity->workspace_id);
        $this->assertSame('upwork_email', $opportunity->provider);
        $this->assertSame('200000000000000000001', $opportunity->external_id);
        $this->assertSame('https://www.upwork.com/jobs/~200000000000000000001', $opportunity->canonical_url);
        $this->assertSame('Client Success & Project Manager', $opportunity->title);
        $this->assertSame('hourly', $opportunity->contract_type);
        $this->assertSame('40.00', $opportunity->hourly_min);
        $this->assertSame('60.00', $opportunity->hourly_max);
        $this->assertSame('USD', $opportunity->currency);
        $this->assertSame('More than 6 months', $opportunity->estimated_duration);
        $this->assertSame('Lead client onboarding & retention across multiple delivery tracks...', $opportunity->excerpt);
        $this->assertSame(2, $opportunity->hidden_skill_count);
        $this->assertTrue($opportunity->payment_verified);
        $this->assertSame('4.75', $opportunity->client_rating);
        $this->assertSame('79000.00', $opportunity->client_spend_usd);
        $this->assertTrue($opportunity->client_spend_approximate);
        $this->assertSame('United States', $opportunity->client_country);

        $this->assertSame(
            ['Project Management', 'Quality Assurance', 'Communication'],
            $opportunity->skills()->orderBy('position')->pluck('name')->all(),
        );
    }

    #[Test]
    public function it_does_not_duplicate_the_same_message_or_content_hash(): void
    {
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-operations-coordinator.eml');
        $action = app(ImportOpportunityEmail::class);

        $firstResult = $action->execute($workspace->id, $rawEmail);
        $secondResult = $action->execute($workspace->id, $rawEmail);

        $this->assertSame(EmailImportStatus::Imported, $firstResult->status);
        $this->assertSame(EmailImportStatus::Duplicate, $secondResult->status);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(1, EmailImport::query()->count());
    }

    #[Test]
    public function it_updates_the_same_job_received_under_a_new_message_id(): void
    {
        $workspace = Workspace::factory()->create();
        $action = app(ImportOpportunityEmail::class);

        $firstResult = $action->execute($workspace->id, $this->fixture('hourly-client-success.eml'));
        $updatedRawEmail = $this->replaceInFixture(
            $this->replaceInFixture(
                $this->replaceInFixture(
                    $this->fixture('hourly-client-success.eml'),
                    '<fixture-hourly-client-success-1@example.test>',
                    '<fixture-hourly-client-success-2@example.test>',
                ),
                'Client   Success   &amp;   Project Manager',
                'Client Success Lead',
            ),
            "Skills:\nProject Management\nQuality Assurance\nCommunication\nproject management\n+2 more",
            "Skills:\nClient Success\nSaaS Operations\n+1 more",
        );

        $secondResult = $action->execute($workspace->id, $updatedRawEmail);
        $opportunity = Opportunity::query()->whereKey($firstResult->opportunityId)->firstOrFail();

        $this->assertSame(EmailImportStatus::Updated, $secondResult->status);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(2, EmailImport::query()->count());
        $this->assertSame('Client Success Lead', $opportunity->title);
        $this->assertSame(1, $opportunity->hidden_skill_count);
        $this->assertSame(['Client Success', 'SaaS Operations'], $opportunity->skills()->orderBy('position')->pluck('name')->all());
    }

    #[Test]
    public function it_allows_the_same_external_job_id_in_different_workspaces(): void
    {
        $firstWorkspace = Workspace::factory()->create();
        $secondWorkspace = Workspace::factory()->create();
        $action = app(ImportOpportunityEmail::class);

        $firstResult = $action->execute($firstWorkspace->id, $this->fixture('hourly-client-success.eml'));
        $secondResult = $action->execute($secondWorkspace->id, $this->fixture('hourly-client-success.eml'));

        $this->assertSame(EmailImportStatus::Imported, $firstResult->status);
        $this->assertSame(EmailImportStatus::Imported, $secondResult->status);
        $this->assertSame(2, Opportunity::query()->count());
    }

    #[Test]
    public function it_quarantines_invalid_input_without_storing_raw_content(): void
    {
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->replaceInFixture(
            $this->fixture('hourly-client-success.eml'),
            'From: Upwork Notification <donotreply@upwork.com>',
            'From: Evil Sender <evil@example.test>',
        );

        $result = app(ImportOpportunityEmail::class)->execute($workspace->id, $rawEmail);
        $emailImport = EmailImport::query()->firstOrFail();

        $this->assertSame(EmailImportStatus::Quarantined, $result->status);
        $this->assertSame(EmailParseErrorCode::UnsupportedSender, $result->errorCode);
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame('quarantined', $emailImport->status);
        $this->assertSame('unsupported_sender', $emailImport->error_code);

        $serializedImport = json_encode($emailImport->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('owner@example.test', $serializedImport);
        $this->assertStringNotContainsString('tracking-token', $serializedImport);
        $this->assertStringNotContainsString('Lead   client onboarding', $serializedImport);
    }

    #[Test]
    public function it_never_persists_tracking_parameters_or_recipient_addresses(): void
    {
        $workspace = Workspace::factory()->create();
        $result = app(ImportOpportunityEmail::class)->execute($workspace->id, $this->fixture('hourly-client-success.eml'));
        $opportunity = Opportunity::query()->whereKey($result->opportunityId)->firstOrFail();
        $emailImport = EmailImport::query()->firstOrFail();

        $this->assertStringNotContainsString('?', $opportunity->canonical_url);
        $this->assertStringNotContainsString('#', $opportunity->canonical_url);

        $serializedImport = json_encode($emailImport->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('owner@example.test', $serializedImport);
        $this->assertStringNotContainsString('tracking-token', $serializedImport);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }

    private function replaceInFixture(string $rawEmail, string $search, string $replace): string
    {
        return str_replace($search, $replace, $rawEmail);
    }
}
