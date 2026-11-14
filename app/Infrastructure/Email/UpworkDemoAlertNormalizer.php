<?php

namespace App\Infrastructure\Email;

use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\Triage\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

final class UpworkDemoAlertNormalizer
{
    public const MAX_RAW_BYTES = 1_048_576;

    public function __construct(
        private readonly MailMimeParser $mimeParser = new MailMimeParser,
    ) {}

    /**
     * @return array{
     *     source_namespace: string,
     *     source_id: string,
     *     body_sha256: string,
     *     input_sha256: string,
     *     raw_sha256: string,
     *     message_id: string,
     *     input: array<string, mixed>
     * }
     */
    public function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_RAW_BYTES) {
            throw new DemoAiException('demo_ai.message_size');
        }

        try {
            $message = $this->mimeParser->parse($raw, false);
        } catch (Throwable) {
            throw new DemoAiException('demo_ai.mime_invalid');
        }

        $messageId = trim((string) $message->getHeaderValue(HeaderConsts::MESSAGE_ID));
        $messageId = trim($messageId, "<> \t\n\r\0\x0B");
        if ($messageId === '') {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }

        $this->assertSupportedSender($message);
        $subject = trim((string) $message->getSubject());
        $subjectPrefix = (string) config('opportunity_sources.upwork.subject_prefix', 'New job alert:');
        if ($subject === '' || ! str_starts_with($subject, $subjectPrefix)) {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }

        if ($message->getTextPartCount() < 1) {
            throw new DemoAiException('demo_ai.mime_invalid');
        }
        $plainText = trim((string) $message->getTextContent());
        if ($plainText === '') {
            throw new DemoAiException('demo_ai.mime_invalid');
        }

        [$externalJobId, $matchedUrl] = $this->extractJobIdentity($plainText);
        $sourceId = 'UPW-~'.$externalJobId;
        $title = $this->extractTitle($plainText, $matchedUrl, $subject, $subjectPrefix);
        [$contractType, $hourlyMin, $hourlyMax, $fixedBudget, $estimatedDuration] = $this->extractTerms($plainText);
        $postedAt = $this->extractPostedAt($plainText, $message);
        $description = $this->extractExcerpt($plainText, $title);
        $skills = $this->extractSkills($plainText);
        [$paymentVerified, $clientRating, $clientSpendUsd, $clientCountry] = $this->extractClientSummary($plainText);

        $input = [
            'source_id' => $sourceId,
            'title' => $title,
            'description' => $description,
            'skills' => $skills,
            'contract_type' => $contractType,
            'hourly_min_usd' => $hourlyMin,
            'hourly_max_usd' => $hourlyMax,
            'fixed_budget_usd' => $fixedBudget,
            'estimated_duration' => $estimatedDuration,
            'hours_per_week_max' => null,
            'required_devices' => null,
            'eligible_locations' => null,
            'posted_at' => $postedAt,
            'proposals_count' => null,
            'client_payment_verified' => $paymentVerified,
            'client_rating' => $clientRating,
            'client_spend_usd' => $clientSpendUsd,
            'client_country' => $clientCountry,
        ];

        $encoded = CanonicalJson::encode($input);
        if (strlen($encoded) > 12_288) {
            throw new DemoAiException('demo_ai.payload_size');
        }

        $inputHash = hash('sha256', $encoded);

        return [
            'source_namespace' => ApprovedDemoInputs::UPWORK_SOURCE_NAMESPACE,
            'source_id' => $sourceId,
            'body_sha256' => $inputHash,
            'input_sha256' => $inputHash,
            'raw_sha256' => hash('sha256', $raw),
            'message_id' => substr($messageId, 0, 255),
            'input' => $input,
        ];
    }

    private function assertSupportedSender(IMessage $message): void
    {
        $fromHeader = $message->getHeader(HeaderConsts::FROM);
        if (! $fromHeader instanceof AddressHeader) {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }

        $addresses = $fromHeader->getAddresses();
        if ($addresses === []) {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }

        $fromAddress = strtolower((string) $addresses[0]->getEmail());
        $allowed = array_map(
            static fn (mixed $address): string => strtolower((string) $address),
            (array) config('opportunity_sources.upwork.from_addresses', ['donotreply@upwork.com', 'upwork@t.upwork.com']),
        );

        if (! in_array($fromAddress, $allowed, true)) {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }
    }

    /** @return array{0: string, 1: string} */
    private function extractJobIdentity(string $plainText): array
    {
        if (! preg_match_all('/https?:\/\/\S+/i', $plainText, $matches)) {
            throw new DemoAiException('demo_ai.missing_canonical_job_url');
        }

        $allowedHost = strtolower((string) config('opportunity_sources.upwork.allowed_host', 'www.upwork.com'));

        foreach ($matches[0] as $candidateUrl) {
            $cleaned = rtrim($candidateUrl, ").,;:>\"'");
            $parts = parse_url($cleaned);
            if (! is_array($parts)) {
                continue;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if ($scheme !== 'https' || $host !== $allowedHost) {
                continue;
            }
            if (preg_match('#^/jobs/~(\d{1,26})$#', $path, $idMatches) === 1) {
                return [$idMatches[1], $cleaned];
            }
        }

        throw new DemoAiException('demo_ai.missing_canonical_job_url');
    }

    private function extractTitle(string $plainText, string $matchedUrl, string $subject, string $subjectPrefix): string
    {
        $lines = preg_split('/\R/', $plainText) ?: [];

        foreach ($lines as $line) {
            $position = strpos($line, $matchedUrl);
            if ($position === false) {
                continue;
            }
            $prefix = $this->cleanText(rtrim(substr($line, 0, $position), ": \t"));
            if ($prefix !== '' && strcasecmp($prefix, 'View job details') !== 0 && strcasecmp($prefix, 'View job') !== 0) {
                return mb_substr($prefix, 0, 160, 'UTF-8');
            }
        }

        $fromSubject = $this->cleanText(substr($subject, strlen($subjectPrefix)));
        if ($fromSubject !== '') {
            return mb_substr($fromSubject, 0, 160, 'UTF-8');
        }

        throw new DemoAiException('demo_ai.body_invalid');
    }

    /** @return array{0: string, 1: float|null, 2: float|null, 3: float|null, 4: string|null} */
    private function extractTerms(string $plainText): array
    {
        $estimatedDuration = null;
        if (preg_match('/(?:^|[·•])\h*Est\.\s*time:\s*([^·•\r\n]+)/mi', $plainText, $durationMatches) === 1) {
            $duration = $this->cleanText($durationMatches[1]);
            $estimatedDuration = $duration !== '' ? mb_substr($duration, 0, 120, 'UTF-8') : null;
        }

        if (preg_match('/^\h*Hourly:\h*(?:\R\h*)?\$([\d,]+(?:\.\d{1,2})?)\h*-\h*\$([\d,]+(?:\.\d{1,2})?)/mi', $plainText, $hourlyMatches) === 1) {
            $min = $this->parseMoney($hourlyMatches[1]);
            $max = $this->parseMoney($hourlyMatches[2]);
            if ($min === 0.0 && $max === 0.0) {
                $min = null;
                $max = null;
            } elseif ($min !== null && $max !== null && $max < $min) {
                [$min, $max] = [$max, $min];
            }

            return ['hourly', $min, $max, null, $estimatedDuration];
        }

        if (preg_match('/^\h*Hourly(?::|\h*[·•]|\h*$)/mi', $plainText) === 1) {
            return ['hourly', null, null, null, $estimatedDuration];
        }

        if (preg_match('/^\h*(?:Fixed-price|Fixed):\h*(?:\R\h*)?\$([\d,]+(?:\.\d{1,2})?)/mi', $plainText, $fixedMatches) === 1
            || preg_match('/Est\.\s*budget:\h*\$([\d,]+(?:\.\d{1,2})?)/mi', $plainText, $fixedMatches) === 1) {
            $budget = $this->parseMoney($fixedMatches[1]);
            if ($budget === 0.0) {
                $budget = null;
            }

            return ['fixed', null, null, $budget, $estimatedDuration];
        }

        if (preg_match('/^\h*(?:Fixed-price|Fixed)(?::|\h*[·•]|\h*$)/mi', $plainText) === 1) {
            return ['fixed', null, null, null, $estimatedDuration];
        }

        throw new DemoAiException('demo_ai.body_invalid');
    }

    private function extractPostedAt(string $plainText, IMessage $message): ?string
    {
        $messageDate = null;
        $rawDate = trim((string) $message->getHeaderValue(HeaderConsts::DATE));
        if ($rawDate !== '') {
            try {
                $messageDate = CarbonImmutable::parse($rawDate)->utc();
            } catch (Throwable) {
                $messageDate = null;
            }
        }

        if (preg_match('/Posted(?:\s+on)?:?\s+(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+)?([A-Za-z]{3,9})\s+(\d{1,2})\b/mi', $plainText, $matches) === 1
            && $messageDate !== null) {
            $month = $matches[1];
            $day = (int) $matches[2];
            $candidate = CarbonImmutable::createFromFormat('!M j Y', $month.' '.$day.' '.$messageDate->year, 'UTC')
                ?? CarbonImmutable::createFromFormat('!F j Y', $month.' '.$day.' '.$messageDate->year, 'UTC');

            if ($candidate !== null) {
                if ($candidate->greaterThan($messageDate->startOfDay()->addDay())) {
                    $candidate = $candidate->subYear();
                }
                if (abs($messageDate->startOfDay()->diffInDays($candidate, false)) <= 1) {
                    return $messageDate->format('Y-m-d\TH:i:s\Z');
                }

                return $candidate->format('Y-m-d\T00:00:00\Z');
            }
        }

        return $messageDate?->format('Y-m-d\TH:i:s\Z');
    }

    private function extractExcerpt(string $plainText, string $fallbackTitle): string
    {
        $lines = preg_split('/\R/', $plainText) ?: [];
        $termsIndex = null;
        $excerptLines = [];

        foreach ($lines as $index => $line) {
            if ($termsIndex === null && (
                preg_match('/^\h*(?:Hourly|Fixed-price|Fixed)(?::|\h*[·•]|\h*$)/i', $line) === 1
                || preg_match('/(?:^|[·•])\h*Est\.\s*time:/i', $line) === 1
            )) {
                $termsIndex = $index;

                continue;
            }

            if ($termsIndex === null || $index <= $termsIndex) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($excerptLines !== []) {
                    break;
                }

                continue;
            }

            if (preg_match('/^(Skills:|Payment verified|Payment unverified|Rating:|Spent:|Country:|View job|[-=_*]{3,})/i', $trimmed) === 1) {
                break;
            }

            $withoutMoreUrl = (string) preg_replace('/\h+more:\h+https?:\/\/\S+$/i', '', $trimmed);
            $withoutUrls = (string) preg_replace('/https?:\/\/\S+/i', '', $withoutMoreUrl);
            $cleaned = $this->cleanText($withoutUrls);
            if ($cleaned !== '') {
                $excerptLines[] = $cleaned;
            }
        }

        $joined = $this->cleanText(implode(' ', $excerptLines));
        $final = $joined !== '' ? $joined : $fallbackTitle;

        return mb_substr($final, 0, 2400, 'UTF-8');
    }

    /** @return list<string> */
    private function extractSkills(string $plainText): array
    {
        $lines = preg_split('/\R/', $plainText) ?: [];
        $skills = [];
        $seen = [];
        $inside = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (! $inside) {
                if (strcasecmp($trimmed, 'Skills:') === 0) {
                    $inside = true;
                }

                continue;
            }
            if ($trimmed === '' || count($skills) >= 12) {
                break;
            }
            if (preg_match('/^\+\d+\s+more$/i', $trimmed) === 1) {
                continue;
            }
            $name = $this->cleanText((string) preg_replace('/:\h+https?:\/\/\S+$/i', '', $trimmed));
            if ($name === '') {
                continue;
            }
            $clamped = mb_substr($name, 0, 40, 'UTF-8');
            $key = mb_strtolower($clamped, 'UTF-8');
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $skills[] = $clamped;
            }
        }

        return $skills;
    }

    /** @return array{0: bool|null, 1: float|null, 2: float|null, 3: string|null} */
    private function extractClientSummary(string $plainText): array
    {
        $paymentVerified = preg_match('/^Payment verified\b/mi', $plainText) === 1 ? true : null;
        if ($paymentVerified === null && preg_match('/^Payment unverified\b/mi', $plainText) === 1) {
            $paymentVerified = false;
        }
        $rating = null;
        $spendUsd = null;
        $country = null;

        if (preg_match(
            '/^Payment verified\h*[·•]\h*(\d(?:\.\d{1,2})?)\h+stars\h*[·•]\h*\$([\d,]+(?:\.\d+)?)\h*([KMB])?\h+spent\h*[·•]\h*(.+)$/miu',
            $plainText,
            $matches,
        ) === 1) {
            $paymentVerified = true;
            $parsedRating = round((float) $matches[1], 2);
            $rating = ($parsedRating >= 0.0 && $parsedRating <= 5.0) ? $parsedRating : null;
            $spendUsd = $this->normalizeMultipliedUsd($matches[2], strtoupper($matches[3]));
            $cleanedCountry = $this->cleanText($matches[4]);
            $country = $cleanedCountry !== '' ? mb_substr($cleanedCountry, 0, 80, 'UTF-8') : null;
        }

        if (preg_match('/^Rating:\s*(\d(?:\.\d{1,2})?)\s+of\s+5$/mi', $plainText, $matches) === 1) {
            $parsedRating = round((float) $matches[1], 2);
            $rating = ($parsedRating >= 0.0 && $parsedRating <= 5.0) ? $parsedRating : null;
        }
        if (preg_match('/^Spent:\s*\$([\d,]+(?:\.\d+)?)\s*([KMB])?$/mi', $plainText, $matches) === 1) {
            $spendUsd = $this->normalizeMultipliedUsd($matches[1], strtoupper((string) ($matches[2] ?? '')));
        }
        if (preg_match('/^Country:\s*(.+)$/mi', $plainText, $matches) === 1) {
            $cleanedCountry = $this->cleanText($matches[1]);
            $country = $cleanedCountry !== '' ? mb_substr($cleanedCountry, 0, 80, 'UTF-8') : null;
        }

        return [$paymentVerified, $rating, $spendUsd, $country];
    }

    private function parseMoney(string $raw): ?float
    {
        $normalized = str_replace(',', '', trim($raw));
        if (! is_numeric($normalized)) {
            return null;
        }
        $value = round((float) $normalized, 2);

        return is_finite($value) && $value >= 0.0 ? $value : null;
    }

    private function normalizeMultipliedUsd(string $amount, string $suffix): ?float
    {
        $base = $this->parseMoney($amount);
        if ($base === null) {
            return null;
        }
        $multiplier = match ($suffix) {
            'K' => 1_000.0,
            'M' => 1_000_000.0,
            'B' => 1_000_000_000.0,
            default => 1.0,
        };

        return round($base * $multiplier, 2);
    }

    private function cleanText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }
}
