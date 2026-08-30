<?php

namespace App\Models;

use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxMessage extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'opportunity_id',
        'mailbox_key',
        'uid_validity',
        'message_uid',
        'status',
        'attempt_count',
        'next_attempt_at',
        'error_code',
        'first_seen_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'message_uid' => 'integer',
            'status' => MailboxMessageStatus::class,
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
