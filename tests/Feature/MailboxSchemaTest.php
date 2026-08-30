<?php

namespace Tests\Feature;

use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Models\MailboxCheckpoint;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MailboxSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_workspace_mailbox_uid_uniqueness(): void
    {
        $workspace = Workspace::factory()->create();
        $this->createMailboxMessage($workspace, uidValidity: 9001, messageUid: 101);

        $this->expectException(QueryException::class);

        $this->createMailboxMessage($workspace, uidValidity: 9001, messageUid: 101);
    }

    #[Test]
    public function it_enforces_workspace_mailbox_checkpoint_uniqueness(): void
    {
        $workspace = Workspace::factory()->create();
        $values = [
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'last_discovered_uid' => 101,
        ];
        MailboxCheckpoint::query()->create($values);

        $this->expectException(QueryException::class);

        MailboxCheckpoint::query()->create($values);
    }

    #[Test]
    public function it_allows_the_same_uid_namespace_in_different_workspaces(): void
    {
        $firstWorkspace = Workspace::factory()->create();
        $secondWorkspace = Workspace::factory()->create();

        $firstMessage = $this->createMailboxMessage($firstWorkspace, uidValidity: 9001, messageUid: 101);
        $secondMessage = $this->createMailboxMessage($secondWorkspace, uidValidity: 9001, messageUid: 101);

        $this->assertNotSame($firstMessage->id, $secondMessage->id);
        $this->assertSame(MailboxMessageStatus::Pending, $firstMessage->status);
        $this->assertTrue($firstMessage->workspace->is($firstWorkspace));
        $this->assertDatabaseCount('mailbox_messages', 2);
    }

    #[Test]
    public function it_cascades_workspace_deletion_without_cross_workspace_effects(): void
    {
        $firstWorkspace = Workspace::factory()->create();
        $secondWorkspace = Workspace::factory()->create();

        foreach ([$firstWorkspace, $secondWorkspace] as $workspace) {
            MailboxCheckpoint::query()->create([
                'workspace_id' => $workspace->id,
                'mailbox_key' => 'primary',
                'uid_validity' => 9001,
                'last_discovered_uid' => 101,
            ]);
            $this->createMailboxMessage($workspace, uidValidity: 9001, messageUid: 101);
            MailboxRun::query()->create([
                'workspace_id' => $workspace->id,
                'mailbox_key' => 'primary',
                'status' => MailboxRunStatus::Succeeded,
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
            ]);
        }

        $this->assertSame(1, $firstWorkspace->mailboxCheckpoints()->count());
        $this->assertSame(1, $firstWorkspace->mailboxMessages()->count());
        $this->assertSame(1, $firstWorkspace->mailboxRuns()->count());

        $firstWorkspace->delete();

        $this->assertDatabaseMissing('mailbox_checkpoints', ['workspace_id' => $firstWorkspace->id]);
        $this->assertDatabaseMissing('mailbox_messages', ['workspace_id' => $firstWorkspace->id]);
        $this->assertDatabaseMissing('mailbox_runs', ['workspace_id' => $firstWorkspace->id]);

        $this->assertDatabaseHas('mailbox_checkpoints', ['workspace_id' => $secondWorkspace->id]);
        $this->assertDatabaseHas('mailbox_messages', ['workspace_id' => $secondWorkspace->id]);
        $this->assertDatabaseHas('mailbox_runs', ['workspace_id' => $secondWorkspace->id]);
    }

    #[Test]
    public function it_nulls_the_opportunity_reference_without_deleting_delivery_history(): void
    {
        $workspace = Workspace::factory()->create();
        $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $message = $this->createMailboxMessage(
            $workspace,
            uidValidity: 9001,
            messageUid: 101,
            opportunity: $opportunity,
        );

        $opportunity->delete();

        $this->assertDatabaseHas('mailbox_messages', [
            'id' => $message->id,
            'workspace_id' => $workspace->id,
            'opportunity_id' => null,
        ]);
        $this->assertNull($message->refresh()->opportunity);
    }

    #[Test]
    public function it_stores_only_safe_delivery_metadata(): void
    {
        $forbiddenColumns = [
            'raw_email',
            'body',
            'headers',
            'sender',
            'recipient',
            'subject',
            'hostname',
            'username',
            'password',
            'credentials',
            'exception_message',
        ];

        foreach (['mailbox_checkpoints', 'mailbox_messages', 'mailbox_runs'] as $table) {
            $columns = Schema::getColumnListing($table);

            foreach ($forbiddenColumns as $column) {
                $this->assertNotContains($column, $columns, sprintf('%s must not contain %s.', $table, $column));
            }
        }
    }

    private function createMailboxMessage(
        Workspace $workspace,
        int $uidValidity,
        int $messageUid,
        ?Opportunity $opportunity = null,
    ): MailboxMessage {
        return MailboxMessage::query()->create([
            'workspace_id' => $workspace->id,
            'opportunity_id' => $opportunity?->id,
            'mailbox_key' => 'primary',
            'uid_validity' => $uidValidity,
            'message_uid' => $messageUid,
            'status' => MailboxMessageStatus::Pending,
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'error_code' => null,
            'first_seen_at' => now(),
            'processed_at' => null,
        ]);
    }
}
