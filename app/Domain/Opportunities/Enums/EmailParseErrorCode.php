<?php

namespace App\Domain\Opportunities\Enums;

enum EmailParseErrorCode: string
{
    case EmailTooLarge = 'email_too_large';
    case MimeParseFailed = 'mime_parse_failed';
    case MissingMessageId = 'missing_message_id';
    case UnsupportedSender = 'unsupported_sender';
    case UnsupportedSubject = 'unsupported_subject';
    case MissingPlainText = 'missing_plain_text';
    case UnsupportedContractType = 'unsupported_contract_type';
    case MissingJobId = 'missing_job_id';
    case InvalidJobUrl = 'invalid_job_url';
    case MissingTitle = 'missing_title';
    case MalformedTerms = 'malformed_terms';
    case UnsupportedTemplate = 'unsupported_template';
}
