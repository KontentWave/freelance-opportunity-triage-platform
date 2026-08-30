<?php

namespace App\Domain\Mailbox\Enums;

enum MailboxIntakeErrorCode: string
{
    case ConfigurationInvalid = 'mailbox.configuration_invalid';
    case InsecureTransport = 'mailbox.insecure_transport';
    case AuthenticationFailed = 'mailbox.authentication_failed';
    case ConnectionFailed = 'mailbox.connection_failed';
    case FolderUnavailable = 'mailbox.folder_unavailable';
    case UidValidityChanged = 'mailbox.uidvalidity_changed';
    case MessageTooLarge = 'mailbox.message_too_large';
    case MessageFetchFailed = 'mailbox.message_fetch_failed';
    case ImportFailed = 'mailbox.import_failed';
    case RetryExhausted = 'mailbox.retry_exhausted';
}
