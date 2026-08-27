<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImportOpportunityEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_a_local_eml_fixture_for_the_selected_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->artisan('opportunity:import-email', [
            'path' => base_path('tests/Fixtures/Emails/upwork/hourly-client-success.eml'),
            '--workspace' => $workspace->id,
        ])
            ->expectsOutputToContain('status: imported')
            ->expectsOutputToContain('external_job_id: 200000000000000000001')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_a_non_zero_exit_code_for_quarantined_input(): void
    {
        $workspace = Workspace::factory()->create();
        $path = $this->writeTempEmail(str_replace(
            'From: Upwork Notification <donotreply@upwork.com>',
            'From: Evil Sender <evil@example.test>',
            $this->fixture('hourly-client-success.eml'),
        ));

        try {
            $this->artisan('opportunity:import-email', [
                'path' => $path,
                '--workspace' => $workspace->id,
            ])
                ->expectsOutputToContain('status: quarantined')
                ->expectsOutputToContain('error_code: unsupported_sender')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_does_not_print_email_bodies_headers_or_tracking_tokens(): void
    {
        $workspace = Workspace::factory()->create();

        $this->artisan('opportunity:import-email', [
            'path' => base_path('tests/Fixtures/Emails/upwork/hourly-client-success.eml'),
            '--workspace' => $workspace->id,
        ])
            ->doesntExpectOutputToContain('From: Upwork Notification')
            ->doesntExpectOutputToContain('owner@example.test')
            ->doesntExpectOutputToContain('tracking-token')
            ->doesntExpectOutputToContain('Lead   client onboarding')
            ->assertExitCode(0);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }

    private function writeTempEmail(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opportunity-email-');

        $this->assertNotFalse($path);

        $bytesWritten = file_put_contents($path, $contents);

        $this->assertNotFalse($bytesWritten);

        return $path;
    }
}
