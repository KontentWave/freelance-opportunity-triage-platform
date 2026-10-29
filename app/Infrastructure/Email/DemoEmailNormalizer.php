<?php

namespace App\Infrastructure\Email;

use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\Triage\Support\CanonicalJson;
use JsonException;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

final class DemoEmailNormalizer
{
    public function __construct(
        private readonly ApprovedDemoInputs $approved,
        private readonly MailMimeParser $mimeParser = new MailMimeParser,
    ) {}

    /** @return array{source_namespace: string, source_id: string, body_sha256: string, input_sha256: string, raw_sha256: string, message_id: string, input: array<string, mixed>} */
    public function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 65_536) {
            throw new DemoAiException('demo_ai.message_size');
        }
        try {
            $message = $this->mimeParser->parse($raw, false);
        } catch (Throwable) {
            throw new DemoAiException('demo_ai.mime_invalid');
        }
        foreach (['From', 'Subject', 'Message-ID', 'Content-Type'] as $header) {
            if (count($message->getAllHeadersByName($header)) !== 1) {
                throw new DemoAiException('demo_ai.envelope_invalid');
            }
        }
        if ($message->getErrors(true) !== []) {
            throw new DemoAiException('demo_ai.mime_invalid');
        }
        $from = $message->getHeader('From');
        $addresses = $from instanceof AddressHeader ? $from->getAddresses() : [];
        if (count($addresses) !== 1 || $addresses[0]->getEmail() !== 'demo-alerts@example.invalid'
            || ! str_starts_with((string) $message->getSubject(), 'FOTP Fictional alert: ')
            || $message->getContentType() !== 'text/plain'
            || strtoupper((string) $message->getCharset()) !== 'UTF-8'
            || $message->getAttachmentCount() !== 0
            || $message->getChildCount() !== 0) {
            throw new DemoAiException('demo_ai.envelope_invalid');
        }

        try {
            $body = json_decode((string) $message->getTextContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DemoAiException('demo_ai.json_invalid');
        }
        $manifest = $this->approved->manifest();
        if (! is_array($body) || array_is_list($body)
            || array_diff(array_keys($body), $manifest['body_fields']) !== []
            || ($body['format'] ?? null) !== 'fotp-authored-alert-v1'
            || ($body['synthetic'] ?? null) !== true
            || ! is_string($body['source_id'] ?? null)
            || preg_match('/^DEMO-[0-9]{3}$/D', $body['source_id']) !== 1) {
            throw new DemoAiException('demo_ai.body_invalid');
        }
        $id = $body['source_id'];
        $messageId = (string) $message->getHeaderValue('Message-ID');
        if (trim($messageId, '<> ') !== $id.'@fotp.example.invalid') {
            throw new DemoAiException('demo_ai.identity_mismatch');
        }
        $members = array_column($manifest['items'], 'normalized_body_sha256', 'source_id');
        $bodyHash = hash('sha256', CanonicalJson::encode($body));
        if (! isset($members[$id]) || ! hash_equals($members[$id], $bodyHash)) {
            throw new DemoAiException('demo_ai.content_unapproved');
        }
        $input = array_fill_keys(array_diff($manifest['body_fields'], ['format', 'synthetic']), null);
        foreach ($input as $field => $_) {
            $input[$field] = $body[$field] ?? null;
        }
        $this->validateFields($input);
        $encoded = CanonicalJson::encode($input);
        if (strlen($encoded) > 12_288) {
            throw new DemoAiException('demo_ai.payload_size');
        }

        return [
            'source_namespace' => $manifest['source_namespace'], 'source_id' => $id,
            'body_sha256' => $bodyHash, 'input_sha256' => hash('sha256', $encoded),
            'raw_sha256' => hash('sha256', $raw), 'message_id' => $messageId, 'input' => $input,
        ];
    }

    /** @param array<string, mixed> $input */
    public function validateFields(array $input): void
    {
        foreach (['title' => 160, 'description' => 2400, 'client_country' => 80] as $key => $limit) {
            $value = $input[$key] ?? null;
            if (($value === null && $key !== 'client_country')
                || ($value !== null && (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit))) {
                throw new DemoAiException('demo_ai.field_invalid');
            }
        }
        foreach (['skills' => [12, 40], 'required_devices' => [8, 120], 'eligible_locations' => [20, 80]] as $key => [$count, $length]) {
            $value = $input[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (! is_array($value) || ! array_is_list($value) || count($value) > $count) {
                throw new DemoAiException('demo_ai.field_invalid');
            }
            foreach ($value as $text) {
                if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $length) {
                    throw new DemoAiException('demo_ai.field_invalid');
                }
            }
        }
        foreach (['hourly_min_usd', 'hourly_max_usd', 'client_spend_usd', 'client_rating', 'hours_per_week_max', 'proposal_count'] as $key) {
            $value = $input[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
                || $value < 0 || abs($value * 100 - round($value * 100)) > 0.000001
                || ($key === 'proposal_count' && ! is_int($value))
                || ($key === 'client_rating' && $value > 5)
                || ($key === 'hours_per_week_max' && $value > 168)) {
                throw new DemoAiException('demo_ai.field_invalid');
            }
        }
        if (! in_array($input['contract_type'] ?? null, [null, 'hourly', 'fixed'], true)
            || (($input['payment_verified'] ?? null) !== null && ! is_bool($input['payment_verified']))
            || (($input['hourly_min_usd'] ?? null) !== null && ($input['hourly_max_usd'] ?? null) !== null
                && $input['hourly_min_usd'] > $input['hourly_max_usd'])) {
            throw new DemoAiException('demo_ai.field_invalid');
        }
        $posted = $input['posted_at'] ?? null;
        if ($posted !== null && (! is_string($posted)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $posted) !== 1
            || strtotime($posted) === false)) {
            throw new DemoAiException('demo_ai.field_invalid');
        }
    }
}
