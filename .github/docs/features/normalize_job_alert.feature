@phase1 @email-normalization
Feature: Normalize a job-alert email
  In order to review freelance opportunities efficiently
  As the owner of a workspace
  I want an authorized job-alert email converted into a safe, normalized opportunity

  Background:
    Given a workspace named "Personal freelance work"

  Rule: Supported alerts become normalized workspace-owned opportunities

    @smoke @critical
    Scenario: Parse a supported hourly job alert
      Given the sanitized raw email fixture "hourly-client-success.eml"
      When I import the email for the workspace
      Then the import result is "imported"
      And exactly one opportunity exists in the workspace with:
        | field             | value                                             |
        | provider          | upwork_email                                      |
        | external job ID   | 200000000000000000001                             |
        | canonical URL     | https://www.upwork.com/jobs/~200000000000000000001 |
        | title             | Client Success and Project Manager                |
        | contract type     | hourly                                             |
        | hourly minimum    | 40.00                                              |
        | hourly maximum    | 60.00                                              |
        | currency          | USD                                                |
        | estimated duration | More than 6 months                                |
      And the opportunity has these visible skills in source order:
        | position | skill              |
        | 0        | Project Management |
        | 1        | Quality Assurance  |
        | 2        | Communication      |
      And the canonical URL contains no query parameters or fragment
      And the import makes no external network request

    @critical @missing-data
    Scenario: Represent an unavailable hourly rate as unknown
      Given the sanitized raw email fixture "hourly-unknown-rate.eml"
      And the alert displays an hourly range of "$0.00 - $0.00"
      When I import the email for the workspace
      Then the import result is "imported"
      And the opportunity contract type is "hourly"
      And the opportunity hourly minimum is absent
      And the opportunity hourly maximum is absent
      And the opportunity is not represented as zero-rate work

  Rule: Repeated delivery is idempotent within each workspace

    @critical @idempotency
    Scenario: Ignore a duplicate job alert
      Given the sanitized raw email fixture "hourly-operations-coordinator.eml" was imported for the workspace
      When I import the same raw email for the same workspace again
      Then the second import result is "duplicate"
      And exactly one opportunity exists for its external job ID in the workspace
      And no duplicate skill records are created
      And the existing opportunity values remain unchanged

  Rule: Untrusted or unsupported input fails safely

    @critical @security
    Scenario Outline: Quarantine an unsupported or malformed email
      Given a raw email that is <condition>
      When I attempt to import the email for the workspace
      Then the import result is "quarantined"
      And the import error code is "<error_code>"
      And no opportunity is created
      And the quarantine record contains no raw body, recipient address, or tracking token
      And the import makes no external network request

      Examples:
        | condition                              | error_code             |
        | larger than the configured 1 MiB limit | email_too_large        |
        | from an unexpected sender              | unsupported_sender     |
        | missing a plain-text MIME part         | missing_plain_text     |
        | missing a canonical job identifier     | missing_job_id         |
