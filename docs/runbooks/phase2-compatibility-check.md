# Phase 2 Compatibility Check

## Purpose

Verify whether the target host can support the proposed scheduled IMAP intake before production mailbox code is built. This check validates host capabilities only. It does not authorize implementation of the adapter or later Phase 2 slices.

The recorded result for candidate commit `69ab3cc` is complete. Repeat every check before deployment if the target host or mailbox provider changes.

## Safety Rules

- Never request, print, paste, record, transmit, store, or commit mailbox credentials.
- Enter credentials only through the target host's protected secret-management interface. Do not pass secrets as command-line arguments or place them in shell history.
- Do not record real endpoints, account identifiers, folder names, messages, headers, addresses, server greetings, flags, or exception text.
- Use one dedicated test folder containing only an authorized synthetic compatibility message. Do not list other folders or messages.
- Keep IMAP protocol debug logging disabled.
- Do not disable TLS certificate or peer-name validation.
- Do not delete, move, mark, flag, or otherwise mutate any source message.
- Do not copy raw RFC822 data to files, logs, terminal output, CI artifacts, screenshots, or documentation.
- Run any temporary probe from an access-controlled location outside the repository. Review it for safe output before execution and remove it after verification.
- The probe may output only the allowlisted fields defined below.

If a check cannot follow these rules, record it as `BLOCKED` without sensitive details and stop.

## Allowed Evidence

A compatibility record may contain only:

- Check identifier from this runbook.
- UTC timestamp.
- Commit SHA under verification.
- PHP version.
- `webklex/php-imap` version.
- Result: `PASS`, `FAIL`, or `BLOCKED`.
- A non-sensitive reason category from this runbook.

Do not include command transcripts when they could reveal deployment paths, environment values, endpoints, mailbox metadata, or exception messages.

## Prerequisites

- Deploy the exact candidate commit to the target PHP runtime.
- Confirm the target uses the protected deployment environment for secrets.
- Prepare one synthetic compatibility message directly in the dedicated test folder through an authorized mail client. Keep its content and identifiers out of the repository and verification output.
- Ensure the host-side probe holds fetched bytes and metadata in memory only.
- Capture source flags in memory immediately before retrieval so they can be compared after retrieval without printing either value.

## Checks

### P2-COMPAT-01: PHP 8.4 Compatibility

On the target host, verify PHP reports major/minor version 8.4 and that the package-required extensions are loaded: `fileinfo`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, and `zip`.

Safe commands may report only version and extension availability:

```shell
php -r 'printf("php_8_4=%s\n", PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? "PASS" : "FAIL"); foreach (["fileinfo", "iconv", "json", "libxml", "mbstring", "openssl", "zip"] as $extension) { printf("extension_%s=%s\n", $extension, extension_loaded($extension) ? "PASS" : "FAIL"); }'
composer show webklex/php-imap --no-ansi
```

Pass when PHP is 8.4, every required extension is available, and Composer reports the locked package version. Do not loosen the PHP platform requirement if this fails.

### P2-COMPAT-02: Certificate-Validated TLS IMAP

From the target host, use the temporary probe with the deployment's configured TLS mode and certificate validation enabled. Confirm all of the following without printing connection details:

- An outbound IMAP connection can be established.
- Certificate chain validation succeeds.
- Peer-name validation succeeds.
- No insecure fallback or validation bypass is used.

Pass only when the probe outputs `check=P2-COMPAT-02 result=PASS`. Categorize failure as `tls_unavailable` without recording the endpoint or raw exception.

### P2-COMPAT-03: Authentication and Dedicated-Folder Selection

Using secrets supplied directly by the target secret manager, authenticate and select exactly the configured dedicated test folder. The probe must not enumerate folders, messages, subjects, senders, recipients, or account details.

Pass only when authentication succeeds and the configured folder is selected without fallback. Categorize failures as `authentication_failed` or `folder_unavailable` without recording server or account details.

### P2-COMPAT-04: UID and UIDVALIDITY

For the selected folder, verify that:

- The server reports UIDVALIDITY.
- The synthetic message has a positive UID.
- UID-based selection retrieves that same message reference.
- Repeating the check in a new connection preserves UID and UIDVALIDITY while the mailbox namespace is unchanged.

Compare values in memory. Output only `check=P2-COMPAT-04 result=PASS` or a safe `uid_support_failed` category; do not print UID values or mailbox metadata.

### P2-COMPAT-05: Complete Raw RFC822 Retrieval

Retrieve the synthetic message by UID as complete original RFC822 bytes, including headers and body. The probe must not reconstruct the message from decoded parts.

Before placing the message in the folder, calculate its byte length and SHA-256 digest in the authorized local process. During the target-host check, compare the fetched byte length and digest in memory against those expected values. Do not print or persist the bytes, digest, length, headers, or content.

Pass only when both comparisons match. Categorize failure as `raw_rfc822_mismatch`.

### P2-COMPAT-06: RFC822.SIZE Availability

Request metadata for the synthetic message by UID before fetching its body. Confirm RFC822.SIZE is present, positive, and usable for enforcing the existing 1 MiB pre-fetch limit.

Compare the reported size with the fetched octet count in memory according to server semantics. Output only the check result. Categorize absence or unusable size metadata as `rfc822_size_unavailable`.

### P2-COMPAT-07: PEEK Retrieval and Unchanged Flags

Capture the synthetic message's flags in memory, retrieve the complete message using PEEK semantics, then read the flags again through a fresh server query.

Pass only when the before and after flag sets are identical and the message remains in the same folder. Do not print flag names or values. Categorize any change or mutation as `peek_mutated_message`.

### P2-COMPAT-08: Provider Cron for Laravel Scheduler

In the provider's protected scheduling controls, verify that cron can invoke the deployed application's `php artisan schedule:run` every minute using the target PHP runtime. Do not include deployment paths or host identifiers in evidence.

Use a benign scheduler invocation to confirm the command can start and exit successfully. This slice does not add the mailbox schedule, so do not enable mailbox network work as part of this check.

Pass only when the provider supports the required cadence and successful invocation. Categorize failure as `scheduler_unavailable`.

## Result Matrix

| Check        | Capability                                    | Recorded result |
| ------------ | --------------------------------------------- | --------------- |
| P2-COMPAT-01 | PHP 8.4 and required extensions               | PASS            |
| P2-COMPAT-02 | Certificate-validated outbound TLS IMAP       | PASS            |
| P2-COMPAT-03 | Authentication and dedicated-folder selection | PASS            |
| P2-COMPAT-04 | UID and UIDVALIDITY                           | PASS            |
| P2-COMPAT-05 | Complete raw RFC822 retrieval                 | PASS            |
| P2-COMPAT-06 | RFC822.SIZE availability                      | PASS            |
| P2-COMPAT-07 | PEEK retrieval with unchanged flags           | PASS            |
| P2-COMPAT-08 | Provider cron for `schedule:run`              | PASS            |

## Decision Rule

All eight checks must be `PASS` before ADR-004 can move from **Proposed — pending target-host verification** to an accepted decision.

Stop Phase 2 implementation if any check is `FAIL` or `BLOCKED`. Do not implement the production adapter, database tables, polling workflow, retries, scheduler, or health commands. Do not add a Node.js worker, disable certificate validation, use IMAP IDLE, widen mailbox access, mutate message flags, or loosen PHP, Laravel, or security constraints.

Record only the safe result and reason category, amend ADR-004 with the outcome, and return to project and quality review.
