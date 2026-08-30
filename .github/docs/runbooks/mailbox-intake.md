# Mailbox Intake Operations

## Purpose

Deploy and operate Phase 2 scheduled mailbox intake without exposing mailbox data or changing source messages. This runbook assumes the target-host checks in `phase2-compatibility-check.md` and ADR-004 are accepted.

The application reads only candidate messages from one authorized, dedicated IMAP folder. It does not delete, move, flag, or mark source messages as read.

## Safety Rules

- Store credentials only in the deployment provider's protected secret manager.
- Never paste credentials into commands, tickets, logs, screenshots, CI output, documentation, or shell history.
- Never print or record mailbox hostnames, usernames, passwords, folder names, subjects, addresses, headers, message bodies, server greetings, raw exceptions, or protocol traces.
- Keep `APP_DEBUG=false` and IMAP protocol debugging disabled outside local development.
- Keep TLS certificate validation enabled. Do not use an insecure fallback.
- Configure only a dedicated mailbox folder that contains authorized job alerts.
- Do not run connectivity or polling commands from CI.
- Record only UTC timestamps, commit SHA, CI URL, safe counters, statuses, and allowlisted error codes.

If an operation cannot follow these rules, disable intake and stop without collecting additional mailbox details.

## Prerequisites

Before enabling intake, confirm:

- The exact candidate commit passed the required `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` checks.
- All eight checks in `phase2-compatibility-check.md` remain valid for the target host and mailbox provider.
- PHP 8.4, Laravel 13, MariaDB 11.4, and the locked Composer dependencies are deployed.
- The provider can invoke `php artisan schedule:run` every minute.
- The default cache store supports atomic locks and is shared by every scheduler process on the host. The configured database cache store satisfies the single-node deployment requirement.
- Database migrations have completed successfully.
- The dedicated folder contains no unrelated personal mail.

## Protected Configuration

Set these values through the deployment provider's protected environment interface. Do not place production values in a repository file.

| Key                                            | Operational rule                                                  |
| ---------------------------------------------- | ----------------------------------------------------------------- |
| `OPPORTUNITY_MAILBOX_ENABLED`                  | Keep `false` until preflight succeeds.                            |
| `OPPORTUNITY_MAILBOX_WORKSPACE_ID`             | Existing workspace ULID that owns all imported records.           |
| `OPPORTUNITY_MAILBOX_KEY`                      | Non-secret identifier, maximum 64 characters; normally `primary`. |
| `OPPORTUNITY_MAILBOX_HOST`                     | Required secret-adjacent endpoint; never print it.                |
| `OPPORTUNITY_MAILBOX_PORT`                     | Valid port from 1 through 65535; normally `993`.                  |
| `OPPORTUNITY_MAILBOX_ENCRYPTION`               | `ssl` or `tls` only.                                              |
| `OPPORTUNITY_MAILBOX_VALIDATE_CERT`            | Must be `true`.                                                   |
| `OPPORTUNITY_MAILBOX_USERNAME`                 | Secret-adjacent account identifier; never print it.               |
| `OPPORTUNITY_MAILBOX_PASSWORD`                 | Secret; never expose or persist it.                               |
| `OPPORTUNITY_MAILBOX_FOLDER`                   | Dedicated authorized folder only; never print it.                 |
| `OPPORTUNITY_MAILBOX_CANDIDATE_FROM`           | Envelope pre-filter; default `upwork@t.upwork.com`.               |
| `OPPORTUNITY_MAILBOX_CANDIDATE_SUBJECT_PREFIX` | Envelope pre-filter; default `New job alert:`.                    |
| `OPPORTUNITY_MAILBOX_BATCH_SIZE`               | Clamp is 1-100; default `25`.                                     |
| `OPPORTUNITY_MAILBOX_INITIAL_LOOKBACK_HOURS`   | Clamp is 1-168; default `24`.                                     |
| `OPPORTUNITY_MAILBOX_MAX_ATTEMPTS`             | Clamp is 1-5; Phase 2 policy uses `3`.                            |
| `OPPORTUNITY_MAILBOX_HEALTH_MAX_AGE_MINUTES`   | Positive integer; default `15`.                                   |

After changing protected configuration, rebuild Laravel's configuration cache through the provider's deployment process:

```shell
php artisan config:cache
```

Do not use `php artisan config:show opportunity_mailbox` in production because its output includes protected mailbox configuration.

## Deployment Sequence

1. Deploy the reviewed commit with `OPPORTUNITY_MAILBOX_ENABLED=false`.
2. Run database migrations through the normal deployment mechanism:

    ```shell
    php artisan migrate --force
    ```

3. Set all protected mailbox configuration values except the enabled flag.
4. Rebuild the configuration cache.
5. Confirm the application is not in debug mode through the provider configuration. Do not dump the complete application configuration.
6. Set `OPPORTUNITY_MAILBOX_ENABLED=true` and rebuild the configuration cache again.
7. Run the safe connectivity check once from the target host:

    ```shell
    php artisan opportunity:mailbox-check
    ```

    Expected success output contains only:

    ```text
    status: succeeded
    ```

8. If connectivity succeeds, run one controlled poll:

    ```shell
    php artisan opportunity:poll-mailbox
    ```

9. Confirm the output contains only a run status, safe counters, and an optional stable error code.
10. Run the persisted health check:

    ```shell
    php artisan opportunity:mailbox-health --json
    ```

Do not redirect command output to public logs or artifacts. Stop and disable intake if any output contains non-allowlisted mailbox information.

## Provider Cron

Configure one provider cron entry to invoke Laravel's scheduler every minute from the deployed application directory:

```cron
* * * * * php artisan schedule:run
```

Use the provider's supported absolute PHP and application paths in its protected scheduling interface. Do not record those paths in public evidence.

Laravel schedules `opportunity:poll-mailbox` every five minutes with a 10-minute overlap lock. Do not add a second direct cron entry for the poll command. Do not use `runInBackground()`, a queue worker, IMAP IDLE, or a permanent process.

A deployment may safely confirm registration without contacting IMAP:

```shell
php artisan schedule:list
```

The listing must show `opportunity:poll-mailbox` with `*/5 * * * *`. The scheduler runtime filter prevents polling when intake is disabled.

## Routine Health Check

Use persisted health state; this command does not connect to IMAP:

```shell
php artisan opportunity:mailbox-health --json
```

Health meanings:

| Status      | Meaning                                                                                                             | Action                                                                          |
| ----------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `healthy`   | Latest completed run is recent, and no retry is overdue or permanently failed.                                      | No action.                                                                      |
| `degraded`  | A recent run is partial, quarantine exists, or a retry is pending but not overdue.                                  | Review safe counters and stable code; monitor the next poll.                    |
| `unhealthy` | Configuration is invalid, the latest run failed or is stale, a retry is overdue, or delivery is permanently failed. | Disable intake if the condition persists; investigate using safe metadata only. |
| `never_run` | No completed run exists for the configured workspace and mailbox key.                                               | Confirm cron, enabled configuration, and scheduler registration.                |

The command returns exit code `0` only for `healthy`. Every other health state returns `1`.

Allowlisted output may include:

- `status`
- `last_run_status`
- `last_run_finished_at`
- safe run counters
- one namespaced `mailbox.*` or `email.*` error code

Do not query or export complete database rows for incident evidence.

## Stable Error Response

| Error code                      | Operational response                                                                                                   |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `mailbox.configuration_invalid` | Disable intake and correct protected configuration or workspace ownership.                                             |
| `mailbox.insecure_transport`    | Disable intake; restore `ssl` or `tls` and certificate validation.                                                     |
| `mailbox.authentication_failed` | Disable intake; rotate or correct credentials through the secret manager.                                              |
| `mailbox.connection_failed`     | Check provider networking and mailbox availability without recording endpoints or raw exceptions.                      |
| `mailbox.folder_unavailable`    | Disable intake; verify the dedicated folder through an authorized mail client.                                         |
| `mailbox.uidvalidity_changed`   | Monitor the bounded rescan and duplicate counter; Phase 1 idempotency remains authoritative.                           |
| `mailbox.message_too_large`     | Leave the source message unchanged; the ledger quarantine is terminal.                                                 |
| `mailbox.message_fetch_failed`  | Monitor the bounded retry schedule.                                                                                    |
| `mailbox.import_failed`         | Monitor retries; investigate application behavior using safe codes and counters only.                                  |
| `mailbox.retry_exhausted`       | Treat as actionable failure; preserve the ledger and source message for reviewed recovery.                             |
| `email.*`                       | Typed Phase 1 quarantine; do not retry automatically. Review parser compatibility using separately sanitized fixtures. |

Never add raw exception messages to logs to diagnose these codes.

## Disable Intake

To stop scheduled network work immediately:

1. Set `OPPORTUNITY_MAILBOX_ENABLED=false` in the provider's protected environment interface.
2. Rebuild Laravel's configuration cache:

    ```shell
    php artisan config:cache
    ```

3. Confirm `php artisan opportunity:mailbox-health --json` reports `unhealthy` with `mailbox.configuration_invalid`.
4. Leave the provider's minute-level `schedule:run` cron installed for other application schedules.
5. Do not delete mailbox runs, checkpoints, message ledgers, email imports, or opportunities.

When urgent provider controls require it, disabling the cron also stops all Laravel schedules, not only mailbox intake. Prefer the feature flag unless the whole scheduler must stop.

## Rollback

1. Disable mailbox intake before changing application code.
2. Keep existing Phase 2 tables and delivery records intact.
3. Deploy the last reviewed compatible application release.
4. Run forward-compatible migrations only. Do not perform a destructive migration rollback.
5. Keep the Phase 1 local `.eml` import command available as the operational fallback.
6. Re-enable intake only after configuration, connectivity, one controlled poll, and persisted health checks pass again.

Rollback must not delete imported opportunities or mutate source mailbox messages.

## 24-Hour Staging Soak

The soak is a separate deployment verification step and is not completed by this runbook.

Before starting:

- Deploy the exact reviewed commit to staging.
- Confirm all required CI checks pass.
- Confirm the dedicated authorized folder and protected configuration.
- Confirm connectivity succeeds and source flags remain unchanged.
- Record only the UTC start timestamp and commit SHA.

During the soak:

- Let the provider invoke `schedule:run` every minute for at least 24 continuous hours.
- Use persisted run timestamps and safe counters to confirm five-minute polling.
- Monitor `opportunity:mailbox-health --json` without enabling protocol or exception logging.
- Do not capture raw messages, headers, addresses, subjects, endpoints, credentials, UIDs, hashes, or database exports.

At completion, verify:

- Every authorized candidate UID has a corresponding durable ledger row, using an access-controlled in-memory comparison whose values are not printed.
- Repeated delivery created no duplicate opportunity.
- No retry is overdue and no unreviewed permanent failure remains.
- Source messages and flags are unchanged.
- Database rows, application logs, console output, screenshots, and CI artifacts contain no prohibited mailbox data.
- Final health is `healthy`.

Allowed soak evidence contains only:

- UTC start and finish timestamps
- commit SHA
- CI URL
- aggregate safe counters
- final health status
- `PASS`, `FAIL`, or `BLOCKED`

If any criterion fails, record only a stable code or safe reason category, disable intake, and return to project and quality review.
