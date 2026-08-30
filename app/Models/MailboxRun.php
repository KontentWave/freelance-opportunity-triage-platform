<?php

namespace App\Models;

use App\Domain\Mailbox\Enums\MailboxRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxRun extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'mailbox_key',
        'status',
        'started_at',
        'finished_at',
        'discovered_count',
        'processed_count',
        'imported_count',
        'updated_count',
        'duplicate_count',
        'quarantined_count',
        'retry_scheduled_count',
        'permanent_failure_count',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => MailboxRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'discovered_count' => 'integer',
            'processed_count' => 'integer',
            'imported_count' => 'integer',
            'updated_count' => 'integer',
            'duplicate_count' => 'integer',
            'quarantined_count' => 'integer',
            'retry_scheduled_count' => 'integer',
            'permanent_failure_count' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
