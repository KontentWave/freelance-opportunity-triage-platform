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

  @slice62 @counting-policy
  Scenario: Prove the hard token ceiling for the entire actual provider input
    Given counting uses either verified zero-charge policy or explicit recorded owner risk acceptance
    And unverified counting charges and retention are not relabelled as verified provider policy
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

    # tests/Feature/OpenAiInputTokenBoundTest.php, ProcessDemoAiTest.php, DemoAiLedgerTest.php
    Scenario: Preserve the accepted-risk decision without claiming free counting
      Given protected configuration explicitly references the owner's recorded counting risk acceptance
      And the policy-evidence hash is empty and counting still requires separate enablement
      When a counting request succeeds or fails under controlled HTTP
      Then its potentially incurred counting fee remains null and unverified in durable attempt provenance
      And settlement of known inference cost never claims to settle unknown counting fees
      And uncertain inference retains its reservation and dispatch block until evidence-referenced reconciliation
      And content cleanup and inference reconciliation preserve unknown counting fees
      And policy revocation or replacement prevents further authorized dispatch
      And the unchanged one-dollar ledger bounds modeled inference rather than unknown total provider charges

  @slice63 @ui @offline
  Rule: Submission intents and progress survive browser recovery without new evaluation

    # tests/Feature/DemoAiReportTest.php:
    # it_restores_the_server_issued_intent_and_run_without_a_second_admission
    # it_replays_an_old_tab_intent_without_admitting_again_after_the_current_version_changes
    # it_requires_a_new_intent_when_approved_inputs_change_before_the_first_submission
    # tests/Browser/demo-ai.spec.js: real browser to Laravel intake...
    # ambiguous admission retry..., a fresh intent..., admission pending...
    # fixed-coordinate preparation clicks..., idle expiry requires...,
    # renewal replaces expiry timers..., unmount cancels delayed preparation...
    Scenario: Restore a server-issued intent and its authorized run
      Given the existing demo session owns a server-issued version-bound submission key
      When double clicks, an ambiguous network response, reload or re-entry occurs
      Then admission reuses that key and returns the same run without another evaluation
      And preparing a fresh intent and submitting it require deliberate actions
      And changing approved inputs before first submission requires explicit renewal
      And pending admission is not represented as a successful evaluation
      And preparation clicks one hundred, two hundred and three hundred milliseconds apart never admit or schedule work
      And an admission activation during Retry-After is ignored rather than submitted later

    # tests/Feature/DemoAiRunEndpointTest.php:
    # it_rejects_an_expired_first_submission_and_renews_only_on_explicit_preparation
    # tests/Feature/DemoAiReportTest.php:
    # it_restores_the_server_issued_intent_and_run_without_a_second_admission
    # tests/Browser/demo-ai.spec.js: idle expiry requires..., renewal replaces...,
    # unmount cancels delayed preparation...
    Scenario: Expire an idle unsubmitted intent locally without starting work
      Given an unsubmitted server intent remains idle until its expiry
      Then the expiry notice and explicit preparation action appear without any network failure or click
      And Evaluate is unavailable until deliberate renewal
      And renewal restores keyboard focus to a separate Evaluate action
      And no cooldown or expiry callback submits admission
      And replaced timers cannot expire a newer intent
      And unmount cancels local timers and ignores late intent responses
      And the backend still rejects expired first submissions but replays existing owned runs

    # tests/Browser/demo-ai.spec.js: renders completed/partial/failed/rejected...
    # polls sequentially..., temporary failure..., restores selected runs...
    # admission rate limiting..., stops polling on 410/401/404...
    Scenario: Show actual states with bounded sequential status recovery
      Given queued and running work has actual stored counts
      When status fetches are slow, rate limited, unavailable or out of order
      Then requests never overlap and polling waits at least two seconds and Retry-After
      And three consecutive temporary failures pause until deliberate resume
      And wrong-run responses cannot replace the selected run
      And completed empty, partial, failed and rejected states are explicit
      And terminal, expired and unauthorized runs stop polling without new admission

  @slice63 @report @offline
  Rule: Screen and download use one frozen public report

    # tests/Feature/DemoAiReportTest.php:
    # it_serves_identical_escaped_self_contained_html_from_the_stored_snapshot_without_external_work
    # it_uses_historical_versions_profile_prompt_input_and_provider_provenance_not_current_configuration
    # it_does_not_rewrite_a_finalized_report_after_late_settlement_or_recovery
    # it_does_not_reconstruct_a_pre_snapshot_historical_report_during_late_settlement
    # it_reports_numeric_zero_counts_for_admission_and_a_rejected_run_without_evaluation
    # tests/Browser/demo-ai.spec.js: real browser to Laravel intake...
    Scenario: Render stored public evidence without reevaluation or unsafe HTML
      Given finalization stored a demo-report-v1 representation of inputs and validated results
      When the owning session opens or downloads the report
      Then both HTML bodies are identical and no intake or provider operation is performed
      And fictional data and Marcel's approved public profile are clearly identified
      And stored versions, profile, prompt, provider provenance and actual counts are used
      And recommendations, reasons, evidence, missing information and unevaluated items are explicit
      And alert timestamps are separate from evaluation time and seven-day expiry
      And hostile text is escaped with no scripts, remote assets, tracking or fabricated listing links
      And the download has a safe filename, defensive headers and a self-contained print stylesheet
      And stored public prompt paragraphs are visible in print without interaction
      And later accounting settlement and recovery cannot rewrite finalized report content
      And historical finalized runs without a snapshot are not reconstructed

    # tests/Feature/DemoAiReportTest.php:
    # it_denies_foreign_sessions_workspaces_private_mode_and_guests_without_record_disclosure
    # it_expires_all_report_surfaces_at_exactly_seven_days_and_purges_only_feature_content
    # tests/Browser/demo-ai.spec.js: real browser to Laravel intake..., stops polling...
    Scenario: Enforce ownership before expiry and preserve unresolved accounting
      Given only the server-configured workspace and owning demo session may inspect a run
      When a different session or workspace requests status, report or download
      Then foreign and missing records return indistinguishable not-found responses
      And session loss is explicit
      And an owned report expires at exactly seven days on every read surface
      And cleanup removes only feature content, not unrelated history or uncertain liabilities

    # tests/Browser/demo-ai.spec.js: disabled mode, keyboard...
    # temporary failure recovers..., a fresh intent...
    # tests/Browser/review-dashboard.spec.js: both existing journeys
    # Interactive Chromium visual inspection: docs/runbooks/phase6-readiness.md, 6.3 section
    Scenario: Preserve accessible private and seeded journeys
      Given live mode is disabled or unavailable
      Then the seeded queue remains clearly static and usable
      And keyboard access, deliberate-action focus and polite status announcements remain usable
      And polling never steals focus
      And narrow viewports, long content and 200 percent zoom remain readable without horizontal overflow
      And the existing private and seeded review regressions still pass

  @pending @slice64 @live
  Scenario: Authorized integrated staging, owner delivery and release acceptance
    Then actual demo PEEK flag preservation and inference usage are verified
    And one operator-only allowlisted owner delivery is verified separately
    And the exact release candidate passes live disable and recovery acceptance
