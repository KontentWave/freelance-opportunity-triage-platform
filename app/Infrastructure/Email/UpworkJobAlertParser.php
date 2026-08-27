<?php

namespace App\Infrastructure\Email;

use App\Domain\Opportunities\Contracts\OpportunityEmailParser;
use App\Domain\Opportunities\Data\ParsedOpportunity;
use App\Domain\Opportunities\Enums\ContractType;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Domain\Opportunities\Enums\OpportunityProvider;
use App\Domain\Opportunities\Exceptions\EmailParseException;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

final class UpworkJobAlertParser implements OpportunityEmailParser
{
    public function __construct(
        private readonly MailMimeParser $mimeParser = new MailMimeParser()
    ) {
    }

    public function parse(string $rawEmail): ParsedOpportunity
    {
        $this->guardRawSize($rawEmail);

        try {
            $message = $this->mimeParser->parse($rawEmail, false);
        } catch (Throwable) {
            throw new EmailParseException(EmailParseErrorCode::MimeParseFailed);
        }

        $messageId = $this->parseMessageId($message);
        $this->assertSupportedSender($message);
        $this->assertSupportedSubject($message);

        $plainText = $this->extractPlainTextBody($message);
        $title = $this->extractTitle($plainText);
        $jobUrl = $this->extractJobUrl($plainText);
        [$externalJobId, $canonicalUrl] = $this->normalizeJobUrl($jobUrl);
        [$hourlyMin, $hourlyMax, $estimatedDuration] = $this->extractHourlyTerms($plainText);
        $excerpt = $this->extractExcerpt($plainText);
        [$skills, $hiddenSkillCount] = $this->extractSkills($plainText);

        return new ParsedOpportunity(
            provider: OpportunityProvider::UpworkEmail,
            sourceMessageId: $messageId,
            externalJobId: $externalJobId,
            canonicalUrl: $canonicalUrl,
            title: $title,
            contractType: ContractType::Hourly,
            hourlyMin: $hourlyMin,
            hourlyMax: $hourlyMax,
            currency: (string) config('opportunity_sources.upwork.currency'),
            estimatedDuration: $estimatedDuration,
            postedOn: null,
            excerpt: $excerpt,
            skills: $skills,
            hiddenSkillCount: $hiddenSkillCount,
            paymentVerified: null,
            clientRating: null,
            clientSpendUsd: null,
            clientSpendApproximate: false,
            clientCountry: null,
            templateFingerprint: (string) config('opportunity_sources.upwork.template_fingerprint'),
        );
    }

    private function guardRawSize(string $rawEmail): void
    {
        if (strlen($rawEmail) > (int) config('opportunity_sources.email_max_bytes')) {
            throw new EmailParseException(EmailParseErrorCode::EmailTooLarge);
        }
    }

    private function parseMessageId(IMessage $message): string
    {
        $messageId = trim((string) $message->getHeaderValue(HeaderConsts::MESSAGE_ID));
        $messageId = trim($messageId, "<> \t\n\r\0\x0B");

        if ($messageId === '') {
            throw new EmailParseException(EmailParseErrorCode::MissingMessageId);
        }

        return substr($messageId, 0, 255);
    }

    private function assertSupportedSender(IMessage $message): void
    {
        $fromHeader = $message->getHeader(HeaderConsts::FROM);

        if (! $fromHeader instanceof AddressHeader) {
            throw new EmailParseException(EmailParseErrorCode::UnsupportedSender);
        }

        $addresses = $fromHeader->getAddresses();
        $fromAddress = $addresses[0]->getEmail() ?? null;

        if (strtolower((string) $fromAddress) !== strtolower((string) config('opportunity_sources.upwork.from_address'))) {
            throw new EmailParseException(EmailParseErrorCode::UnsupportedSender);
        }
    }

    private function assertSupportedSubject(IMessage $message): void
    {
        $subject = trim((string) $message->getSubject());
        $subjectPrefix = (string) config('opportunity_sources.upwork.subject_prefix');

        if ($subject === '' || ! str_starts_with($subject, $subjectPrefix)) {
            throw new EmailParseException(EmailParseErrorCode::UnsupportedSubject);
        }
    }

    private function extractPlainTextBody(IMessage $message): string
    {
        if ($message->getTextPartCount() < 1) {
            throw new EmailParseException(EmailParseErrorCode::MissingPlainText);
        }

        $plainText = trim((string) $message->getTextContent());

        if ($plainText === '') {
            throw new EmailParseException(EmailParseErrorCode::MissingPlainText);
        }

        return $plainText;
    }

    private function extractJobUrl(string $plainText): string
    {
        if (! preg_match_all('/https?:\/\/\S+/i', $plainText, $matches)) {
            throw new EmailParseException(EmailParseErrorCode::MissingJobId);
        }

        foreach ($matches[0] as $candidateUrl) {
            $candidateUrl = rtrim($candidateUrl, ").,;:>\"'");

            if (preg_match('#/jobs/~\d+#', $candidateUrl) === 1) {
                return $candidateUrl;
            }
        }

        throw new EmailParseException(EmailParseErrorCode::MissingJobId);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeJobUrl(string $jobUrl): array
    {
        $parts = parse_url($jobUrl);

        if (! is_array($parts)) {
            throw new EmailParseException(EmailParseErrorCode::InvalidJobUrl);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || $host !== strtolower((string) config('opportunity_sources.upwork.allowed_host'))) {
            throw new EmailParseException(EmailParseErrorCode::InvalidJobUrl);
        }

        if (preg_match('#^/jobs/~(\d+)$#', $path, $matches) !== 1) {
            throw new EmailParseException(EmailParseErrorCode::MissingJobId);
        }

        $externalJobId = $matches[1];

        return [
            $externalJobId,
            'https://' . $host . '/jobs/~' . $externalJobId,
        ];
    }

    private function extractTitle(string $plainText): string
    {
        $lines = preg_split('/\R/', $plainText) ?: [];

        foreach ($lines as $line) {
            $candidate = $this->normalizeWhitespace($this->decodeEntities(trim($line)));

            if ($candidate === '' || preg_match('/^https?:\/\//i', $candidate) === 1) {
                continue;
            }

            return substr($candidate, 0, 255);
        }

        throw new EmailParseException(EmailParseErrorCode::MissingTitle);
    }

    private function extractExcerpt(string $plainText): ?string
    {
        $lines = preg_split('/\R/', $plainText) ?: [];
        $termsIndex = null;
        $excerptLines = [];

        foreach ($lines as $index => $line) {
            if ($termsIndex === null && preg_match('/^Est\.\s*time:/i', $line) === 1) {
                $termsIndex = $index;

                continue;
            }

            if ($termsIndex === null || $index <= $termsIndex) {
                continue;
            }

            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                if ($excerptLines !== []) {
                    break;
                }

                continue;
            }

            if (preg_match('/^(Skills:|View job details:)/i', $trimmedLine) === 1) {
                break;
            }

            $excerptLines[] = $trimmedLine;
        }

        if ($excerptLines === []) {
            return null;
        }

        return $this->normalizeWhitespace($this->decodeEntities(implode(' ', $excerptLines)));
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function extractSkills(string $plainText): array
    {
        $lines = preg_split('/\R/', $plainText) ?: [];
        $skills = [];
        $seenSkills = [];
        $hiddenSkillCount = 0;
        $isInsideSkillsBlock = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (! $isInsideSkillsBlock) {
                if (strcasecmp($trimmedLine, 'Skills:') === 0) {
                    $isInsideSkillsBlock = true;
                }

                continue;
            }

            if ($trimmedLine === '') {
                break;
            }

            if (preg_match('/^\+(\d+)\s+more$/i', $trimmedLine, $matches) === 1) {
                $hiddenSkillCount = (int) $matches[1];

                continue;
            }

            $normalizedSkill = $this->normalizeWhitespace($this->decodeEntities($trimmedLine));

            if ($normalizedSkill === '') {
                continue;
            }

            $skillKey = mb_strtolower($normalizedSkill, 'UTF-8');

            if (isset($seenSkills[$skillKey])) {
                continue;
            }

            $seenSkills[$skillKey] = true;
            $skills[] = $normalizedSkill;
        }

        return [$skills, $hiddenSkillCount];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function extractHourlyTerms(string $plainText): array
    {
        if (preg_match('/^Fixed-price:/mi', $plainText) === 1) {
            throw new EmailParseException(EmailParseErrorCode::UnsupportedContractType);
        }

        if (preg_match('/^Hourly:\s*\$(\d+\.\d{2})\s*-\s*\$(\d+\.\d{2})$/mi', $plainText, $hourlyMatches) !== 1) {
            throw new EmailParseException(EmailParseErrorCode::MalformedTerms);
        }

        $hourlyMin = $hourlyMatches[1];
        $hourlyMax = $hourlyMatches[2];

        if ($hourlyMin === '0.00' && $hourlyMax === '0.00') {
            $hourlyMin = null;
            $hourlyMax = null;
        }

        $estimatedDuration = null;

        if (preg_match('/^Est\.\s*time:\s*(.+)$/mi', $plainText, $durationMatches) === 1) {
            $estimatedDuration = substr(
                $this->normalizeWhitespace($this->decodeEntities(trim($durationMatches[1]))),
                0,
                100
            );
        }

        return [$hourlyMin, $hourlyMax, $estimatedDuration];
    }

    private function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
