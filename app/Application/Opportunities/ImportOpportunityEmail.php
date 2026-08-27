<?php

namespace App\Application\Opportunities;

use App\Application\Opportunities\Data\ImportResult;
use App\Domain\Opportunities\Contracts\OpportunityEmailParser;
use App\Domain\Opportunities\Data\ParsedOpportunity;
use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Domain\Opportunities\Exceptions\EmailParseException;
use App\Models\EmailImport;
use App\Models\Opportunity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ImportOpportunityEmail
{
    public function __construct(
        private readonly OpportunityEmailParser $parser,
    ) {}

    public function execute(string $workspaceId, string $rawEmail): ImportResult
    {
        $contentHash = hash('sha256', $rawEmail);
        $safeMessageId = $this->extractSafeMessageId($rawEmail);
        $existingImport = $this->findExistingImport($workspaceId, $contentHash, $safeMessageId);

        if ($existingImport !== null) {
            return new ImportResult(
                status: EmailImportStatus::Duplicate,
                opportunityId: $existingImport->opportunity_id,
                externalJobId: $existingImport->opportunity?->external_id,
                errorCode: null,
            );
        }

        try {
            $parsedOpportunity = $this->parser->parse($rawEmail);
        } catch (EmailParseException $exception) {
            EmailImport::query()->create([
                'workspace_id' => $workspaceId,
                'opportunity_id' => null,
                'message_id' => $safeMessageId,
                'content_sha256' => $contentHash,
                'status' => EmailImportStatus::Quarantined->value,
                'error_code' => $exception->errorCode->value,
                'imported_at' => now(),
            ]);

            return new ImportResult(
                status: EmailImportStatus::Quarantined,
                opportunityId: null,
                externalJobId: null,
                errorCode: $exception->errorCode,
            );
        }

        try {
            return DB::transaction(function () use ($workspaceId, $contentHash, $parsedOpportunity): ImportResult {
                $opportunity = Opportunity::query()->firstOrNew([
                    'workspace_id' => $workspaceId,
                    'provider' => $parsedOpportunity->provider->value,
                    'external_id' => $parsedOpportunity->externalJobId,
                ]);

                $status = $opportunity->exists ? EmailImportStatus::Updated : EmailImportStatus::Imported;

                $this->fillOpportunity($opportunity, $parsedOpportunity);
                $opportunity->save();

                $opportunity->skills()->delete();

                foreach ($parsedOpportunity->skills as $position => $skillName) {
                    $opportunity->skills()->create([
                        'name' => $skillName,
                        'position' => $position,
                    ]);
                }

                EmailImport::query()->create([
                    'workspace_id' => $workspaceId,
                    'opportunity_id' => $opportunity->id,
                    'message_id' => $parsedOpportunity->sourceMessageId,
                    'content_sha256' => $contentHash,
                    'status' => $status->value,
                    'error_code' => null,
                    'imported_at' => now(),
                ]);

                return new ImportResult(
                    status: $status,
                    opportunityId: $opportunity->id,
                    externalJobId: $opportunity->external_id,
                    errorCode: null,
                );
            });
        } catch (QueryException $exception) {
            $duplicateImport = $this->findExistingImport($workspaceId, $contentHash, $parsedOpportunity->sourceMessageId);

            if ($duplicateImport !== null) {
                return new ImportResult(
                    status: EmailImportStatus::Duplicate,
                    opportunityId: $duplicateImport->opportunity_id,
                    externalJobId: $duplicateImport->opportunity?->external_id,
                    errorCode: null,
                );
            }

            throw $exception;
        }
    }

    private function fillOpportunity(Opportunity $opportunity, ParsedOpportunity $parsedOpportunity): void
    {
        $opportunity->fill([
            'canonical_url' => $parsedOpportunity->canonicalUrl,
            'title' => $parsedOpportunity->title,
            'contract_type' => $parsedOpportunity->contractType->value,
            'hourly_min' => $parsedOpportunity->hourlyMin,
            'hourly_max' => $parsedOpportunity->hourlyMax,
            'currency' => $parsedOpportunity->currency,
            'estimated_duration' => $parsedOpportunity->estimatedDuration,
            'posted_on' => $parsedOpportunity->postedOn?->toDateString(),
            'excerpt' => $parsedOpportunity->excerpt,
            'hidden_skill_count' => $parsedOpportunity->hiddenSkillCount,
            'payment_verified' => $parsedOpportunity->paymentVerified,
            'client_rating' => $parsedOpportunity->clientRating,
            'client_spend_usd' => $parsedOpportunity->clientSpendUsd,
            'client_spend_approximate' => $parsedOpportunity->clientSpendApproximate,
            'client_country' => $parsedOpportunity->clientCountry,
            'source_template' => $parsedOpportunity->templateFingerprint,
        ]);
    }

    private function findExistingImport(string $workspaceId, string $contentHash, ?string $messageId): ?EmailImport
    {
        return EmailImport::query()
            ->with('opportunity')
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($contentHash, $messageId): void {
                if ($messageId !== null) {
                    $query->where('message_id', $messageId)->orWhere('content_sha256', $contentHash);

                    return;
                }

                $query->where('content_sha256', $contentHash);
            })
            ->first();
    }

    private function extractSafeMessageId(string $rawEmail): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $rawEmail, $matches) !== 1) {
            return null;
        }

        $messageId = trim($matches[1]);
        $messageId = trim($messageId, "<> \t\n\r\0\x0B");

        if ($messageId === '') {
            return null;
        }

        return substr($messageId, 0, 255);
    }
}
