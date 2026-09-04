<?php

namespace App\Infrastructure\Email;

use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\DiscoveredMailboxBatch;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Data\MailboxCursor;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Data\MailboxProbeResult;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;

final class WebklexImapMailboxClient implements MailboxClient
{
    /** @var Closure(bool, string): ImapProtocol */
    private Closure $protocolFactory;

    private ?ImapProtocol $protocol = null;

    private ?int $uidValidity = null;

    /** @param null|Closure(bool, string): ImapProtocol $protocolFactory */
    public function __construct(
        private readonly MailboxConfiguration $configuration,
        ?Closure $protocolFactory = null,
    ) {
        $this->protocolFactory = $protocolFactory
            ?? fn (bool $validateCert, string $encryption): ImapProtocol => $this->makeProtocol(
                $validateCert,
                $encryption,
            );
    }

    public function probe(): MailboxProbeResult
    {
        $this->connectAndSelectFolder();

        return new MailboxProbeResult(successful: true);
    }

    public function discover(MailboxCursor $cursor, int $limit): DiscoveredMailboxBatch
    {
        $protocol = $this->connectAndSelectFolder();
        $uidValidity = $this->uidValidity;

        if ($uidValidity === null || $uidValidity < 1) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::ConnectionFailed);
        }

        try {
            $uids = $protocol->search(
                [$this->searchCriteria($cursor, $uidValidity)],
                IMAP::ST_UID,
            )->validatedData();
        } catch (Throwable) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::ConnectionFailed);
        }

        $uids = array_values(array_unique(array_map(
            static fn (mixed $uid): int => (int) $uid,
            is_array($uids) ? $uids : [],
        )));
        $uids = array_values(array_filter(
            $uids,
            static fn (int $uid): bool => $uid > 0,
        ));

        if ($cursor->uidValidity === $uidValidity) {
            $uids = array_values(array_filter(
                $uids,
                static fn (int $uid): bool => $uid > $cursor->lastDiscoveredUid,
            ));
        }

        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, max(0, $limit));

        if ($uids === []) {
            return new DiscoveredMailboxBatch(
                uidValidity: $uidValidity,
                messages: [],
                highestDiscoveredUid: $cursor->uidValidity === $uidValidity
                    ? $cursor->lastDiscoveredUid
                    : 0,
            );
        }

        try {
            $metadata = $protocol->fetch(
                ['UID', 'RFC822.SIZE'],
                $uids,
                null,
                IMAP::ST_UID,
            )->validatedData();
        } catch (Throwable) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageFetchFailed);
        }

        $messages = [];
        foreach ($uids as $uid) {
            $values = $this->normalizeFetchValues($this->fetchValuesForUid($metadata, $uid));
            $reportedSize = (int) ($values['RFC822.SIZE'] ?? 0);

            if ($reportedSize < 1) {
                throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageFetchFailed);
            }

            $messages[] = new MailboxMessageReference($uid, $reportedSize);
        }

        return new DiscoveredMailboxBatch(
            uidValidity: $uidValidity,
            messages: $messages,
            highestDiscoveredUid: $uids[array_key_last($uids)],
        );
    }

    public function fetchRaw(MailboxMessageReference $message, int $maximumBytes): string
    {
        if ($message->reportedSize > $maximumBytes) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageTooLarge);
        }

        $protocol = $this->connectAndSelectFolder();

        try {
            $response = $protocol->fetch(
                ['UID', 'RFC822.SIZE', 'BODY.PEEK[]'],
                [$message->uid],
                null,
                IMAP::ST_UID,
            )->validatedData();
        } catch (Throwable) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageFetchFailed);
        }

        $values = $this->normalizeFetchValues($this->fetchValuesForUid($response, $message->uid));
        $rawMessage = $this->rawBody($values);

        if ($rawMessage === null) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageFetchFailed);
        }

        if (strlen($rawMessage) > $maximumBytes) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::MessageTooLarge);
        }

        return $rawMessage;
    }

    public function close(): void
    {
        if ($this->protocol === null) {
            return;
        }

        try {
            $this->protocol->logout();
        } catch (Throwable) {
        } finally {
            $this->protocol = null;
            $this->uidValidity = null;
        }
    }

    private function connectAndSelectFolder(): ImapProtocol
    {
        if (! $this->configuration->enabled) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::ConfigurationInvalid);
        }

        if ($this->protocol !== null) {
            return $this->protocol;
        }

        try {
            $protocol = ($this->protocolFactory)(
                $this->configuration->validateCert,
                $this->protocolEncryption(),
            );
            $protocol->disableDebug();
            $protocol->disableUidCache();
            $protocol->connect(
                (string) $this->configuration->host,
                $this->configuration->port,
            );
        } catch (Throwable) {
            if (isset($protocol)) {
                $this->logout($protocol);
            }

            throw new MailboxIntakeException(MailboxIntakeErrorCode::ConnectionFailed);
        }

        try {
            $protocol->login(
                (string) $this->configuration->username,
                (string) $this->configuration->password,
            )->validate();
        } catch (Throwable) {
            $this->logout($protocol);

            throw new MailboxIntakeException(MailboxIntakeErrorCode::AuthenticationFailed);
        }

        try {
            $folder = $protocol->examineFolder((string) $this->configuration->folder);
            $folder->validate();
            $this->uidValidity = (int) ($folder->array()['uidvalidity'] ?? 0);
        } catch (Throwable) {
            $this->logout($protocol);

            throw new MailboxIntakeException(MailboxIntakeErrorCode::FolderUnavailable);
        }

        $this->protocol = $protocol;

        return $protocol;
    }

    private function makeProtocol(bool $validateCert, string $encryption): ImapProtocol
    {
        $protocol = new ImapProtocol(
            Config::make(['options' => ['debug' => false, 'uid_cache' => false]]),
            $validateCert,
            $encryption,
        );
        $protocol->setConnectionTimeout(30);
        $protocol->setSslOptions([]);

        return $protocol;
    }

    private function protocolEncryption(): string
    {
        return $this->configuration->encryption === 'tls'
            ? 'starttls'
            : $this->configuration->encryption;
    }

    private function logout(ImapProtocol $protocol): void
    {
        try {
            $protocol->logout();
        } catch (Throwable) {
        }
    }

    private function searchCriteria(MailboxCursor $cursor, int $uidValidity): string
    {
        $criteria = [
            $this->candidateFromSearchCriteria(),
            'SUBJECT '.$this->quoteSearchValue($this->configuration->candidateSubjectPrefix),
        ];

        if ($cursor->uidValidity === $uidValidity) {
            $criteria[] = 'UID '.($cursor->lastDiscoveredUid + 1).':*';
        } else {
            $lookbackAt = $cursor->initialLookbackAt
                ?? CarbonImmutable::now('UTC')->subHours($this->configuration->initialLookbackHours);
            $criteria[] = 'SINCE '.$lookbackAt->format('d-M-Y');
        }

        return implode(' ', $criteria);
    }

    private function candidateFromSearchCriteria(): string
    {
        $addresses = $this->configuration->candidateFromAddresses();
        $criteria = 'FROM '.$this->quoteSearchValue(array_shift($addresses));

        foreach ($addresses as $address) {
            $criteria = 'OR '.$criteria.' FROM '.$this->quoteSearchValue($address);
        }

        return $criteria;
    }

    private function quoteSearchValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function fetchValuesForUid(mixed $response, int $uid): mixed
    {
        if (! is_array($response)) {
            return null;
        }

        return $response[$uid] ?? $response[(string) $uid] ?? null;
    }

    /** @return array<string, mixed> */
    private function normalizeFetchValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[strtoupper((string) $key)] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $values */
    private function rawBody(array $values): ?string
    {
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'BODY')) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
