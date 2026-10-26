@phase3 @triage
Feature: Triage imported opportunities with explainable suggestions
  In order to reduce unnecessary listing reviews without hiding uncertain work
  As the owner of a workspace
  I want deterministic suggestions that remain reproducible and comparable with my judgment

  Background:
    Given a workspace named "Personal freelance work"
    And the synthetic demonstration scoring profile is valid

  Rule: Every suggestion is deterministic, explainable, and conservative about missing evidence

    @smoke @critical
    Scenario: Explain a promising imported opportunity
      Given an imported hourly USD opportunity has a preferred visible skill
      And its known hourly maximum meets the configured floor
      And its client has verified payment and a rating at the configured minimum
      When I triage the opportunity for the workspace
      Then the recommendation is "APPLY"
      And the result contains the ordered contributions "skill_match", "rate", "payment_verified", and "client_rating"
      And each contribution contains its state, points, maximum points, reason code, and explanation
      And manual review is required
      And the result reminds me to verify the full scope, credible delivery capability, and the 20-hour weekly limit

    @critical @hard-exclusion
    Scenario: Reject a confirmed rate ceiling below the configured minimum
      Given an imported hourly USD opportunity has a positive known maximum below the configured floor
      And one or more other scoring signals are unknown
      When I triage the opportunity for the workspace
      Then every scoring rule is evaluated
      And the hard exclusion is "rate.maximum_below_minimum"
      And the recommendation is "SKIP"
      And the decision reason is "decision.below_rate_floor"

    @critical @missing-data
    Scenario: Keep incomplete evidence for manual review
      Given an imported opportunity has no confirmed below-floor rate
      And at least one scoring rule is unknown
      When I triage the opportunity for the workspace
      Then the recommendation is "MAYBE"
      And the decision reason is "decision.incomplete_data"
      And each field causing uncertainty appears once in the sorted missing-field list
      And missing information is not treated as an adverse fact

    @critical @skills
    Scenario: Treat a skill mismatch as a preference signal
      Given an imported opportunity has visible skills and no hidden skills
      And no visible skill exactly matches a preferred skill
      And all other signals are positive and known
      When I triage the opportunity for the workspace
      Then the skill contribution is "not_matched" with zero points
      And the recommendation is "MAYBE"
      And the skill mismatch is not a hard exclusion

    @critical @thresholds
    Scenario: Apply deterministic score thresholds to complete evidence
      Given an imported opportunity has complete scoring evidence and no hard exclusion
      When its score equals the configured apply threshold
      Then the recommendation is "APPLY"
      When its score equals the configured skip-below threshold
      Then the recommendation is "MAYBE"
      And only a score below the skip-below threshold produces a score-based "SKIP"

  Rule: Profiles, inputs, and evaluations preserve reproducible history

    @critical @validation
    Scenario: Reject an invalid scoring profile without writing
      Given a local scoring profile violates the documented profile contract
      When I attempt to triage an opportunity
      Then the command reports only "triage.profile_invalid"
      And no opportunity query is made
      And no evaluation is written

    @critical @idempotency @history
    Scenario: Reuse an identical evaluation and preserve older versions
      Given an imported opportunity was evaluated with a normalized profile and input snapshot
      When I evaluate the same normalized opportunity with the same profile and engine again
      Then the existing evaluation is returned unchanged
      When the normalized input or profile definition changes
      Then a new evaluation is appended
      And the older evaluation and its review remain unchanged
      And the older stored snapshots reproduce the older result

  Rule: Human judgment remains explicit and workspace isolated

    @critical @feedback
    Scenario: Record and revise human judgment independently
      Given an evaluation recommends "APPLY"
      When I record the human label "APPLY" with sample kind "demo"
      Then one current review is created without changing the machine evaluation
      When I submit the identical review again
      Then the current review remains unchanged
      When I explicitly revise the human label to "SKIP" with reason "fit" and sample kind "real"
      Then the same review is updated
      And the machine evaluation remains unchanged

    @critical @security
    Scenario: Isolate evaluation, feedback, and reports by workspace
      Given an opportunity and its evaluation belong to another workspace
      When I attempt to evaluate, review, or report them using the selected workspace
      Then the operation reports only "triage.not_found"
      And no foreign evaluation or review data is returned or changed

  Rule: Calibration evidence cannot overstate product feasibility

    @critical @calibration
    Scenario: Prevent synthetic or insufficient evidence from passing calibration
      Given a selected cohort uses a demo profile or contains a demo review
      When I build its calibration report
      Then the data status is "DEMO_ONLY"
      And targets met is null
      Given instead a personal-profile cohort has fewer than 30 selections, incomplete reviews, or lacks either human class
      When I build its calibration report
      Then the data status is "INSUFFICIENT_DATA"
      And targets met is null

    @critical @calibration @boundary
    Scenario: Calculate a real-mode calibration report at the target boundary
      Given 30 distinct selected evaluations share one personal profile version and one engine version
      And every evaluation has a real human review
      And both human keep and human skip examples are present
      When exactly half of the selected evaluations are machine skips
      And the false-negative count is no more than five percent of human keeps using unrounded integers
      Then the data status is "READY"
      And targets met is true
      And the report contains the full 3 by 3 confusion matrix and aggregate counts only

    @critical @calibration @validation
    Scenario: Reject a cohort that could distort comparison
      Given a local cohort contains duplicate evaluation IDs, mixed profile versions, mixed engine versions, or repeated opportunities
      When I request a calibration report
      Then the command reports only "triage.cohort_invalid"
      And no report is produced
      And no cohort is automatically selected by machine recommendation
