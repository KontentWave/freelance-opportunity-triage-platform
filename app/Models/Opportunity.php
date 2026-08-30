<?php

namespace App\Models;

use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $provider
 * @property string $external_id
 */
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'provider',
        'external_id',
        'canonical_url',
        'title',
        'contract_type',
        'hourly_min',
        'hourly_max',
        'currency',
        'estimated_duration',
        'posted_on',
        'excerpt',
        'hidden_skill_count',
        'payment_verified',
        'client_rating',
        'client_spend_usd',
        'client_spend_approximate',
        'client_country',
        'source_template',
    ];

    protected function casts(): array
    {
        return [
            'hourly_min' => 'decimal:2',
            'hourly_max' => 'decimal:2',
            'posted_on' => 'immutable_date',
            'payment_verified' => 'boolean',
            'client_rating' => 'decimal:2',
            'client_spend_usd' => 'decimal:2',
            'client_spend_approximate' => 'boolean',
            'hidden_skill_count' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(OpportunitySkill::class);
    }

    public function emailImports(): HasMany
    {
        return $this->hasMany(EmailImport::class);
    }

    public function mailboxMessages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }
}
