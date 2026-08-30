<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxCheckpoint extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'mailbox_key',
        'uid_validity',
        'last_discovered_uid',
    ];

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'last_discovered_uid' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
