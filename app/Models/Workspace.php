<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function emailImports(): HasMany
    {
        return $this->hasMany(EmailImport::class);
    }

    public function mailboxCheckpoints(): HasMany
    {
        return $this->hasMany(MailboxCheckpoint::class);
    }

    public function mailboxMessages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    public function mailboxRuns(): HasMany
    {
        return $this->hasMany(MailboxRun::class);
    }

    public function opportunityEvaluations(): HasMany
    {
        return $this->hasMany(OpportunityEvaluation::class);
    }

    public function opportunityReviews(): HasMany
    {
        return $this->hasMany(OpportunityReview::class);
    }
}
