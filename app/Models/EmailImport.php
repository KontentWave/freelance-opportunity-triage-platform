<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workspace_id
 * @property ?string $opportunity_id
 * @property ?string $message_id
 * @property string $content_sha256
 * @property string $status
 * @property ?string $error_code
 * @property-read Opportunity|null $opportunity
 */
class EmailImport extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'opportunity_id',
        'message_id',
        'content_sha256',
        'status',
        'error_code',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
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
