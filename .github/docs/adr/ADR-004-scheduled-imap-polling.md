# ADR-004: Scheduled IMAP Polling

- **Status:** Accepted — target-host verified; disabled redirect parser integration complete locally
- **Date:** 2026-08-29
- **Last amended:** 2026-09-02
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

## Amendment: Redirect-Only Alert Canonicalization

This amendment approves the isolated resolver and the disabled-by-default local parser/import integration with synthetic fixtures and fake resolver boundaries. It does not approve production HTTP resolution, deployment, a live request, historical replay, mailbox intake, provider cron, or the staging soak. Those actions remain disabled until separately reviewed and explicitly approved.

### Trigger and Bounded Offline Spike

Seven days of aggregate staging evidence showed 41 alerts from the supported direct-link sender and 31 alerts from the supported redirect-only sender. Excluding the redirect-only sender would omit approximately 43 percent of candidate alerts. No raw message, header, mailbox identifier, tracking value, or database record was used or retained for this spike.

The spike evaluated only repository-owned synthetic MIME and tracking-link structures:

- The supported direct-link fixture exposes `/jobs/~<digits>` in decoded `text/plain`, which remains sufficient for deterministic offline normalization.
- The synthetic redirect-only structure is an HTTPS tracking URL with an opaque token and no canonical path, job identifier, or documented reversible payload.
- The MIME fields currently required by the parser are `Message-ID`, `From`, `Subject`, `Date`, and decoded `text/plain`. The first four identify and validate the message but do not independently identify an Upwork job.
- Percent-decoding, Base64-decoding, token slicing, or numeric-substring extraction cannot establish provenance or integrity for an opaque token. Such heuristics could manufacture a job identity from attacker-controlled input and are therefore rejected.

Offline canonical-ID recovery is not defensible from the available synthetic structure or another non-sensitive MIME field. This conclusion is deliberately narrow: a future documented, authenticated token format could justify a new offline spike, but must not be inferred from private samples.

### Decision

Permit a dedicated `RedirectDestinationResolver` boundary to resolve an otherwise supported redirect-only alert only after ordinary MIME parsing, exact sender validation, subject validation, and plain-text extraction succeed. The parser remains authoritative for the final canonical URL and all normalized opportunity fields.

Parser integration keeps the resolver disabled by default behind `OPPORTUNITY_REDIRECT_RESOLUTION_ENABLED=false`. Approval of this amendment authorizes only the local integration and fake-only automated tests, not production resolver bindings, deployment, a live request, cron enablement, or the staging soak. Those actions require their existing explicit operational approvals.

Resolution must satisfy all of these controls:

- Accept only an absolute `https` URL with no user information, no explicit non-443 port, and an exact initial tracking host from a code-reviewed allowlist. Wildcards, suffix matching, environment-supplied arbitrary hosts, IP literals, and alternate URL schemes are forbidden.
- Allow redirects only among separately enumerated tracking hosts and the exact canonical host `www.upwork.com`. Validate scheme, host, port, user information, and normalized URL syntax before every request and before accepting every `Location` value.
- Resolve and validate every A and AAAA result before each hop. Reject loopback, private, link-local, carrier-grade NAT, documentation, benchmark, multicast, reserved, unspecified, and other non-global addresses. The transport must connect to a validated address while preserving hostname-based TLS verification so DNS rebinding cannot bypass the check.
- Follow redirects manually. Do not rely on an HTTP client's automatic redirect handling because every hop must pass the same URL, DNS, and address checks.
- Use `HEAD` only, accept only redirect statuses `301`, `302`, `303`, `307`, and `308`, and stop as soon as a validated `Location` is the canonical `https://www.upwork.com/jobs/~<digits>` URL. Do not fetch the canonical destination and do not fall back to `GET`.
- Permit at most three redirect responses, a two-second connection timeout, and five seconds total elapsed time. Do not retry inside one import attempt.
- Bound response headers to 8 KiB and reject a response that exceeds the limit. Do not request, download, buffer, parse, or inspect a response body.
- Send no cookies, authentication, mailbox data, message identifiers, recipient data, tracking headers, or `Referer`. Explicitly disable all proxy use, including proxy environment variables; do not use a shared cookie jar or ambient proxy credentials.
- Keep the source tracking URL and every intermediate URL in memory only for the resolution call. Never persist or log them, include them in exception messages, command output, traces, events, metrics labels, or test failure messages.
- Return only allowlisted stable error codes. Proposed codes are `redirect_url_rejected`, `redirect_address_rejected`, `redirect_timeout`, `redirect_response_invalid`, `redirect_limit_exceeded`, and `redirect_destination_invalid`; mailbox presentation prefixes these as `email.*`.
- Convert every resolution failure to typed quarantine and continue the mailbox batch. Do not retry a policy rejection. Timeout and transport retry policy, if later proposed, requires separate review because it affects poll duration and remote traffic.

The resolver must return only a canonical job ID and canonical URL. It must not return response metadata, and the application must not persist a tracking URL or redirect chain.

### Quarantine-History Constraint

Existing staging `email_imports`, mailbox message ledgers, mailbox runs, and their quarantine codes are historical evidence and must not be deleted, rewritten, or reset by this amendment or its implementation. Initially, an approved resolver would apply only to newly processed messages; existing terminal quarantines remain untouched. Any later recovery of previously quarantined messages requires a separate reviewed, append-only audit design because the current import action updates a matching quarantine record in place.

### Required Fake and Adversarial Tests

Implementation approval requires deterministic tests with outbound HTTP blocked except for exact fakes. No test may contact Upwork or any tracking service. At minimum, tests must prove:

- one allowlisted synthetic redirect resolves to a canonical job ID without requesting the final destination;
- HTTP, user-information URLs, non-443 ports, IP literals, malformed URLs, protocol-relative locations, and unlisted initial or intermediate hosts are rejected;
- IPv4 and IPv6 loopback, private, link-local, carrier-grade NAT, reserved, and mixed public/private DNS answers are rejected on every hop;
- a DNS-rebinding simulation cannot change the connected address after validation;
- redirect loops, more than three redirects, missing or oversized `Location` headers, unsupported statuses, timeouts, TLS failures, and oversized response headers produce only the documented stable codes;
- canonical-looking paths on lookalike hosts, encoded host/path confusion, fragments, and credentials cannot be accepted;
- cookies, authentication, `Referer`, message metadata, and response-body reads are absent from every request;
- tracking and intermediate URLs never appear in persistence, logs, command output, exception text, events, or metrics;
- a resolution failure quarantines only that message and does not stop the batch; and
- existing quarantine and mailbox history remains byte-for-byte unchanged.

### Review Gate

Project and quality engineering approved the isolated implementation on 2026-09-02 with `link.t.upwork.com` as the only initial and intermediate host, `www.upwork.com` as the only final host, the six documented stable error codes, `HEAD` only with no `GET` fallback, the dedicated ext-cURL hop adapter, and no modification of existing quarantine history.

Before production resolver binding or deployment, the implementation must pass the complete existing suite, focused fake/adversarial tests, static analysis, formatting, dependency audit, and secret scan. A separately approved target-host compatibility check must then prove bounded HTTPS behavior using only safe status metadata. Only after those gates may intake and cron be considered for a controlled poll and a new 24-hour soak.

### Architecture Review: 2026-09-02

The repository was reviewed offline at commit `455fa74` without using a private message, tracking value, mailbox identifier, database record, or network request.

The installed Guzzle 8.1.0 and PHP cURL transport can issue a bodyless `HEAD`, disable automatic redirects, retain certificate and hostname verification, and pass `CURLOPT_RESOLVE` so a request connects only to an address already validated by the application. Guzzle otherwise resolves proxy settings from process environment variables, so proxy bypass must be explicit rather than assumed.

Laravel's HTTP client and Guzzle own the cURL header callback. They permit validation after a complete header block but do not expose a hard aggregate raw-header byte limit through their supported request options. Therefore, the 8 KiB abort requirement cannot be guaranteed by the ordinary Laravel HTTP facade alone.

The recommended implementation shape, subject to approval, is:

- `RedirectDestinationResolver` owns URL policy, the absolute five-second deadline, manual redirect sequencing, canonical-ID extraction, and stable error mapping.
- A replaceable `HostAddressResolver` returns all A and AAAA answers for policy validation. The redirect resolver rejects the entire hop if any answer is non-global.
- A replaceable `RedirectHopClient` performs exactly one `HEAD` request to one prevalidated URL and one selected prevalidated address.
- The production hop client is a small ext-cURL adapter, not a general HTTP client. It sets `CURLOPT_RESOLVE`, certificate and hostname verification, no-follow mode, `CURLOPT_NOBODY`, explicit empty proxy settings, connection and remaining-deadline timeouts, and a raw header callback that aborts as soon as the aggregate exceeds 8 KiB.
- Tests replace the address resolver and hop client contracts with deterministic fakes. Pure policy tests cover every adversarial URL, address, redirect, deadline, and leakage case without opening a socket. Adapter option construction and header-limit behavior are tested separately without contacting an external host.

This shape adds no package dependency and prevents framework redirect middleware, ambient proxy configuration, or a second DNS lookup from bypassing per-hop policy. It is more specialized than the Laravel HTTP facade, but the specialization is required to enforce the approved SSRF and response-size invariants.

The exact initial and intermediate host allowlists and stable error-code names are approved for the isolated implementation. `HEAD` behavior for the authorized redirect service remains unverified. If a later approved, privacy-safe target-host check shows that `HEAD` does not return a usable redirect, stop and amend this ADR again; do not silently add a `GET` fallback.

Architecture review outcome: **approved and implemented as an isolated local spike; production use remains blocked**.

### Isolated Implementation Evidence: 2026-09-02

The approved spike adds the resolver policy, DNS and single-hop contracts, immutable request/response data, stable typed failures, deterministic fakes, and the dedicated ext-cURL hop adapter. It does not bind the contracts in Laravel's service container and no parser, importer, mailbox workflow, command, or configuration calls the resolver.

Focused direct PHPUnit validation passed 52 tests with 148 assertions. The tests cover exact host and scheme policy, URL confusion, all-address public-IP validation, mixed DNS answers, a stateful DNS-rebinding attempt, bounded DNS work, post-DNS absolute-deadline enforcement, address pinning, adapter request-invariant validation, timeout and redirect limits, canonical destination validation, proxy/cookie/auth/referrer suppression, raw-header limits, duplicate and stale `Location` handling, IPv6 pinning, and stable exception mapping. The complete Laravel suite passed against the repository-managed MariaDB 11.4 test database with 140 tests and 864 assertions. Repository-wide PHPStan analysis and Pint formatting passed. The locked Composer dependency audit reported no advisories. Local gitleaks was unavailable, so the required hosted `Secret scan` remains a merge and deployment gate. No test opened a network connection.

### Security Review and Parser-Integration Decision: 2026-09-02

The focused review found and resolved three blockers in the isolated boundary:

- DNS resolution had no timeout in its contract and could exceed the five-second total deadline. The contract now receives a bounded timeout, and the resolver recomputes the remaining deadline after DNS before permitting a request.
- The cURL adapter trusted caller-supplied URL, host, address, and limit invariants. It now validates URL/host consistency, public address classification, and approved timeout/header ceilings before configuring cURL, and fails closed if cURL rejects a mandatory option.
- The required DNS-rebinding simulation was absent. The fake resolver now supports stateful answers, and the adversarial test proves that a public first answer followed by a loopback answer is rejected before a second request.

Public-address classification is centralized in one immutable value object and includes explicit carrier-grade NAT, documentation, benchmark, multicast, reserved, IPv4-mapped IPv6, protocol-assignment, 6to4, unique-local, and link-local ranges in addition to PHP's built-in checks. Typed DNS timeouts remain `redirect_timeout`; unknown resolver and transport failures expose only stable codes.

**Decision:** GO for a separately approved local parser-integration slice that remains disabled by default and uses only synthetic fixtures and fake resolver boundaries. NO-GO for a live compatibility request, enabling resolution, deployment, mailbox intake, cron, controlled polling, historical replay, or the soak. The local integration slice must not weaken the `HEAD`-only policy and must preserve existing quarantine history. Production enablement remains blocked on the separately approved target-host `HEAD` compatibility check, all required hosted checks including `Secret scan`, and another project and quality review.

### Local Parser Integration Evidence: 2026-09-03

The approved local slice is implemented behind `OPPORTUNITY_REDIRECT_RESOLUTION_ENABLED=false`. Direct canonical links continue through the existing offline path without invoking the resolver. For redirect-only alerts, exact sender and subject validation and plain-text extraction occur before selecting the exact `link.t.upwork.com` tracking host. The parser retains the source URL only in memory for title positioning and returns only the resolver's canonical job ID and URL.

All six resolver failures map to stable `EmailParseErrorCode` cases and therefore to the existing `email.<code>` mailbox quarantine boundary. Synthetic tests prove disabled mode performs no DNS or HTTP work, direct links bypass the resolver even when enabled, unsupported senders are rejected before resolution, successful resolution does not fetch the canonical destination, tracking values are not persisted, and importing a new redirect-only alert leaves an existing quarantine row byte-for-byte unchanged. No production address-resolver or hop-client container binding was added.

Focused parser/import validation passed 30 tests with 264 assertions; the isolated resolver and cURL adapter remain green with 52 tests and 148 assertions. The complete MariaDB 11.4 suite passed 145 tests with 917 assertions. Repository-wide PHPStan, Pint, Composer validation, locked dependency audit, and diff checks passed. Local coverage enforcement could not run because neither PCOV nor Xdebug is installed; the protected CI coverage job remains required. Local gitleaks remains unavailable, so the hosted `Secret scan` also remains required. No test opened a network connection.

## Alternatives Considered

### Permanent Node.js mail worker

Deferred. It adds a second production runtime and long-lived process without evidence that scheduled PHP polling is insufficient. It may be reconsidered only through an amended ADR supported by target-host evidence.

### Laravel IMAP wrapper

Rejected for this phase. The core package is sufficient for the proposed adapter boundary, and the wrapper would add framework coupling that the boundary is intended to avoid.

### IMAP IDLE or queue worker

Out of scope. The target operating model is bounded scheduled polling with no required daemon or queue worker.

## Scope Boundary

This amendment adds an isolated redirect policy, a disabled and unbound HTTP hop adapter, and disabled-by-default parser/import integration using fake boundaries. It does not approve production HTTP use, OAuth, scraping, browser automation, historical replay, a live compatibility request, enabling resolution, mailbox intake, cron, controlled polling, or the soak. The accepted IMAP polling implementation remains otherwise unchanged.
