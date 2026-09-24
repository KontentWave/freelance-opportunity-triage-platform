<?php

namespace App\Application\Operations;

use App\Application\Review\ListReviewOpportunities;
use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Domain\Triage\Data\ScoringProfile;
use App\Models\EmailImport;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use Carbon\CarbonImmutable;

final class BuildOperationalSummary
{
    public function __construct(private readonly ListReviewOpportunities $listReviewOpportunities) {}

    /** @return array<string, mixed> */
    public function execute(string $workspaceId, ScoringProfile $profile, CarbonImmutable $generatedAt): array
    {
        $generatedAt = $generatedAt->utc();
        $windowStart = $generatedAt->subHours(24);

        $completed = MailboxRun::query()->where('workspace_id', $workspaceId)
            ->whereIn('status', [MailboxRunStatus::Succeeded, MailboxRunStatus::Partial, MailboxRunStatus::Failed])
            ->whereBetween('finished_at', [$windowStart, $generatedAt])
            ->toBase()
            ->selectRaw('COUNT(*) AS completed_runs, COALESCE(SUM(processed_count), 0) AS processed_count, COALESCE(SUM(duplicate_count), 0) AS duplicate_count, COALESCE(SUM(quarantined_count), 0) AS quarantined_count')
            ->first();

        $lastSuccess = MailboxRun::query()->where('workspace_id', $workspaceId)
            ->where('status', MailboxRunStatus::Succeeded)
            ->where('finished_at', '<=', $generatedAt)
            ->max('finished_at');

        $unfinished = MailboxMessage::query()->where('workspace_id', $workspaceId)
            ->whereIn('status', [MailboxMessageStatus::Pending, MailboxMessageStatus::RetryWait])
            ->where('first_seen_at', '<=', $generatedAt)
            ->toBase()->selectRaw('COUNT(*) AS unfinished_count, MIN(first_seen_at) AS oldest_first_seen_at')->first();

        $knownCodes = array_map(static fn (EmailParseErrorCode $code): string => $code->value, EmailParseErrorCode::cases());
        $placeholders = implode(', ', array_fill(0, count($knownCodes), '?'));
        $imports = EmailImport::query()->where('workspace_id', $workspaceId)
            ->where('status', EmailImportStatus::Quarantined)
            ->whereBetween('imported_at', [$windowStart, $generatedAt])
            ->toBase()
            ->selectRaw("CASE WHEN error_code IN ($placeholders) THEN error_code ELSE 'unknown_error' END AS safe_code, COUNT(*) AS total", $knownCodes)
            ->groupBy('safe_code')->get();

        $errorCounts = [];
        $quarantinedImports = 0;
        foreach ($imports as $import) {
            $count = (int) $import->total;
            $quarantinedImports += $count;
            $code = (string) $import->safe_code;
            $errorCounts[$code] = ($errorCounts[$code] ?? 0) + $count;
        }
        ksort($errorCounts);

        $recommendation = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.recommendation')), pointer_evaluations.recommendation, newest_evaluations.recommendation)";
        $triage = $this->listReviewOpportunities->query($workspaceId, $profile)
            ->toBase()->select('opportunities.workspace_id')
            ->selectRaw("SUM(CASE WHEN $recommendation = 'APPLY' THEN 1 ELSE 0 END) AS apply_count")
            ->selectRaw("SUM(CASE WHEN $recommendation = 'MAYBE' THEN 1 ELSE 0 END) AS maybe_count")
            ->selectRaw("SUM(CASE WHEN $recommendation = 'SKIP' THEN 1 ELSE 0 END) AS skip_count")
            ->selectRaw('SUM(CASE WHEN COALESCE(pointer_evaluations.id, newest_evaluations.id) IS NULL THEN 1 ELSE 0 END) AS unscored_count')
            ->groupBy('opportunities.workspace_id')
            ->first();

        return [
            'schema_version' => 1,
            'generated_at' => $generatedAt->toAtomString(),
            'window_hours' => 24,
            'mailbox' => [
                'completed_runs' => (int) $completed->completed_runs,
                'processed_count' => (int) $completed->processed_count,
                'duplicate_count' => (int) $completed->duplicate_count,
                'quarantined_count' => (int) $completed->quarantined_count,
                'last_successful_poll_age_seconds' => $lastSuccess === null ? null : max(0, (int) CarbonImmutable::parse($lastSuccess, 'UTC')->diffInSeconds($generatedAt)),
                'unfinished_count' => (int) $unfinished->unfinished_count,
                'oldest_unfinished_age_seconds' => $unfinished->oldest_first_seen_at === null ? null : max(0, (int) CarbonImmutable::parse($unfinished->oldest_first_seen_at, 'UTC')->diffInSeconds($generatedAt)),
            ],
            'parser' => ['quarantined_imports' => $quarantinedImports, 'error_counts' => (object) $errorCounts],
            'triage' => [
                'APPLY' => (int) ($triage->apply_count ?? 0),
                'MAYBE' => (int) ($triage->maybe_count ?? 0),
                'SKIP' => (int) ($triage->skip_count ?? 0),
                'UNSCORED' => (int) ($triage->unscored_count ?? 0),
            ],
        ];
    }
}
