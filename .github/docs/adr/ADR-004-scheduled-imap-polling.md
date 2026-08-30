# ADR-004: Scheduled IMAP Polling

- **Status:** Accepted — target-host verified
- **Date:** 2026-08-29
- **Decision owners:** Project and quality engineering

## Context

Phase 2 must import authorized job-alert messages from one dedicated mailbox folder without changing the Phase 1 parser or normalized opportunity contract. The existing `ImportOpportunityEmail` action accepts complete raw RFC822 bytes and provides workspace-scoped duplicate protection by message identity, content hash, and provider job identity.

The deployment target is expected to run PHP 8.4 and Laravel 13 with MariaDB 11.4. Its outbound IMAP and cron capabilities have not yet been verified. A permanent Node.js mail worker would introduce another production runtime, deployment path, and operational boundary before that complexity is justified.

Composer resolves `webklex/php-imap:^6.2` to version 6.2.0 on the repository's PHP 8.4.12 platform. Locked dependency audit evidence is clean. This proves local dependency compatibility only; it does not prove target-host IMAP behavior.

During compatibility preparation, a current alert was observed with the exact sender address `upwork@t.upwork.com`, while Phase 1 accepted only `donotreply@upwork.com`. The parser allowlist was expanded to include both exact addresses without changing the normalized opportunity contract. This is not a domain wildcard, and the parser remains authoritative after the mailbox envelope pre-filter.

## Proposed Decision

Use a Laravel Artisan command invoked by Laravel's scheduler and the hosting provider's cron facility. The provider cron will invoke `php artisan schedule:run`; the application poll will run on a bounded schedule rather than as a daemon or IMAP IDLE process.

Hide IMAP operations behind a replaceable `MailboxClient` domain boundary. The production adapter may use the core `webklex/php-imap` package, but application workflow and domain code must not depend directly on that package. Do not install `webklex/laravel-imap`.

Use at-least-once delivery. Discover messages by UID within a UIDVALIDITY namespace, durably record delivery state before processing, and pass complete raw RFC822 bytes to the existing `ImportOpportunityEmail` action. Repeated delivery is expected and is resolved by the Phase 1 idempotency guards. The future mailbox ledger will provide operational delivery tracking, not replace Phase 1 duplicate protection.

Retrieval must use PEEK semantics and must not change flags, move messages, delete messages, or access folders outside the configured dedicated folder. Certificate validation and protocol debug-log suppression are mandatory. Raw messages remain in memory only for the call to the Phase 1 importer and are not persisted or logged.

## Consequences

- Deployment remains a single Laravel/PHP application with no permanent Node.js service.
- The scheduler depends on the provider reliably invoking `schedule:run` at the supported cadence.
- At-least-once processing requires durable checkpoint and ledger work in a later slice.
- Package-specific behavior remains replaceable through `MailboxClient`.
- Live transport behavior cannot be accepted from local Composer evidence alone.
- A failed target-host proof blocks the production adapter and all later polling work.

## Target-Host Verification Gate

All items in `.github/docs/runbooks/phase2-compatibility-check.md` must pass on the target host before this ADR can be accepted:

- PHP 8.4 and required extension compatibility.
- Outbound IMAP over certificate-validated TLS.
- Authentication and selection of only the configured dedicated folder.
- Stable UID and UIDVALIDITY support.
- Complete raw RFC822 retrieval.
- RFC822.SIZE availability before body retrieval.
- PEEK retrieval with source message flags unchanged.
- Provider cron availability for Laravel `schedule:run`.

Verification evidence must contain only safe status metadata. It must not contain mailbox endpoints, account identifiers, credentials, folder contents, message content, headers, addresses, server greetings, or raw exception text.

### Verification Attempt: 2026-08-30

Candidate commit: `69ab3cc`

| Check          | Result | Safe reason                                            |
| -------------- | ------ | ------------------------------------------------------ |
| `P2-COMPAT-01` | `PASS` | PHP 8.4 and required extensions available              |
| `P2-COMPAT-02` | `PASS` | Certificate-validated TLS connection established       |
| `P2-COMPAT-03` | `PASS` | Authentication and isolated-folder selection succeeded |
| `P2-COMPAT-04` | `PASS` | UID and UIDVALIDITY stable across reconnect            |
| `P2-COMPAT-05` | `PASS` | Complete raw RFC822 bytes matched expected input       |
| `P2-COMPAT-06` | `PASS` | RFC822.SIZE available and matched fetched octets       |
| `P2-COMPAT-07` | `PASS` | PEEK retrieval left message flags unchanged            |
| `P2-COMPAT-08` | `PASS` | Active cron and `schedule:run` invocation verified     |

All compatibility checks passed on the designated target. Earlier failed attempts were resolved by correcting protected target inputs and using the package's supported UID-array fetch shape; no transport, authentication, or mailbox security constraint was weakened. Repeat this gate before deployment if the target host or mailbox provider changes.

## Explicit Stop Conditions

Stop before implementing the production adapter, delivery tables, polling workflow, retries, scheduler, or health commands if any of these conditions applies:

- Certificate-validated outbound TLS IMAP cannot be established from the target host.
- Authentication or dedicated-folder selection cannot be proven safely.
- Stable UID and UIDVALIDITY behavior cannot be proven.
- Complete original RFC822 bytes cannot be retrieved.
- RFC822.SIZE is unavailable for the required pre-fetch size guard.
- PEEK retrieval changes flags or any required retrieval operation mutates mailbox state.
- The provider cannot invoke Laravel `schedule:run` at the required cadence.
- Verification would require exposing credentials, personal mailbox data, raw content, headers, addresses, server details, or raw exceptions.

Do not work around a failed proof by disabling certificate validation, widening folder access, changing message flags, adding IMAP IDLE, introducing a Node.js worker, or loosening PHP, Laravel, or dependency-security constraints. Record the failed item using safe status metadata, amend this ADR, and obtain project and quality review before continuing.

## Alternatives Considered

### Permanent Node.js mail worker

Deferred. It adds a second production runtime and long-lived process without evidence that scheduled PHP polling is insufficient. It may be reconsidered only through an amended ADR supported by target-host evidence.

### Laravel IMAP wrapper

Rejected for this phase. The core package is sufficient for the proposed adapter boundary, and the wrapper would add framework coupling that the boundary is intended to avoid.

### IMAP IDLE or queue worker

Out of scope. The target operating model is bounded scheduled polling with no required daemon or queue worker.

## Scope Boundary

This ADR does not approve or implement mailbox configuration, a production IMAP adapter, persistence tables, polling, retry behavior, scheduler registration, connectivity or health commands, OAuth, HTTP marketplace access, scraping, or browser automation. It does not change Phase 1 parsing or normalization.
