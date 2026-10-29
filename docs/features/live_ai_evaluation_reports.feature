@phase6 @backend @offline
Feature: Bounded authored-alert AI evaluation against the approved public profile
  Visitors may manually request a session-owned fictional demonstration.
  Model results are separate from deterministic scores and require human review.
  This document maps behavior to PHPUnit tests; no Gherkin runtime is installed.

  Rule: Only pinned public authored inputs are eligible

    # tests/Feature/DemoEmailNormalizerTest.php
    Scenario: Normalize all twenty approved messages without exposing transport fields
      Given the approved authored-demo-v2 manifest and public-profile-v2
      When the real MIME and JSON normalizer reads the corpus
      Then every source has its approved decoded-body hash and independent input hash
      And omitted facts remain null
      And changed hashes and adversarial sources are rejected

    # tests/Feature/BoundedDemoMailboxTest.php
    Scenario: Select newest unique eligible sources within the fixed UID window
      Given a controlled EXAMINE and BODY.PEEK transport with UID holes and duplicates
      When discovery examines the last one hundred numeric UID positions descending
      Then it selects no more than twenty unique approved sources
      And oversized metadata prevents body retrieval
      And malformed sources increment explicit rejection counts
      And a UIDVALIDITY change invalidates the entire selection

  Rule: Paid work requires atomic reservations and durable identity

    # tests/Feature/DemoAiConcurrencyTest.php
    Scenario: Actual MariaDB lock contention cannot exceed the daily budget
      Given concurrent PHP processes waiting on the same MariaDB ledger lock
      And only one full-run reservation fits the remaining daily ceiling
      When their admission transactions compete
      Then only one new run reserves 75680 micro-USD
      And duplicate request identities return the same run
      And database queue dispatch waits for the outer transaction to commit

    # tests/Feature/DemoAiLedgerTest.php
    Scenario: Retry and redelivery never duplicate an attempt allocation
      Given one admitted run reserves both possible attempts for each item
      When a complete schema-invalid result is retried
      Then retry waits at least thirty seconds within the original deadline
      And at most two uniquely numbered attempts exist for that item
      And redelivery cannot redispatch an in-flight attempt

    # tests/Feature/DemoAiLedgerTest.php
    Scenario: Uncertainty survives midnight, process loss, disablement and retention
      Given a potentially billed provider attempt has no reconciled outcome
      When its lease expires or its generated content is purged
      Then its maximum attempt liability remains reserved
      And further paid dispatch remains blocked
      And evidence-referenced reconciliation cannot restart evaluation
      And a response crossing UTC midnight requires evidence of its actual dispatch day
      And a proven unsent zero-cost cancellation does not freeze accounting
      And later settlement or recovery cannot extend the original retention deadline

    # tests/Feature/DemoAiLedgerTest.php
    Scenario: Preserve successful results in an honest partial run
      Given one item succeeds and another fails or expires
      When the run finalizes
      Then successful immutable results remain available
      And final counts and the partial state reflect actual outcomes
      And an empty successful selection completes without a provider request

  Rule: Provider output is validated independently of structured-output promises

    # tests/Feature/OpenAiInputTokenBoundTest.php, ProcessDemoAiTest.php, DemoAiLedgerTest.php
    Scenario: Gate inference with one request-bound server count under the existing provider fence
      Given counting policy evidence is explicitly supplied only as an offline fixture
      When the real counting adapter receives controlled HTTP count responses
      Then all twenty actual authored requests include instructions, profile, schema and input
      And exactly eight thousand input tokens may proceed but eight thousand and one may not
      And Unicode, multilingual and adversarial content remains unchanged data
      And malformed counts, counting errors and request or policy changes prevent inference
      And fresh inference authorization rechecks snapshots, UTC budget, kill switch and fence
      And count and inference share the rolling thirty-operation limit and two-second spacing
      And the original job work budget bounds both operations
      And fake HTTP counts are not authoritative production corpus evidence

    # tests/Feature/OpenAiEvaluationProviderTest.php
    Scenario: Exercise the actual Responses adapter with controlled HTTP
      Given the token-bound contract is explicitly substituted for offline transport tests
      When the actual adapter constructs and parses a response
      Then it requests gpt-6-luna with no reasoning, tools, storage, streaming or background work
      And it validates source, schema, criteria, evidence fields and exact quotes
      And cache reads, cache writes, ordinary input and all billable output are disjoint
      And refusal, truncation, invented evidence and injection conflicts are failures, not SKIP
      And an advertised weekly maximum alone is not a mandatory minimum
      And every material unknown requires a criterion or corresponding input-field clarification

    # tests/Feature/ProcessDemoAiTest.php
    Scenario: Fail closed while the full-request token proof is unavailable
      Given production has no verified schema-and-framing-inclusive token bound
      When an admitted run reaches provider preparation
      Then it fails with demo_ai.token_bound_unverified before paid dispatch
      And only proven-unstarted allocations are released
      And this behavior does not establish completion of Slice 6.2

  Rule: Server configuration and session ownership cannot be overridden

    # tests/Feature/DemoAiRunEndpointTest.php
    Scenario: Enforce CSRF, mode, workspace, session and polling boundaries
      Given an authenticated session for the server-configured demo workspace
      When it posts a server-issued admission key
      Then duplicate submission returns the existing run
      And private configuration and source overrides are rejected
      And missing and foreign run IDs return the same not-found response
      And missing CSRF is rejected
      And status polling is limited to one request per two seconds

  @pending @slice62-blocker
  Scenario: Prove the hard token ceiling for the entire actual provider input
    Given operator-reviewed authoritative evidence explicitly establishes zero-charge counting
    And counting-specific data handling is established and acceptable before any authenticated count
    And an approved exact server-side count or documented conservative full-request bound
    When approved instructions, profile, schema, input and provider framing are counted
    Then every permitted dispatch is proven to fit at most eight thousand input tokens
    And exact boundary, multilingual and adversarial payload tests prove the bound
    And all twenty actual constructed corpus requests have authoritative count evidence
    And counting failures and request changes after counting reject before inference
    And controlled count stubs alone do not establish production acceptance
    # Actual 2026-10-29 evidence: docs/runbooks/phase6-readiness.md
    # Twenty frozen requests measured under one-request plus 19-request exceptions;
    # DEMO-001 reused, range 1086-1127, all pass. Production policy remains pending.
    And measured corpus counts and experimental consent do not establish zero-charge or retention policy

  @pending @slice63 @ui
  Scenario: Accessible progress and matching escaped HTML report download
    Then the stored read-only report and download agree across refresh and expiry

  @pending @slice64 @live
  Scenario: Authorized integrated staging, owner delivery and release acceptance
    Then actual demo PEEK flag preservation and inference usage are verified
    And one operator-only allowlisted owner delivery is verified separately
    And the exact release candidate passes live disable and recovery acceptance
