@phase4 @draft
Feature: Review and enrich opportunities in an accessible workspace dashboard
  In order to make informed decisions from supported job alerts
  As the owner of a workspace
  I want a ranked review queue, explicit evidence, and a record of my own judgment

  # Phase 3 engineering and the first real calibration are complete.
  # The READY report covered 30 reviewed imports: machine skip rate 3.33%,
  # false-negative rate 9.09%, and targets_met=false. No feasibility GO is claimed.
  # Phase 4 proceeds under Marcel's explicit portfolio-demo decision; these
  # synthetic acceptance scenarios do not establish successful calibration.

  Background:
    Given the application uses a private review deployment
    And I am signed in as the user assigned to workspace "A"
    And another user is assigned to workspace "B"
    And the server selects the synthetic demonstration profile
    And that profile has these scoring settings:
      | setting               | value                                         |
      | minimum_hourly_usd    | 20.00                                         |
      | preferred_skills      | django, project management, quality assurance |
      | minimum_client_rating | 4.50                                          |
      | skill_match_weight    | 40                                            |
      | rate_weight           | 30                                            |
      | payment_weight        | 20                                            |
      | rating_weight         | 10                                            |
      | skip_below            | 35                                            |
      | apply_at              | 70                                            |

  Rule: Private data requires an authenticated assigned workspace

    @critical @authentication
    Scenario: Protect the private review journey
      Given I have signed out
      When I request the review queue page
      Then I am redirected to the login page
      When I request the review queue JSON
      Then the response status is 401
      And no opportunity data is returned
      When I sign in with the provisioned account
      Then my session identifier is regenerated
      And I can view only the assigned workspace
      When I log out
      Then the session is invalidated
      And a subsequent private JSON request returns 401

    @critical @security
    Scenario Outline: Isolate every opportunity operation
      Given an opportunity and its evaluations belong to workspace "B"
      When I attempt "<operation>" using that opportunity while assigned to workspace "A"
      Then the response status is 404
      And it does not disclose whether the foreign record exists
      And no evaluation, enrichment, review, or review pointer is changed

      Examples:
        | operation              |
        | open the detail page   |
        | fetch detail JSON      |
        | evaluate the email     |
        | save manual enrichment |
        | save human feedback    |

  Rule: The queue exposes ranked saved suggestions without hiding new or skipped work

    @smoke @queue
    Scenario: Rank and filter the saved review queue
      Given workspace "A" has these current displayed contexts:
        | job | suggestion | score | posted_on  | missing    | review     |
        | U   | UNSCORED   | null  | 2026-09-10 | unassessed | unreviewed |
        | A   | APPLY      | 100   | 2026-09-10 | none       | unreviewed |
        | B   | APPLY      | 70    | 2026-09-11 | none       | unreviewed |
        | C   | MAYBE      | 70    | 2026-09-11 | hourly_max | unreviewed |
        | D   | MAYBE      | 60    | 2026-09-10 | none       | reviewed   |
        | E   | SKIP       | 30    | 2026-09-11 | none       | unreviewed |
      When I open the queue with default filters
      Then the order is "U, A, B, C, D, E"
      And skipped opportunities remain visible
      And no workspace "B" opportunity is included
      When I set recommendation to "MAYBE" and missing to "present"
      Then only job "C" is shown
      When I clear the filters and set review to "reviewed"
      Then only job "D" is shown
      And the filter state survives opening a detail page and returning
      Given 20 additional synthetic opportunities are in workspace "A"
      When I clear all filters and open page 1
      Then exactly 25 opportunities are returned
      And page 2 contains the remaining one without duplication
      When no opportunity matches my selected filters
      Then an empty-state explanation and a Clear filters action are available

    @critical @evaluation
    Scenario: Evaluate an unscored opportunity explicitly
      Given an imported opportunity has a known hourly maximum of "40.00" and all other demo signals are positive
      And it has no evaluation under the active profile
      When I open its queue row and details
      Then it is labeled "UNSCORED"
      And no scoring or feedback record is written
      When I activate Evaluate
      Then one email evaluation recommends "APPLY" with score 100
      And the opportunity's review pointer selects that evaluation
      When I activate Evaluate again without changing the inputs or profile
      Then the same immutable evaluation is returned
      Given the imported hourly maximum changes to "10.00"
      When I activate Evaluate
      Then a second email evaluation recommends "SKIP"
      Given the imported hourly maximum returns to "40.00"
      When I activate Evaluate
      Then the original evaluation is reused and selected
      And the queue shows its "APPLY" suggestion
      And there are still only two email evaluations

    @critical @explanation
    Scenario: Explain uncertain evidence
      Given an hourly USD opportunity has a preferred skill, verified payment, and rating "4.90"
      And its hourly maximum is unknown
      When I inspect its evaluated details
      Then the email suggestion is "MAYBE" with score 70
      And all four rule contributions and their reasons are visible
      And "hourly_max" appears as missing information
      And the displayed basis is "Email evidence"
      And the page explains that suggestions require human judgment
      And I am reminded to check full scope, credible delivery capability, and 20 hours per week
      And a valid canonical listing link is available only as a deliberate user action

  Rule: Human judgment and confirmed details preserve the original email evidence

    @critical @feedback
    Scenario: Record an independent human decision
      Given the displayed email evaluation recommends "APPLY"
      And there is no manual enrichment
      When I submit human label "SKIP" without a disagreement reason
      Then validation fails with response status 422
      And no review is written
      When I submit "SKIP" with reason "fit", outcome "not_applied", synthetic notes, and sample kind "demo"
      Then one current review is saved
      And the original evaluation and opportunity are unchanged
      When I submit the identical review again
      Then the same review remains unchanged
      And its review and update timestamps remain unchanged after time advances
      When I explicitly revise the feedback
      Then only the current review changes
      And the page continues to distinguish my judgment from the machine suggestion

    @critical @evaluation @history
    Scenario: Save against the effective evaluation displayed by the dashboard
      Given a Phase 3 evaluation matches the active profile, engine, and current inputs
      And the opportunity has no selected review evaluation
      When I open the opportunity details
      Then that newest eligible evaluation is displayed without changing the review pointer
      When I save confirmed details and human feedback against that displayed evaluation
      Then both writes succeed against the displayed context
      And the review pointer remains unchanged
      When I submit a different historical evaluation
      Then the response status is 409
      And no new enrichment or changed feedback is persisted

    @critical @enrichment
    Scenario: Re-evaluate confirmed details without rewriting the email
      Given an hourly USD email evaluation has a preferred skill, verified payment, and rating "4.90"
      And its hourly maximum is unknown
      And the email suggestion is "MAYBE" with score 70
      When I paste a synthetic full description and explicitly confirm hourly_max as "40.00"
      Then a manual enrichment revision is saved with its normalized inputs and result
      And the re-evaluation is "APPLY" with score 100
      And the queue displays "Your confirmed details" as its basis
      And both the email and manual suggestions remain available on the detail page
      And the opportunity's imported hourly maximum remains unknown
      And the original email evaluation remains "MAYBE" with score 70
      And no marketplace request is made
      When I record human label "APPLY" for the enriched context
      Then a reason explaining disagreement with the original "MAYBE" is required

    @critical @provenance
    Scenario: Keep prose separate from scoring inputs
      Given an evaluated opportunity is missing its hourly maximum
      When I paste a full description that mentions a budget and includes HTML-like text
      And I submit no structured overrides
      Then the description is stored as user-supplied plain text
      And the score and recommendation are identical to the base evaluation
      And the hourly maximum remains unknown in the scoring input
      And the rendered description does not execute scripts or load embedded resources
      And the page explains how explicitly confirmed fields affect scoring

    @critical @history
    Scenario: Preserve successive manual contexts
      Given an email evaluation has no enrichment
      When I save manual payload "A" with an expected enrichment of null
      Then revision 1 is created
      When I retry that identical submission
      Then revision 1 is returned unchanged
      When I save different payload "B" against revision 1
      Then revision 2 is created
      When I deliberately restore payload "A" against revision 2
      Then revision 3 is created and displayed
      And revisions 1 and 2 retain their original text, inputs, and results
      And a review of revision 2 is not treated as a review of revision 3

    @critical @stale
    Scenario Outline: Refuse a stale editing context
      Given I opened an evaluated opportunity and its current manual context
      And "<change>" occurs before I submit
      When I attempt to "<operation>" using the previously displayed context
      Then the response status is 409
      And no new enrichment or changed feedback is persisted
      And the UI preserves my unsaved input and explains that the evidence changed

      Examples:
        | change                             | operation           |
        | the normalized email inputs change | save enrichment     |
        | the active profile version changes | save enrichment     |
        | another manual revision is saved   | save enrichment     |
        | the normalized email inputs change | save human feedback |
        | the active profile version changes | save human feedback |
        | another manual revision is saved   | save human feedback |

    @critical @calibration
    Scenario: Preserve email-only calibration semantics
      Given a personal-profile email evaluation recommends "SKIP"
      And confirmed manual details produce an enriched suggestion of "APPLY"
      When I record human label "APPLY", a valid disagreement reason, and genuine sample provenance
      And the existing calibration command reports a cohort containing the base evaluation
      Then that sample contributes one false negative against the original email suggestion
      And the enriched suggestion is not substituted for the email prediction
      And the real report still needs at least 30 complete eligible reviews and both human classes
      And synthetic reviews never establish calibration success

  Rule: Private inputs and the public demonstration have explicit boundaries

    @critical @validation
    Scenario Outline: Reject unsafe or excessive input
      Given I am editing an authorized opportunity
      When I submit enrichment with "<invalid_input>"
      Then the response status is <status>
      And no enrichment is saved
      And the response contains no private path, request body, SQL, or exception text

      Examples:
        | invalid_input                              | status |
        | a client-supplied workspace_id             | 422    |
        | a client-supplied profile path or result   | 422    |
        | a request body larger than 64 KiB          | 413    |
        | a description longer than 20000 characters | 422    |
        | a rating above 5.00                        | 422    |
        | a non-boolean payment verification value   | 422    |
        | an unrecognized structured override key    | 422    |

    @smoke @demo
    Scenario: Run the synthetic demo without credentials
      Given the application was started in demo mode with a separate synthetic database
      And I have no authenticated session
      When I activate Start demo
      Then I enter the fixed seeded workspace without entering credentials
      And the interface identifies all data as synthetic
      And I can review a queue and re-evaluate an allowlisted preset
      And demo feedback is always stored with sample kind "demo"
      And arbitrary descriptions, notes, override bodies, and real provenance are rejected
      And no request parameter can select a different demo user or workspace
      And no mailbox or marketplace request occurs
      Given instead the application was started in private mode
      When I request either demo-entry route
      Then the response status is 404

  Rule: The browser journey works with a keyboard and reports failures honestly

    @smoke @browser @accessibility
    Scenario: Complete the browser review journey by keyboard
      Given the built application is serving a synthetic test dataset
      When I navigate from the queue to details using only the keyboard
      And I confirm one structured field and save a re-evaluation
      And I record a human decision with its required reason
      Then focus remains visible and follows the logical reading order
      And controls have accessible names and associated help or errors
      And a polite status region announces the successful saves
      And the saved context remains after reloading the page
      And the primary controls remain usable at 320 CSS pixels and 200 percent zoom
      And the browser has made no request outside the local application origin

    @browser @errors
    Scenario: Keep an unsuccessful save visibly unsaved
      Given I have entered valid unsaved feedback
      When its save request fails or the session expires
      Then the UI shows an actionable failure message
      And it does not display a saved confirmation
      And my draft remains available in memory for correction or copying
      And it is not written to browser persistent storage
      And a retry is enabled only after the failed request has completed
