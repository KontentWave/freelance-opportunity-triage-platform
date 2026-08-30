@phase2 @mailbox-intake
Feature: Import job alerts from a dedicated mailbox
  In order to avoid opening and reviewing every alert email manually
  As the owner of a workspace
  I want authorized job-alert emails imported safely on a schedule

  Background:
    Given a workspace named "Personal freelance work"
    And mailbox intake is enabled for that workspace
    And the dedicated mailbox uses certificate-validated TLS

  Rule: A scheduled poll imports each newly discovered candidate at least once

    @smoke @critical
    Scenario: Import a newly received alert on the scheduled poll
      Given the mailbox UIDVALIDITY is 9001
      And candidate message UID 101 contains the sanitized raw fixture "hourly-client-success.eml"
      When the scheduled mailbox poll runs
      Then the mailbox run status is "succeeded"
      And the message ledger status for UID 101 is "imported"
      And the checkpoint UID is 101 in UIDVALIDITY 9001
      And exactly one opportunity exists for external job ID "200000000000000000001"
      And the source message remains in the mailbox with unchanged flags
      And no raw email content is persisted or logged

    @critical @idempotency
    Scenario: Ignore a candidate already completed by an earlier poll
      Given candidate message UID 101 in UIDVALIDITY 9001 was already imported
      When a later poll discovers candidate message UID 101 again
      Then no second mailbox delivery attempt is made for UID 101
      And exactly one opportunity exists for its external job ID
      And the later mailbox run status is "succeeded"

  Rule: Temporary delivery failures are bounded and never lose or duplicate work

    @critical @retry
    Scenario: Retry a temporary fetch failure without duplicating the opportunity
      Given candidate message UID 102 is durably recorded in the message ledger
      And fetching UID 102 fails temporarily on the first attempt
      When the first mailbox poll finishes
      Then the message ledger status for UID 102 is "retry_wait"
      And the attempt count for UID 102 is 1
      And the next attempt is scheduled 5 minutes later
      And no opportunity exists for UID 102
      When the next due mailbox poll fetches UID 102 successfully
      Then the message ledger status for UID 102 is "imported"
      And exactly one opportunity exists for UID 102
      And rediscovering UID 102 creates no duplicate opportunity

    @critical @health
    Scenario: Make an exhausted delivery failure actionable
      Given candidate message UID 103 is durably recorded in the message ledger
      And fetching UID 103 fails temporarily on every due attempt
      When the third delivery attempt finishes
      Then the message ledger status for UID 103 is "permanently_failed"
      And its error code is "mailbox.retry_exhausted"
      And no further attempt is scheduled
      And mailbox health is "unhealthy"
      And the health output contains no raw exception or mailbox credential

  Rule: Unsupported input and mailbox failures are isolated and safely observable

    @critical @security
    Scenario: Quarantine an unsupported candidate and continue the batch
      Given candidate message UID 104 has the expected envelope but no plain-text MIME part
      And candidate message UID 105 contains the sanitized raw fixture "hourly-client-success.eml"
      When the scheduled mailbox poll processes both messages
      Then the message ledger status for UID 104 is "quarantined"
      And its error code is "email.missing_plain_text"
      And the message ledger status for UID 105 is "imported"
      And the mailbox run status is "partial"
      And the checkpoint advances through UID 105
      And no raw body, recipient address, or tracking value is persisted or logged

    @critical @security @health
    Scenario Outline: Report mailbox setup failures without leaking secrets
      Given the mailbox probe fails because of "<failure>"
      When I run the mailbox connectivity check
      Then the command exits unsuccessfully
      And the command reports only error code "<error_code>"
      And no checkpoint advances
      And no mailbox hostname, username, password, server greeting, or raw exception is printed or logged

      Examples:
        | failure                | error_code                    |
        | invalid configuration  | mailbox.configuration_invalid |
        | authentication failure | mailbox.authentication_failed |
        | TLS connection failure | mailbox.connection_failed     |
        | unavailable folder     | mailbox.folder_unavailable    |
