<?php

namespace Database\Seeders;

use App\Application\Triage\EvaluateOpportunity;
use App\Application\Triage\RecordOpportunityReview;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\EmailImport;
use App\Models\MailboxCheckpoint;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class ReviewDemoSeeder extends Seeder
{
    private const PRIMARY_WORKSPACE_ID = '01J00000000000000000000001';

    private const SECONDARY_WORKSPACE_ID = '01J00000000000000000000002';

    private const PRIMARY_USER_ID = 41001;

    private const SECONDARY_USER_ID = 41002;

    private const USER_EMAILS = ['reviewer@synthetic.invalid', 'isolated@synthetic.invalid'];

    private const WORKSPACE_NAMES = ['Synthetic Review Workspace', 'Isolated Synthetic Workspace'];

    public function run(
        LocalScoringProfileLoader $profileLoader,
        EvaluateOpportunity $evaluateOpportunity,
        RecordOpportunityReview $recordReview,
    ): void {
        $this->assertSafeDatabase();
        $profile = $profileLoader->load(resource_path('triage/profiles/demo-v1.json'));

        DB::transaction(function () use ($profile, $evaluateOpportunity, $recordReview): void {
            $primaryWorkspace = $this->createWorkspace(self::PRIMARY_WORKSPACE_ID, self::WORKSPACE_NAMES[0]);
            $secondaryWorkspace = $this->createWorkspace(self::SECONDARY_WORKSPACE_ID, self::WORKSPACE_NAMES[1]);

            $this->createUser(self::PRIMARY_USER_ID, self::USER_EMAILS[0], $primaryWorkspace);
            $this->createUser(self::SECONDARY_USER_ID, self::USER_EMAILS[1], $secondaryWorkspace);

            for ($index = 1; $index <= 28; $index++) {
                $workspace = $index <= 26 ? $primaryWorkspace : $secondaryWorkspace;
                $attributes = $this->opportunityAttributes($workspace->id, $index);
                $opportunity = Opportunity::query()->firstOrCreate([
                    'workspace_id' => $workspace->id,
                    'provider' => 'synthetic_demo',
                    'external_id' => $attributes['external_id'],
                ], $attributes);

                $this->assertRecordMatches($opportunity, $attributes);

                if ($index === 1) {
                    continue;
                }

                if ($opportunity->skills()->doesntExist() && $index % 4 !== 0) {
                    $opportunity->skills()->create([
                        'name' => $index % 3 === 0 ? 'Documentation' : 'Quality Assurance',
                        'position' => 0,
                    ]);
                }

                $evaluation = $evaluateOpportunity->execute($workspace->id, $opportunity->id, $profile);

                if ($opportunity->review_evaluation_id === null) {
                    $opportunity->forceFill(['review_evaluation_id' => $evaluation->id])->save();
                } elseif ($opportunity->review_evaluation_id !== $evaluation->id) {
                    throw new RuntimeException('Refusing to overwrite an altered synthetic opportunity.');
                }

                if ($index === 2) {
                    $recordReview->execute(
                        $workspace->id,
                        $evaluation->id,
                        $evaluation->recommendation->value,
                        null,
                        'demo',
                    );
                }
            }
        });
    }

    private function assertSafeDatabase(): void
    {
        if (config('opportunity_review.mode') !== 'demo') {
            throw new RuntimeException('ReviewDemoSeeder may run only in demo mode.');
        }

        $database = (string) DB::connection()->getDatabaseName();
        $safeSuffix = collect(config('opportunity_review.demo_database_suffixes', []))
            ->contains(fn (string $suffix): bool => str_ends_with($database, $suffix));

        if (! $safeSuffix) {
            throw new RuntimeException('ReviewDemoSeeder requires a disposable *_demo or *_test database.');
        }

        if (config('opportunity_mailbox.enabled')) {
            throw new RuntimeException('ReviewDemoSeeder requires mailbox intake to be disabled.');
        }

        $hasUnrelatedRecords = Workspace::query()->whereNotIn('name', self::WORKSPACE_NAMES)->exists()
            || User::query()->whereNotIn('email', self::USER_EMAILS)->exists()
            || Opportunity::query()->where('provider', '!=', 'synthetic_demo')->exists()
            || EmailImport::query()->exists()
            || MailboxCheckpoint::query()->exists()
            || MailboxMessage::query()->exists()
            || MailboxRun::query()->exists();

        if ($hasUnrelatedRecords) {
            throw new RuntimeException('Refusing to seed a database containing non-demo application records.');
        }
    }

    private function createWorkspace(string $id, string $name): Workspace
    {
        $workspace = Workspace::query()->find($id);

        if ($workspace === null) {
            if (Workspace::query()->where('name', $name)->exists()) {
                throw new RuntimeException('Refusing to adopt an unrelated workspace.');
            }

            return Workspace::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        if ($workspace->name !== $name) {
            throw new RuntimeException('Refusing to overwrite an unrelated workspace.');
        }

        return $workspace;
    }

    private function createUser(int $id, string $email, Workspace $workspace): void
    {
        $user = User::query()->find($id);

        if ($user === null) {
            User::query()->forceCreate([
                'id' => $id,
                'workspace_id' => $workspace->id,
                'name' => 'Synthetic Reviewer',
                'email' => $email,
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]);

            return;
        }

        if ($user->email !== $email || $user->workspace_id !== $workspace->id) {
            throw new RuntimeException('Refusing to overwrite an unrelated user.');
        }
    }

    /** @return array<string, mixed> */
    private function opportunityAttributes(string $workspaceId, int $index): array
    {
        $variant = $index % 4;

        return [
            'id' => sprintf('01J%023d', $index),
            'workspace_id' => $workspaceId,
            'provider' => 'synthetic_demo',
            'external_id' => sprintf('demo-%03d', $index),
            'canonical_url' => sprintf('https://www.upwork.com/jobs/~%020d', $index),
            'title' => sprintf('Synthetic opportunity %02d', $index),
            'contract_type' => 'hourly',
            'hourly_min' => $variant === 2 ? '8.00' : '25.00',
            'hourly_max' => $variant === 1 ? null : ($variant === 2 ? '12.00' : '40.00'),
            'currency' => 'USD',
            'estimated_duration' => $index % 5 === 0 ? null : 'One to three months',
            'posted_on' => CarbonImmutable::parse('2026-09-18')->subDays($index)->toDateString(),
            'excerpt' => $index === 3
                ? '<script>syntheticBrowserProbe()</script>'
                : 'Fixed fictional scope for browser acceptance.',
            'hidden_skill_count' => $index % 6 === 0 ? 2 : 0,
            'payment_verified' => $variant === 2 ? false : ($index % 7 === 0 ? null : true),
            'client_rating' => $variant === 2 ? '3.50' : ($index % 5 === 0 ? null : '4.90'),
            'client_spend_usd' => null,
            'client_spend_approximate' => false,
            'client_country' => null,
            'source_template' => 'synthetic-demo-v1',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function assertRecordMatches(Opportunity $opportunity, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $current = $opportunity->getAttribute($key);

            if ($current instanceof DateTimeInterface) {
                $current = $current->format('Y-m-d');
            }

            if ((string) $current !== (string) $value) {
                throw new RuntimeException('Refusing to overwrite an altered synthetic opportunity.');
            }
        }
    }
}
