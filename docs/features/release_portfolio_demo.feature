@phase5 @portfolio
Feature: Release a sanitized and reproducible portfolio demonstration
  In order to show the accepted demo without publishing private opportunity data
  As the release operator
  I want a verified candidate, host acceptance, and an explicitly approved release

  # These scenarios map to PHPUnit, Playwright, Node release tests, and operator
  # checks in the Phase 5 project sheet. This file is not executed by a Gherkin runner.
  # The first real calibration missed both targets; publication is not a feasibility GO.

  Rule: The demonstration and its limits are repeatable

    @demo @smoke
    Scenario: Launch the documented synthetic demo
      Given a clean checkout with locked dependencies and a disposable demo database
      When I follow the documented setup and enter the synthetic demo
      Then the review queue and detail journey work with two isolated workspaces
      And no real alert data or marketplace credentials are required

    @report @security
    Scenario: Report workspace-scoped operational evidence
      Given two workspaces with separate mailbox, parser, and review histories
      When I request the aggregate summary for one explicit workspace
      Then its time windows and current queue suggestion counts use only that workspace
      And unavailable state returns a safe code without private content

    @documentation @privacy
    Scenario: Explain the portfolio boundary honestly
      Given the architecture guide, threat model, synthetic screenshots, and provider notes
      When I review the material proposed for public release
      Then it describes authorized future sources without claiming an existing API adapter
      And it does not claim measured time savings, comprehensive coverage, or a feasibility GO

  Rule: Only the tested candidate can become a release

    @candidate @security
    Scenario: Prepare a traceable candidate
      Given the exact CI checkout has passed the existing quality and browser checks
      When the pinned scanner inventories installed locked dependencies and packaging runs
      Then the candidate contains approved tracked source and compiled assets only
      And its manifest identifies the full SHA, repository, run, and attempt
      And its SBOM and checksums describe the four-file candidate

    @candidate @security
    Scenario: Refuse incomplete or mismatched release evidence
      Given a candidate artifact and an operator smoke attestation
      When the CI run, latest attempt, required jobs, artifact, hashes, host smoke, or tag state is invalid
      Then publication stops before creating a release
      And no fallback build or other attempt's artifact is promoted

    @host @manual
    Scenario: Accept the exact archive on an isolated host
      Given the candidate artifact passed checksum and private-content review
      When the operator deploys its archive with a separate synthetic demo database
      Then HTTPS demo entry, queue, detail, preset enrichment, and saved feedback reload work
      And foreign-workspace access and logged-out access are denied
      And no mailbox, marketplace, or non-origin request is observed
      And the archive hash, SHA, run, attempt, UTC time, and sanitized outcome are recorded

    @rollback @manual
    Scenario: Rehearse a data-preserving rollback
      Given the candidate passed host smoke on a disposable demo deployment
      When the operator switches to previous known-good code and compiled assets
      Then existing review and enrichment rows remain unchanged
      When the operator restores the candidate
      Then those rows remain unchanged and the demo smoke still passes

    @publication @manual
    Scenario: Publish only the verified archive with operator approval
      Given the exact archive passed host smoke and rollback rehearsal
      And its CI run passed all three required checks on the candidate SHA
      When the operator dispatches the release workflow from main with matching evidence
      Then a draft targeting the candidate SHA receives the four verified assets
      And version "v1.0.0" is published without rebuilding or replacing assets
      And the tag resolves to the candidate commit

    @publication @recovery
    Scenario: Leave a partial publication for deliberate inspection
      Given release creation has started but promotion fails
      When the operator inspects the draft, tag, assets, and hashes
      Then no automated retry deletes or moves the tag or overwrites assets
      And only a matching draft may be resumed with explicit approval
