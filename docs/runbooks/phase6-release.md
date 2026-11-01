# Phase 6.4 owner delivery and bounded release acceptance

## Phase 6.4 documentation checkpoint: pending release verification - 2026-11-01

**Phase 6.4 is not complete.** The remaining release operations are **not yet performed for the exact schema-v2 candidate**, not newly failed tests. The owner approved documenting this checkpoint through a separate branch/commit/PR/merge and returning to `main`; that approval does **not** authorize host changes, activation, scheduling, SMTP authentication/testing/sending, paid operations or v1.0.2 publication. Earlier dated observations below retain their historical scope; the current receipt/exception decisions supersede earlier nonreceipt and unwaived-gate blockers without erasing their evidence.

| Gate / deliverable | Current status | Remaining action |
| --- | --- | --- |
| Exact runtime candidate and protected checks | **PASSED**: `e4364291e86f576fc20fcb27890f2abf108b400f`, independent main CI `41238369861` / attempt 1; 323 PHPUnit tests / 3,645 assertions, 24 browser tests and required coverage/build/audit/inventory gates | Preserve the accepted archive SHA-256 `1e4a5695fa5fcc3d8477c3fdd1dd29ae355055aa0252e985dbd614cfa6b41dab`; documentation commits or their CI artifacts are not replacement runtime candidates |
| Live recommendation acceptance | **OWNER-APPROVED EXACT-RUN EXCEPTION**: actual partial 19/20 run `01m69tea8emstgs8ep4dwj513e`, not 20/20 success | Keep failures/validators/output unchanged and [DEMO-004 #84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84) open for the unestablished exact-evidence cause; no diagnostic AI rerun under this scope |
| Owner report receipt | **RESOLVED**: both original accepted reports have explicit owner acknowledgment recorded; received partial-report attachment matches the exact stored HTML hash | No report resend or receipt rewrite; SMTP acceptance and owner acknowledgment remain distinct evidence |
| Final disable/code rollback/restoration and actual-HTTPS acceptance | **NOT YET PERFORMED FOR THIS CANDIDATE**; earlier candidate checks do not certify schema v2. Current disabled state and preserved history were verified | Obtain fresh bounded approval, then exercise the [protected sequence, steps 11-12](#approved-sequence-checklist-checkpoint-plan-actual-execution-below), including cache/OPcache-aware actual served bytes, same additive schema/storage/history and restoration. Backup readability/checksums are not isolated restore or host-loss recovery proof |
| Opt-in activation/scheduling and v1.0.2 publication | **NOT PERFORMED / AWAITING SEPARATE AUTHORIZATION**, not failed tests | Only after all applicable acceptance passes, obtain approval and follow protected steps 13-14 with the unchanged exact accepted artifact; do not activate or dispatch the release workflow from this documentation checkpoint |
| Final completion documentation | **PENDING VERIFIED RELEASE OUTCOMES** | Record actual final smoke/rollback/restoration/activation/publication results before marking Phase 6.4 complete; this checkpoint records missing work, not completion |

Public current remains **disabled v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**. All additional run/test/send allowances are consumed. No additional AI run or report resend is required for the remaining release checks; no counting-policy inquiry is reopened. Open checkpoint PR #81 remains separate and must not be repurposed. Repository documentation integration does not synchronize or overwrite shared-original sheet/roadmap documents.

**Latest received-report verification - 2026-11-01:** bounded local inspection of the owner-supplied single EML confirms the actual **19/20 partial report attachment is byte-exact**: **107,913 bytes**, SHA-256 **`812077b286c0ab11490119a5a4e81e12eed3788405aabde037bedc89c2550c86`**. Inline HTML differs only by 1,297 LF-to-CRLF line endings; visible counts/states and all 20 items match, DEMO-004 remains failed/UNEVALUATED. Supplied receiving headers report **SPF/DKIM/DMARC pass** for this message. No raw headers/addresses/private content emitted, attachment/file altered, new network/send/AI work or release. [Exact received MIME evidence](#owner-supplied-received-report-verification---2026-11-01).

**Latest fresh confirmation - 2026-11-01, 09:05:44Z:** the owner separately approved one new plain-text public-host console confirmation. **`FOTP-confirmation-20261101-090544-43fa729b`** was SMTP-accepted at **09:05:44Z** (10:05:44 local), and the owner explicitly answered **"Received this exact subject"**. No report resend, AI work, application/receipt-table/configuration change or release. Both explicitly recorded report receipts remain valid. The owner's preceding last-seen 09:28 observation leaves the later local-curl test receipt unconfirmed; do not conflate these messages. [Exact fresh check and receipt distinctions](#fresh-public-host-confirmation---2026-11-01).

**Latest owner-receipt resolution - 2026-11-01, 09:01:46Z:** the owner found the messages in the actual protected owner mailbox, explicitly confirmed **all emails received**, and separately confirmed BOTH existing reports/authorized receipt-only recording. The implemented `--receipt` command recorded each report's OPERATOR-REPORTED receipt at **09:01:46Z**, without resending or altering SMTP acceptance/results/accounting. Apparent nonreceipt is resolved by corrected mailbox checking, not a transport-code fix; actual arrival times/downstream traces remain unknown. The exact-run **partial 19/20 exception** is approved, DEMO-004 [#84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84) remains OPEN, exact archive unchanged. Public **v1.0.1 remains disabled**; final candidate-specific disable/rollback/HTTPS acceptance and separately authorized opt-in/publication remain outstanding. [Exact final receipts and preserved gates](#confirmed-owner-receipt-and-preserved-release-boundary---2026-11-01).

**Latest owner-local curl outcome - 2026-11-01:** the owner reports that explicit `smtps://smtp.m1.websupport.sk:465`, username-only authentication with a private password prompt, bounded timeouts and CRLF completed the local plain-text upload and **the message was received**. This is confirmed **OPERATOR-REPORTED receipt for that local test only**, in the owner's different-account -> project/source-mailbox direction. It is not receipt of either report or our project-source -> protected-owner console tests. No new agent authentication/send, credential reuse, report resend, configuration change or release followed; partial-run exception/#84 and separate report-receipt gate remain unchanged.

**Latest clarification - 2026-11-01:** the owner initially checked the project/source mailbox, whereas the approved reports/console tests target the separate protected owner recipient. This mismatch was clarified; the owner then explicitly reported both console tests absent after checking that protected recipient and chose to **keep** the original recipient. The partial-report answer was a local curl timeout, not receipt evidence; its receipt stays unconfirmed. The owner's curl example selects `smtp`, not implicit-TLS `smtps`, on port 465; this command-level mismatch was verified offline without execution/authentication/send. It does not explain the existing smtps application submissions. [Exact clarification and limits](#recipient-role-clarification-and-owner-curl-timeout---2026-11-01).

**Latest differential evidence - 2026-11-01, 08:30:40Z:** owner reports Outlook successfully delivered to the same intended recipient through **smtp.m1.websupport.sk:465**, but from a different sending account. One separately approved project-source plain-text test with public EHLO **fotp.kontentwave.com** was SMTP-accepted at **08:28:00Z**; owner again answered **"Not received"**. Separately authorized bounded read-only sender-INBOX inspection found **zero matching submission/DSN messages**, without accessing unrelated bodies/folders. EHLO correction alone did not establish delivery; root cause remains unestablished. No more sends were made, no code/settings deployed, exact-run exception/#84 unchanged and public v1.0.1 disabled. [Working-path comparison, authorizations and limits](#outlook-comparison-ehlo-test-and-sender-notice-check---2026-11-01).

**Latest console-mail outcome - 2026-11-01, 08:16:02Z:** after the owner explicitly authorized **exactly one new standalone plain-text console test**, that message was SMTP-accepted through the approved source/server to the existing protected recipient, with no attachment or report resend. The owner answered **"Not received"** for this test too. No positive test/report receipt or downstream cause is established; this consumes the new single-test allowance. Exact-run 19/20 exception/#84 remains approved; public v1.0.1 stays disabled. [Authorization, actual test and limits](#separately-approved-single-console-email---2026-11-01).

**Latest decision/investigation - 2026-11-01:** the owner explicitly APPROVED an exact-run recommendation exception accepting the actual **partial 19/20** result on candidate `e4364291e86f576fc20fcb27890f2abf108b400f`, with failure history preserved and [DEMO-004 follow-up #84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84) OPEN. This does not claim 20/20 success or general accuracy. Both accepted reports still have no owner receipt; downstream cause is **NOT ESTABLISHED**. Read-only host/GitHub and offline exact-report MIME inspection left public **v1.0.1 disabled**, without another AI operation, SMTP connection/send, host change or publication. The owner approved obtaining sanitized existing sender-provider traces; evidence has not yet been supplied. [Decision, evidence and bounded next plan](#owner-exception-and-read-only-delivery-investigation---2026-11-01).

**Latest execution outcome - 2026-10-31:** schema v2 is integrated through [PR #82](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/82); its independent exact-main archive passed verification and one newly approved real acceptance run. The run finalized **PARTIAL: 20 selected / 19 succeeded / 1 failed**, so the explicitly approved **20/20 release gate FAILED**. Modeled inference settled **USD 0.004872**, zero outstanding reservations/uncertainty; counting fees remain unknown. One new partial-report email was SMTP-accepted, duplicate triggering did not resend, and the owner reports **nonreceipt**. Public **v1.0.1 remains served with all switches disabled**; no public candidate switch, new rollback rehearsal, activation or v1.0.2 publication occurred. [Exact new evidence and remaining blockers](#schema-v2-integration-and-new-bounded-acceptance---2026-10-31).

**2026-10-31 new execution scope APPROVED:** after independent offline verification, the owner approved a separate schema-v2 fix PR (preserving open checkpoint PR #81), normal protected integration, independent exact-main CI/four-file artifact verification, protected public-demo backup and inactive installation, and real loopback/SSH-tunnel acceptance with bounded dedicated scheduling. Existing 19 migrations/history and the already populated 20-message corpus must be retained; no blind migrations, reseeding or APPEND.

The new allowance is **one run**, at most 20 items/two existing-policy attempts per item (**40 counts + 40 inferences maximum**), **USD 0.075680 maximum initial modeled-inference reservation**, original **30-minute admission deadline**, and **45-minute helper window**. Existing concurrency/rate limits and USD 1/day inference-only ledger remain; count fees/endpoint retention remain unknown under the existing accepted-risk decision. Recheck all UTC liabilities before admission and stop dispatch on uncertainty; no whole-run repeat.

The owner explicitly selected **all 20 approved inputs selected and 20/20 valid successful results**, no unresolved uncertainty or obvious rubric violations, as the new recommendation acceptance gate. All isolation, real PEEK preservation, report consistency, accounting, browser and recovery criteria also apply. At most **one new finalized report email** to the existing protected sole recipient is authorized through the implemented command, with duplicate-state verification and **no old-report resend or mail retry**. Record receipt only after explicit owner confirmation. Only after all gates and owner receipt pass may cache-aware disable/data-preserving HTTPS-verified rollback lead to opt-in activation and unused v1.0.2 publication from the exact accepted archive. Failure leaves/restores disabled v1.0.1; permission denials are never bypassed.

**Independent local checks in this continuation:** 50 provider/counting tests / 1,324 assertions PASS with unreachable offline database configuration and controlled HTTP; project PHPStan, dirty Pint and whitespace checks PASS. Installed Laravel 13.36.0/Boost 2.10.3 verified; no dependency change/reinstallation. Current branch/head, all 14 modified source/evidence files, open PR #81 and protected main `19c92a463b99b8eee21469ad290399875ac08102` were reverified. No new full-suite/browser/coverage CI, host mutation, paid operation, email or release is claimed by this entry.

**Historical offline checkpoint - 2026-10-31:** the owner separately approved one one-item/one-attempt diagnostic, then a narrowly scoped **offline schema-contract fix**. The diagnostic failed exact-evidence validation for a bare field name; its modeled inference settled USD **0.000291**, with one counting fee still unknown. The local v2 schema now declares the existing allowed evidence and missing-information identifiers; focused tests/static analysis pass, but these changes are **not committed, integrated, deployed or live accepted**. Public v1.0.1 and all disabled switches remain unchanged. Further paid work, email and release are not authorized by the offline-fix decision. See [diagnostic and offline correction](#one-item-diagnostic-and-offline-schema-correction---2026-10-31).

**2026-10-30 latest outcome: offline implementation and protected integration COMPLETE; the one bounded live acceptance FAILED.** Normal artifact retrieval succeeded on resumption without an authorization bypass. The exact candidate selected 20 authored alerts, but none produced an accepted recommendation after the permitted 40 attempts. One failure-report email was SMTP-accepted; the owner reports nonreceipt. The public site was restored to v1.0.1 after an HTTPS-verified, data-preserving disabled-code rehearsal. Feature, counting, scheduler and owner delivery are disabled; v1.0.2 is not published. See [actual resumed execution](#resumed-bounded-execution---2026-10-30). Phases 1-5, previous releases, historical audits and the unsuccessful product-feasibility result are unchanged.

## Owner-supplied received-report verification - 2026-11-01

The owner supplied a single received EML rather than just its HTML attachment. It was parsed **locally and in memory only**, with a bounded input size; no raw headers, addresses, credentials or private message content were output or copied into repository evidence. The read-only attachment was not changed, extracted to disk or used to access any mailbox.

| Exact received evidence | Verified result |
| --- | --- |
| Attached report identity | `fictional-demo-01m69tea8emstgs8ep4dwj513e.html`; expected live run identity and synthetic labels present |
| Decoded attachment bytes/hash | **107,913 bytes**, SHA-256 **`812077b286c0ab11490119a5a4e81e12eed3788405aabde037bedc89c2550c86`**; exact match to stored/download/durable-delivery report evidence |
| Inline HTML versus attachment | Inline part **109,210 bytes**, hash `583dd51d321387025dc6f556736b2dcc25b3132d62918dc0f156e9cf821213c2`; **1,297 CRLFs**. Converting CRLF to LF gives byte-for-byte attachment equality, expected size/hash. This is a line-ending representation difference, not repaired AI output or missing content |
| Actual visible report | **partial / 20 selected / 19 succeeded / 1 failed**, all **20 articles** and identical inline/attachment recommendation/state pairs |
| Failure honesty | DEMO-004 displays **UNEVALUATED / failed**, not an invented recommendation; DEMO-003 displays successful APPLY after its previously recorded retry. Overall displayed successful recommendations: **9 APPLY / 5 MAYBE / 5 SKIP** |
| Executable/external content scan | Both HTML parts have zero script/iframe/object/embed tags, event-handler attributes, external resource attributes or external CSS imports/URLs. This is parser/content evidence, not a new visual browser acceptance |
| Received authentication metadata | Supplied `Authentication-Results` reports **spf=pass / dkim=pass / dmarc=pass**; raw values/identity fields were not emitted. This is evidence reported in this received EML, not independent cryptographic/DNS validation or universal authentication/deliverability proof |
| MIME parse | Two HTML parts (inline plus attachment); no top-level parser defects. No received-file modification or network operation |

This strengthens the already recorded owner receipt with exact received attachment evidence. It supports neither an original SPF/DKIM/DMARC rejection claim nor a need to change transport based on earlier hypotheses. Provider trace/actual arrival timing and DEMO-004's failed provider output remain unavailable; no root cause is invented, failure/result repaired, or 20/20/general accuracy claimed.

Confirmed report receipts, the exact-run partial exception/#84, frozen runtime archive and disabled public v1.0.1 remain unchanged. Final candidate-specific disable/data-preserving rollback/actual-HTTPS acceptance and separately approved opt-in/publication are still outstanding. No receipt record was rewritten, email resent, AI request made, candidate rebuilt or shared original updated by this offline inspection.

## Fresh public-host confirmation - 2026-11-01

After recording both explicitly confirmed report receipts, the owner requested one more checking email and reported that the last seen message was at **09:28 local**. That observation narrows the earlier broad all-email statement: **receipt of local-curl test `FOTP-local-curl-test-20261101-085307-173e32fc` remains unconfirmed**. It does not revoke the separately explicit confirmation of both existing reports; no durable report receipt was erased or rewritten.

The owner explicitly approved **exactly one new standalone plain-text confirmation**, from the existing approved project sender to the protected owner recipient, through the public-host console path with public EHLO, no attachment/report resend, AI work, configuration change or retry.

- **Subject/reference:** `FOTP-confirmation-20261101-090544-43fa729b`.
- **Actual operation:** one locked-Symfony send under explicit PHP 8.4.27, approved smtps/465, peer/name-verified required TLS, ten-second stream timeout, EHLO `fotp.kontentwave.com`, only `text/plain`.
- **SMTP acceptance:** **2026-11-01T09:05:44Z** (10:05:44 local); no repeat.
- **Actual owner answer:** **"Received this exact subject"**, OPERATOR-REPORTED. This is specific fresh-test receipt, not inference from SMTP state or an older inbox message.
- **Preservation:** no Laravel/retired-helper bootstrap, source/recipient override, delivery-table/report/accounting/migration/cron/configuration write, paid operation or public-code switch. Public current remains disabled v1.0.1.

The new one-message allowance is consumed. This confirms receipt on that exact public-host/project-sender/owner-recipient path, not universal deliverability or retrospective proof of every local test. Confirmed report receipts, partial-run exception/#84, unchanged exact artifact and final protected release boundary remain as recorded below. No further test/send, activation or publication follows automatically. Sheet/roadmap were not edited/synchronized in this confirmation entry.

## Confirmed owner receipt and preserved release boundary - 2026-11-01

### Last matching local test and destination reconciliation

The owner separately approved **exactly one new matching local curl test**, from the approved project sender to the existing protected owner recipient. The actual workspace is WSL with curl **7.81.0**. Protected identities were read through BatchMode/strict-host-key SSH into process memory; credentials/addresses were passed to curl through a private configuration pipe, not command arguments, files, environment exports or output. An offline all-protocols-disabled probe first verified the separate config/upload pipes without a connection.

At **08:53:08Z**, `FOTP-local-curl-test-20261101-085307-173e32fc` completed with **curl exit 0 / SMTP 250 / 497 uploaded bytes / TLS verification result 0**. Explicit smtps/465, ten-second connection/thirty-second total timeout, CRLF, only `text/plain`, no attachment/report, no retry. No public-host SMTP send, application/retired-helper bootstrap, AI operation, delivery-table/configuration write or recipient-inbox access occurred. A generated Message-ID hash reference was retained in execution evidence without disclosing identity values. This matching-test allowance is consumed.

The owner initially reported nonreceipt for that test too. A proposed owner-performed Outlook comparison again arrived at the project/source mailbox. Private role comparison at **08:58:48Z** then verified that the destination in the owner's successful examples is **the configured source, not the distinct protected owner**; values were not emitted. The recipient-decision form was interrupted, so no override/recipient change was authorized or made.

The owner subsequently explained that the actual owner inbox contained **all emails**. This closes the apparent nonreceipt investigation: prior answers were operator observations made before the correct mailbox was identified, not proof of transport failure. Actual individual arrival times and provider-side dispositions remain unavailable; do not claim that EHLO/MIME/DNS/filtering changes caused receipt or that there was a repaired transport defect. The local curl scheme/authentication problems belong to those separate owner-local commands, not the original accepted report submissions.

### Explicit authorization and durable receipt-only recording

The owner explicitly confirmed receipt of **both** the October 31 partial report and October 30 failure report, and approved only the existing receipt-only commands. Under explicit PHP **8.4.27** in the inactive exact candidate, disabled feature/counting/scheduler/owner-send/private-intake configuration and guarded demo workspace were checked before invocation:

| Existing report | Durable receipt result |
| --- | --- |
| `01m69tea8emstgs8ep4dwj513e`, actual partial **20 selected / 19 succeeded / 1 failed** | `--receipt=phase64.owner.receipt.20261101.partial.confirmed`; **receipt_operator_reported**, `receipt_reported_at` **2026-11-01 09:01:46 UTC**, original status remains `accepted` |
| `01m677qrdnpzan84tj7shz9jkv`, historical failed run | `--receipt=phase64.owner.receipt.20261101.failed.confirmed`; **receipt_operator_reported**, same UTC timestamp, original status remains `accepted` |

These timestamps record **owner acknowledgment**, not exact provider/inbox arrival time. References are opaque and stored hashed; no owner address, private content or credential is retained in repository documentation. The owner initially said all emails were present; the later last-seen 09:28 observation leaves the subsequent local-curl test receipt unconfirmed. Standalone-test receipt is never fabricated as a report delivery row or used to replace either immutable report.

Immediate verification compared pre/post data: **only receipt timestamp/reference fields changed**. Both stored report snapshots and every other delivery column, all **11 historical-table counts/digests**, attempts/budget/accounting/jobs, **19 migrations** and shared environment were unchanged. No SMTP send or paid operation followed the confirmation. All switches remain disabled and public current remains **v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**.

### Remaining acceptance and release boundary

- **Recommendation gate:** owner-approved exception for this exact partial run/candidate is recorded; actual failure status/history preserved. [DEMO-004 #84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84) is still OPEN, its exact-evidence cause unestablished; no validator/output repair or AI repeat.
- **Owner receipt:** both existing reports now have explicit OPERATOR-REPORTED receipt, independently of SMTP acceptance. Historical nonreceipt observations below are preserved and superseded, not deleted.
- **Runtime candidate:** unchanged **`e4364291e86f576fc20fcb27890f2abf108b400f`**, independent CI **41238369861 / attempt 1**, archive SHA-256 reverified **`1e4a5695fa5fcc3d8477c3fdd1dd29ae355055aa0252e985dbd614cfa6b41dab`**. No transport-code change or rebuilt/newer-main archive substituted; documentation baseline `830d674...` is not a runtime candidate.
- **Not executed/authorized by receipt approval:** new candidate-specific cache-aware disable/data-preserving public code rollback/restoration and actual-HTTPS acceptance, opt-in activation/scheduling, or **v1.0.2 publication**. Obtain a fresh bounded decision before these host/publication operations. Phase 6.4/Phase 6 are not marked complete by receipt alone. Keep disabled public v1.0.1.
- All separately approved run/test/send allowances remain consumed; receipt recording creates no new send/paid allowance. Counting fees/retention remain the unchanged accepted risk, not verified free or a total-charge guarantee.

Before sheet/roadmap outcome updates, guarded read-only shared-original comparisons at **09:02:28.414Z / 09:02:29.373Z** again matched saved remote baselines **`71d35f9f917f61c1fd9c7007988b73a909502b1494c4852dce3210b2329858d9` / `10ce119dc3f834b56351ee75928207cf9528024f2869755004166c347862fc21`**. Preserved local hashes were **`86669d373e5e7256f6ca4a46715c23734f3b61c63559d215808d5d3f731beb07` / `1c1015155884564d27c5f63877b5940e3c0fd052d850d4fec4d07aca5da48349`**. No original replacement, force pull, sync-state write or whole-document publication. Existing edits/PR #81 and all earlier failed evidence remain preserved.

## Recipient-role clarification and owner curl timeout - 2026-11-01

The owner supplied a failed local curl attempt (later **`curl: (28) server response timeout`**) using an account distinct from the approved project source. **That command was not executed or copied into repository evidence; account/address/credential values were not retained or reused.** Rotation of the disclosed password and no posting of its replacement were advised. This does not authorize using that different account for application tests.

Offline local curl documentation/protocol inspection verified:

- Without a scheme, the provided hostname is guessed as **`smtp`**. A probe with **all network protocols disabled** returned `Protocol "smtp" not supported or disabled`; it made no server connection.
- On an SMTP connection, `--ssl-reqd` requires TLS upgrading; dedicated port **465** expects TLS from connection start. Use explicit **`smtps://smtp.m1.websupport.sk:465`**, not scheme-less/STARTTLS-style SMTP on 465. This explains a plausible greeting/TLS stall in the owner's CLI example, not an observed downstream rejection.
- Passwords must not be embedded in chat/history commands: `--user 'SMTP_USERNAME'` can prompt privately. Bounded connection/total timeouts and CRLF message formatting are appropriate for a separately authorized local test. No such corrected command was run here.
- Our two console tests already used explicit **smtps**, TLS/peer-name verification and bounded timeout. The curl scheme problem is **not established as their failure cause**.

Read-only protected-role verification at **08:34:23Z** reconfirmed that report/console From is the dedicated source mailbox, while To is the **distinct originally approved protected owner recipient**; no address or digest was emitted.

The owner then confirmed:

1. Earlier nonreceipt checks had looked in the **project/source mailbox** (the destination of the owner's inbound curl example), not the protected report destination. Those historical answers remain operator observations but do not by themselves prove absence at our actual destination. The destination should have been reconciled before interpreting the working Outlook path.
2. After private recipient-check guidance, the owner explicitly selected **"Not received after checking protected owner mailbox"** for both console subjects. No owner inbox was accessed by the agent.
3. The owner chose **keep the original protected owner recipient**. No sender/recipient override or configuration change followed.
4. The partial-report response was a curl timeout, **not confirmation of report receipt or a clear report-mailbox observation**. No report receipt timestamp/reference is recorded.

**Subsequent owner-local success:** after selecting explicit smtps and supplying the password through curl's private prompt, the owner shared a completed **78-byte upload** and explicitly said the message arrived. No account/address/password values are reproduced. The record does not establish why earlier authentication failed, that password rotation occurred, or whether project-source outbound delivery works: this successful test still sends from the owner's different account into the project/source mailbox. Its receipt must not be recorded against either existing report or agent console-test reference.

**Scope/outcome:** no new SMTP/IMAP connection, send, mailbox read, AI operation, code/host change or publication in this clarification; all earlier allowances remain consumed. The curl CLI mistake and initial mailbox-role mismatch are established findings, but actual project-source downstream delivery remains unestablished given the subsequent console nonreceipt observations. Do not silently change the target, reuse the disclosed credential, resend accepted mail or claim that correcting the CLI command certifies application delivery. Approved exact-run 19/20 exception/#84 remains; public v1.0.1 stays disabled with report receipt and remaining release gates unresolved. Sheet/roadmap were not edited or synchronized in this clarification.

Reference: [curl SMTP guide: secure transfer](https://everything.curl.dev/usingcurl/smtp.html#secure-mail-transfer). No sensitive example was sent to this public documentation source.

## Outlook comparison, EHLO test and sender-notice check - 2026-11-01

**OPERATOR-REPORTED:** the owner successfully sent from Outlook to the same mailbox intended for the owner reports, using **smtp.m1.websupport.sk / port 465**. Authentication verdicts were unknown. Outlook's sending account differs from the approved project-source account used by console/report delivery; its receipt establishes a working destination/path, not equivalence of sender identity, authentication, client/host route or message construction. The private account address is not reproduced here.

Read-only locked-factory/shared-configuration inspection at **08:25:40Z** independently reconfirmed the isolated default **EHLO `[127.0.0.1]`** versus normal public configuration **`fotp.kontentwave.com`**, with no SMTP connection. This is a concrete configuration difference, not established causation.

The owner explicitly approved **one new differential console test**: same protected project sender, recipient, SMTP endpoint/TLS/timeout and plain-text body template; supply public EHLO only, with a fresh unique subject/reference and generated message headers. No alternative sending account, attachment, report resend, persistent code/configuration change, feature activation or retry.

- **Unique subject:** `FOTP-console-test-20261101-082800-44d6f737`.
- **Actual send:** one direct locked-Symfony invocation, **SMTP-accepted at 2026-11-01T08:28:00Z** (09:28:00 local), explicit EHLO `fotp.kontentwave.com`.
- **Owner answer:** **"Not received"**, OPERATOR-REPORTED; no positive receipt.
- Sent-object identifier SHA-256 reference retained in operator execution evidence; actual identifier, addresses, credentials and raw debug/reply were not emitted.
- No Laravel bootstrap/retired helper, report/delivery/accounting write, AI operation or public configuration/code switch. This new single-test allowance is consumed.

**Interpretation:** selecting the normal public EHLO did not produce owner-confirmed receipt in this test. Do not claim EHLO is the root cause, that a transport patch resolves nonreceipt, or that SMTP acceptance proves delivery. Outlook and project-source paths still differ by sending account and potentially client/host/authentication behavior. Report-specific MIME is not necessary for the observed nonreceipt, but original report filtering is not disproved.

The owner then separately approved a **bounded read-only dedicated project SENDER mailbox check** for delivery-status/bounce notices concerning the two console subjects or original report subject. Approved scope excludes recipient/personal inboxes, unrelated folders/bodies, flag changes, sends and AI work.

At **08:30:40Z**, explicit PHP 8.4.27 and certificate-validated IMAP SSL/993 used the existing approved dedicated source credentials, debug/UID caching disabled and connection/work deadlines. Only **INBOX** was EXAMINEd; server-side search was restricted to the last **100 numeric UID positions**, since **30 October**, and the exact submission reference/subject matches (at most ten returned matches). BODY.PEEK delivery-status-part access was available only for matching DSNs, not unrelated message bodies.

**Actual outcome:** **0 matching messages / 0 delivery-status parts read**, stable UIDVALIDITY, no matched persistent-flag changes, **0 unrelated bodies / 0 SMTP connections / 0 sends**. Connection reset closed the read-only session without CLOSE/EXPUNGE. No other mailbox folder was inspected. This is bounded absence of matching evidence, **not proof that no bounce exists elsewhere or that final delivery occurred**. As approved, investigation stopped instead of sending further tests.

**Remaining blocker:** downstream disposition and actual owner receipt remain unestablished. Existing sender-provider trace plan is still approved but no trace supplied. Any further test, broader mailbox/provider access or transport/host change needs another concrete bounded decision; no use of the owner's different sending-account credentials is authorized. No speculative patch/deployment/release is claimed. The exact runtime archive, 19 migrations/history/reports and approved partial-run exception/#84 remain preserved; public v1.0.1 stays disabled.

Guarded read-only original comparisons at **08:31:04.524Z / 08:31:05.317Z** again matched saved sheet/roadmap remote baselines, preserving local hashes **`dc1a837b2bfff682615c6fa1abdb7690970e7ab391ffa87571136daa387f8ea6` / `993baf6ed586bf5f0103a105f2c7701dab210c67d44abe7fb492570d43ce4fa6`** before these narrow updates. No original replacement, sync-state update or shared publication; earlier entries retain their dated scopes.

## Separately approved single console email - 2026-11-01

Following the earlier read-only investigation, the owner requested an ordinary console email and explicitly confirmed this bounded plan: **one standalone plain-text email** through the approved project sender/SMTP server to the existing protected sole recipient, no attachment, AI processing, report resend, durable delivery-state reset or feature activation; **no retry on uncertainty**. This is a new single-test allowance, not renewal of either consumed report-send allowance or any paid-run allowance.

Read-only preflight at **08:15:37Z**, using BatchMode/strict-host-key SSH and explicit PHP **8.4.27**, reverified the approved source/recipient/password identities against their protected files, distinct recipient, authorized endpoint/smtps, disabled retired switches and unchanged public current. No values were exposed.

Exactly one direct console invocation used the inactive candidate's locked Symfony Mailer, **smtp.m1.websupport.sk:465 / smtps**, TLS required with explicit peer/name verification and ten-second stream timeout. It constructed only `text/plain`, one From/To, no HTML or attachments, and retained the same default EHLO `[127.0.0.1]`. The retained configuration was parsed for the approved mail identity only: no Laravel application bootstrap, retired-helper execution, environment export/reenablement, scheduler or AI call occurred.

| Actual test evidence | Outcome |
| --- | --- |
| Unique subject/reference | `FOTP-console-test-20261101-081602-79ae6312` |
| SMTP outcome / UTC | **ACCEPTED, 2026-11-01T08:16:02Z** (09:16:02 Slovak local time); one `send` invocation, no repeat |
| Sent-object identifier provenance | A SHA-256 reference to the returned identifier was recorded in the operator execution evidence; no actual identifier/address, raw reply or transport debug was emitted |
| Explicit owner answer | **"Not received"**, OPERATOR-REPORTED; no positive receipt |
| Protected application state | Public current remains v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`; no application code/configuration, report, `demo_ai_deliveries`, AI/accounting, migration or cron change |

**Interpretation:** owner-reported nonreceipt also occurs for ordinary plain text without the report HTML/attachment. Those report-specific properties are therefore not necessary to reproduce the observation; this does **not** establish why either original report failed to arrive or rule out additional report filtering. SMTP acceptance remains distinct from final delivery/receipt. Sender identity/EHLO, downstream authentication/filtering/routing and recipient behavior remain hypotheses without verdict evidence.

The new test allowance is now consumed. Do not resend this test or either accepted report, reset durable state, enable public features or claim receipt. Further authentication/test/send or any host/transport change needs a separate bounded decision. The earlier sanitized-existing-trace plan remains approved, but no provider trace has been supplied. Receipt and remaining protected acceptance/release gates still block Phase 6.4/v1.0.2; no AI rerun is needed or authorized.

Before narrow sheet/roadmap outcome updates, guarded read-only shared-original comparisons at **08:16:50.495Z / 08:16:46.203Z** again matched the unchanged saved remote baselines. Preserved pre-edit local hashes were **`b728a59d1f82904a85cb40fe094d141914db97a860ca271605676206998ad979` / `ee11acaa7f3e2d70cdf53d7b366133ee62883bd9aa4641aa26f9062e31ed046d`**. No document replacement, force pull, sync-state write or shared publication occurred. The earlier no-send investigation below remains a dated historical scope, not a claim that this separately approved test never happened.

## Owner exception and read-only delivery investigation - 2026-11-01

### Explicit owner decision and exact candidate

The owner selected **APPROVE**, not merely proposed, the following bounded revision: accept run **`01m69tea8emstgs8ep4dwj513e`** on **`e4364291e86f576fc20fcb27890f2abf108b400f`** as an exception to the original 20/20 recommendation gate, retaining its actual **20 selected / 19 succeeded / 1 failed** state and all failure evidence, with a focused DEMO-004 tracking issue. **Receipt and every other release gate remain independently required.** [Issue #84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84) was created and verified OPEN. DEMO-004's two `demo_ai.evidence_invalid` failures remain causally undiagnosed because failed raw responses were not retained; no validator/output repair, new AI diagnostic or accuracy claim follows this exception.

Initial checkout was clean **`main` / `830d67470d245384cad2e3ad2d8fec3ee1f11686`**, the merged documentation-only PR #83. Its delta from the runtime candidate contains documentation only; it is **not a substitute candidate**. PR #82's merge, PR #83's merge and OPEN checkpoint PR #81 at `4cac97bf0f49acb86607ab6c12d53d02917346a9` were reverified without repurposing the checkpoint.

Independent push CI **41238369861 / attempt 1** still targets the exact runtime merge and has all three required jobs successful. Its existing logs reconfirm **323 tests / 3,645 assertions**, **24 browser tests**, and **90.48% / 93.64% / 96.90%** coverage; no new suite execution is claimed. Artifact **13056850549** remains unexpired with the recorded ZIP digest. Host four-file strict checks and direct archive hashing reconfirm application SHA-256 **`1e4a5695fa5fcc3d8477c3fdd1dd29ae355055aa0252e985dbd614cfa6b41dab`**. No archive rebuild or newer-main runtime substitution occurred.

### Verified delivery evidence and its limits

Inspection used BatchMode/strict-host-key SSH and explicit **`/usr/bin/php8.4` 8.4.27**. Protected configuration was parsed in memory; the application/retired helper was not booted or run. MariaDB reads used the verified demo-mode/`_demo` guard and a **read-only transaction**, rolled back without writes. Credential/address/digest values, raw transport dumps and private content were not emitted.

| Evidence | Verified observation / limit |
| --- | --- |
| Current durable delivery rows | Old run `01m677qrdnpzan84tj7shz9jkv`: `accepted`, **2026-10-30T20:33:23Z**; new run `01m69tea8emstgs8ep4dwj513e`: `accepted`, **2026-10-31T20:34:09Z**. Both have null failure code and null `receipt_reported_at`; retained status/receipt metadata agrees. No delivery command, recovery, receipt write or duplicate trigger was invoked in this investigation. |
| Snapshot/HTML identity | New database snapshot equals retained `new-report.json` and its durable canonical report hash. Offline rendering with the unchanged candidate view/lock reproduced exactly **107,913 bytes**, SHA-256 **`812077b286c0ab11490119a5a4e81e12eed3788405aabde037bedc89c2550c86`**, matching durable delivery HTML. Original immutable report also matches retained evidence. |
| Accepted transport versus receipt | `OwnerReportDelivery::send` sets acceptance only from a non-null Laravel `SentMessage`. Locked Symfony SMTP expects **250 after terminating DATA**, not just accepted RCPT. This establishes submission acceptance under the implemented path, not downstream delivery, filtering disposition or owner receipt. The original reply text is unavailable. |
| Sender/envelope/configuration | Retired configuration still selects authorized **smtp.m1.websupport.sk:465 / smtps** with credentials present. From is valid, equals authenticated SMTP username and the approved dedicated source; protected source/recipient/key identities match their four owned regular 0600 files in the 0700 operator directory. Sole recipient remains distinct and exact allowlist comparison passes. Values were not exposed; no SMTP login/test occurred. Without explicit Sender/Return-Path, Symfony derives MAIL FROM from From; no CC/BCC or global recipient override is configured by the isolated builder. |
| MIME construction, offline only | Exact report is an HTML-only body plus one byte-identical `.html` attachment: `multipart/mixed`, quoted-printable HTML, base64 attachment, no `text/plain`. A non-sent specimen with `.invalid` placeholder addresses measured **262,184 bytes**. This is not a captured original wire message or its exact header size. Attachment/content filtering or client clipping remain hypotheses, not demonstrated causes of nonreceipt. |
| EHLO and signing | The isolated builder omits `local_domain`, so the locked factory retains Symfony's **`[127.0.0.1]`** default rather than the normal mail configuration's APP_URL-derived domain. The application adds no DKIM signature. Neither observation proves rejection; provider-side signing and recipient SPF/DKIM/DMARC verdicts are unverified. Do not change transport code based on these hypotheses alone. |
| Missing downstream provenance | Delivery schema/code retain no SMTP Message-ID, provider queue ID, reply or raw debug; the returned sent object is discarded after acceptance classification. Symfony generates a header ID and can parse some queue replies, but the actual original IDs were not retained and cannot be invented or regenerated retrospectively. The available application log has zero matching lines in the two acceptance-minute windows; no rejection/filter verdict was found there. Provider traces, bounces, recipient routing/filter state and actual final delivery remain unavailable. |
| Owner observation | Retained protected observation says `not_received` (OPERATOR-REPORTED), no positive receipt and no additional send. Approval of this investigation/exception is not receipt evidence. No personal/recipient inbox, unrelated folder or provider console was accessed. |

At **08:06:45Z**, the new run still had **22 known attempts / 4,872 micro-USD settled**, unchanged three failed-attempt codes; zero active runs/jobs/in-flight or uncertain attempts/outstanding inference reservations. All **11 original historical-table counts/digests**, original report, shared environment and recovery SQL/environment/storage checksums match retained evidence. All **19 applied migration names** match the inactive candidate; no migration/reseed/reset/down operation was run. Recovery readability/checksums do not prove an isolated restore or host-loss recovery.

Shared environment and retired overlay remain disabled for feature/counting/scheduler/owner delivery/private intake; both releases are configuration-uncached, candidate shared links remain correct, active helper is absent and port 8014 has no listener. No retired overlay/helper was reenabled. Public `current` remains **v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**, its tag unchanged. Read-only actual HTTPS retrieval returned login 200 and matching historical v1.0.1 CSS/JS hashes; no authenticated browser write or new rollback rehearsal is claimed.

Final read-only check at **08:10:25Z** explicitly confirmed the original absent crontab (zero entries), not merely suppressed listing output, and unchanged public current. Documentation whitespace validation passed; no application code changed or new PHPUnit/browser execution was needed.

### Approved bounded next plan; evidence still pending

The owner selected **APPROVE** for obtaining **owner-supplied sanitized sender-provider traces** for only the two already accepted submissions, using their exact UTC timestamps and narrow **plus/minus five-minute** correlation windows. This is approval of evidence gathering, **not provision of evidence**: the accompanying clarification supplied no trace or receipt.

No sample email or private inbox export is needed. The owner can ask the sender's provider/support, using protected project sender/recipient identities privately:

> Please trace the two existing project SMTP submissions accepted at 2026-10-30 20:33:23 UTC and 2026-10-31 20:34:09 UTC, within five minutes either side. Provide a sanitized disposition: whether the queue handed off, deferred, bounced, quarantined or rejected them; any downstream SMTP status/classification; and any available authentication, filtering or routing verdict. Use a redacted correlation reference. Do not include credentials, full addresses, private message content or raw transport dumps.

Assess the supplied trace offline, distinguish sender handoff from final receipt, and propose any necessary new scope separately. **No SMTP authentication/test/send, resend/state reset, AI work, host/configuration change, recipient-inbox access or publication is authorized by this plan.** If a source mailbox or provider-console inspection becomes necessary, obtain narrowly scoped authorization first. Any transport-code change requires normal protected checks, a new exact-main artifact and applicable acceptance reconsideration; the current archive cannot certify that change.

**Precise blocker:** owner receipt and downstream disposition remain unestablished. The recommendation exception alone does not complete Phase 6.4. Keep disabled public v1.0.1; do not proceed to conditional disable/data-preserving rollback/actual-HTTPS acceptance, opt-in or v1.0.2 until all remaining revised gates and explicit receipt pass. Both paid-run/email allowances remain consumed; counting-policy uncertainty stays accepted risk, not a new inquiry or free/total-charge guarantee.

Before sheet/roadmap edits, guarded read-only shared-original comparisons at **08:02:16.390Z / 08:02:12.928Z** matched saved remote baselines **`71d35f9f917f61c1fd9c7007988b73a909502b1494c4852dce3210b2329858d9` / `10ce119dc3f834b56351ee75928207cf9528024f2869755004166c347862fc21`**. Preserved local hashes **`86fc09cc2c1e619047335509bbd7837e8d6945b03a165abf85e0ec91cb18b2a3` / `754ab7be508f03a6b5d99d97ac3618d721c099a575e81e11c467410b6649cec7`** were rechecked immediately before editing. No local replacement, force pull, sync-state write or whole-document publication occurred. Dated failures below remain historical evidence, not rewritten passes.

## Schema-v2 integration and new bounded acceptance - 2026-10-31

### Exact protected integration and independent archive

All 14 existing modified source/evidence files were preserved. Separate branch `fix/phase64-schema-contract-v2` retained implementation commit `8ba67e0` and documentation/approval commit **`8c4e21db9ea25a91126f08faceafd5e704cedf85`**. [PR #82](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/82) passed [final-head CI 41238051695](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41238051695), then normal protected squash integration produced **`e4364291e86f576fc20fcb27890f2abf108b400f`**. An initially mistyped match-head argument was rejected before merging; the verified exact head was subsequently supplied. No protection or runtime denial was bypassed. Checkpoint PR #81 remains OPEN at its unchanged `4cac97bf0f49acb86607ab6c12d53d02917346a9`.

VERIFIED independently for that exact merge: push-to-main [CI 41238369861 / attempt 1](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41238369861) completed Quality, Tests / MariaDB 11.4 and Secret scan successfully. Its logs report **323 PHPUnit tests / 3,645 assertions**, **24 browser tests**, **90.48% overall / 93.64% parser-domain / 96.90% triage** coverage, locked installation/build, audits and release/inventory validation. These are fresh new-revision checks, not the original candidate's historical results.

Only unexpired artifact `release-candidate-e4364291e86f576fc20fcb27890f2abf108b400f-1`, ID **13056850549**, was retrieved. GitHub ZIP digest `ea6f7dfea1fac6ef6861d2d21af3589ec06f333d09b30a840cb72520189aecd3` is distinct from the application archive. Exact four-file checks, strict checksums/manifest, safe archive inventory and dependency inventory against both archived lockfiles passed locally; transferred hashes matched on the protected host.

| File | Verified SHA-256 |
| --- | --- |
| `freelance-opportunity-triage-platform-e4364291e86f576fc20fcb27890f2abf108b400f.tar.gz` | `1e4a5695fa5fcc3d8477c3fdd1dd29ae355055aa0252e985dbd614cfa6b41dab` |
| `candidate.json` | `3a958d37024024f91841f1a8d9a10c680d2cd944bfceb811d03d909095842b1a` |
| `sbom.cdx.json` | `1dcb9e061c1423c3cb786f6add3f69613644e1f2d228a167dcf4d3d2d22fe802` |
| `SHA256SUMS` | `120b8438ed357aebcf894610d0efa4c48538f81b021aa6253f5f338494dd2fda` |

Original archive `19c92a463b99b8eee21469ad290399875ac08102` still hashes to `043303428b0582cef203b99b9490b0638d4db95da341daabb2872e3244578712`; it and its failed run/report/accounting remain historical, not substituted or overwritten.

### Protected staging and one actual run

Strict-host-key/BatchMode SSH reverified public-demo isolation, dedicated MariaDB, unchanged shared environment/key/storage and disabled private intake/all four switches. Explicit `/usr/bin/php8.4` is still 8.4.27. Protected original credential/recipient files remained owned regular 0600 files in the 0700 operator directory; values were not exposed or changed. A new 0700 protected recovery set contains completed consistent SQL dump, environment, readable storage archive/inventory, checksums and absent-crontab baseline. **No isolated restore or host-loss recovery was verified.**

The exact archive was installed inactive with locked production Composer dependencies/platform checks, without rebuilding assets. Its migration inventory exactly matches the **19 already applied migrations**; **no migrations, seeding, reset or down operation ran**. A new process-only 45-minute overlay reused approved credential/corpus/recipient identities while public shared configuration stayed byte-for-byte disabled. Real PHP application bound only `127.0.0.1:8014`, reached through loopback-only SSH forwarding; actual served CSS/JS matched the new archive before admission. The initial launcher working-directory error and host crontab long-filename limitation were corrected before paid admission; neither was a model failure or permission denial.

Real certificate-validated EXAMINE/BODY.PEEK reverified the existing **20 authored-demo-v2 messages**, UIDVALIDITY **1793117440**, UIDNEXT **21**, identities/approved hashes and unchanged raw contents/persistent flags before/after the run; session-local `Recent` was excluded from persistent-flag comparison. **No APPEND, mailbox move/delete or genuine-mail access occurred.** A single owned minute scheduler invoked the unchanged dedicated bounded worker/recovery through explicit PHP 8.4, inherited process-only configuration and the original helper deadline.

Immediately before browser admission, actual UTC settled inference was **291 micro-USD**, zero all-day reservations/uncertainty/jobs and no accounting block. The approved admission reservation remained **75,680 micro-USD**. The one real web-admitted run **`01m69tea8emstgs8ep4dwj513e`** retained admission **20:29:28Z**, original deadline **20:59:28Z**, and finalized **20:33:13Z**:

| Actual result | Verified value |
| --- | --- |
| Final state / counts | **partial / 20 selected / 19 succeeded / 1 failed** |
| Count / inference operations | **22 / 22**, at most two attempts/item |
| Failed attempts | DEMO-004 attempts 1 and 2, DEMO-003 attempt 1: `demo_ai.evidence_invalid`; DEMO-003 retry succeeded |
| Model / exact request identity | All returned `gpt-6-luna`; every authoritative count hash matched its inferred full request |
| Full schema-v2 input count | **1,314-1,398**, all within 8,000; old v1 counts were not reused |
| Shared authorization spacing / rolling minute | Minimum **2 seconds**, maximum **17 operations/minute** |
| Modeled inference settlement | **4,872 micro-USD = USD 0.004872**, all 22 outcomes known |
| Outstanding reservation / uncertainty / jobs | **0 / 0 / 0** |
| UTC settled total, including earlier diagnostic | **5,163 micro-USD** |
| Counting fees / endpoint-specific retention | **NOT VERIFIED**, unchanged accepted-risk exception |

The 19 stored results passed unchanged validators on independent reread. Their recommendations match the authored expected bands and no obvious hard-constraint conflict was found in the bounded review; this is not a general accuracy/commercial-usefulness claim. No raw failed model responses were captured, so **the remaining DEMO-004 exact-evidence failure is not causally diagnosed**. The schema namespace correction did not establish universal quote correctness. No output was repaired or retry allowance extended, and no second whole run was admitted.

### Report, delivery, safe stop and remaining gates

Real browser finalization/refresh, matching report/download bytes, filename, script/external-resource exclusion and guest/foreign-session denial passed with zero observed page errors/outside-origin browser requests. Partial report HTML is **107,913 bytes**, SHA-256 **`812077b286c0ab11490119a5a4e81e12eed3788405aabde037bedc89c2550c86`**; the durable delivery HTML hash matches it.

One new `demo-ai:deliver-owner` invocation was **SMTP-accepted at 20:34:09Z**. Duplicate invocation returned existing accepted state without another send. The protected sole recipient/source identity was unchanged; the old accepted report was not resent. The owner explicitly answered **“Not received”** for this new run (OPERATOR-REPORTED), recorded separately in protected evidence; **no positive receipt timestamp/reference was fabricated**.

Because **19/20 fails the newly approved 20/20 gate**, and owner receipt also remains absent, conditional public code switching/rollback rehearsal, activation and v1.0.2 publication were **NOT EXECUTED**. The owned cron was removed only after exact readback against its owned entry and absent original baseline; bounded server/tunnel stopped, process overlay disabled/retired, active helper removed. Public `current` never left **v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**. Final actual HTTPS asset hashes, seeded authenticated queue/reload, secure isolated session, absent live link and logout/guest JSON 401 passed at **20:40:16.049Z**; an earlier check used an incorrect API URL and was corrected to the documented `/review/v1/opportunities` route.

All **11 original historical-table counts/digests**, including deterministic evaluations/skills and private-intake tables, and the original immutable report were reverified unchanged. Original and new reports/accounting/delivery liabilities remain on the same 19-migration schema; no jobs, uncertainty, reservations, active task cron or paid helper remain. Prior v1.0.0/v1.0.1 tag/asset identities were rechecked; unused v1.0.2 was not created.

Protected new recovery/evidence lives at `~/apps/fotp-demo/recovery-phase64-schema-v2-20261031/`; verified assets at `~/apps/fotp-demo/incoming-phase64-schema-v2-20261031/`, inactive installation at the exact SHA. `runtime.disabled.env` and `retired-operator.php` are evidence, not an active configuration/scheduler. Never reenable or rerun them automatically.

Before the narrow sheet/roadmap updates, guarded read-only original comparisons at **20:38:50.286Z / 20:38:52.461Z** reconfirmed unchanged saved remote baselines (`71d35f9f917f61c1fd9c7007988b73a909502b1494c4852dce3210b2329858d9` / `10ce119dc3f834b56351ee75928207cf9528024f2869755004166c347862fc21`). Preserved pre-edit repository hashes were `ee4e3f7b538b51f79379e3df1a5765089d23da2d8ed749c7808b9ea59175ce67` / `7bd74c065d623759f4031bcd5c8cf82b188093006fd3b57d41d9b065eaa82d0e`. No document/state replacement, force pull or shared publication occurred.

**Remaining blockers:** DEMO-004 evidence failure and new owner nonreceipt. The new one-run/one-email allowance is consumed. Further paid work/email needs another bounded decision; do not silently lower the accepted 20/20 gate or repeat for cleaner results. Phase 6.4 and Phase 6 remain incomplete. Later documentation-only integration does not replace this exact tested archive.

## Baseline and candidate

- Repository baseline: `eaa26d1f8b81ed3fcd6d2f2fca81ac7339677c15`, including merged 6.3 PR #78. Existing 6.3 CI and the guarded shared-original comparison are recorded in [readiness](phase6-readiness.md#slice-63-final-integration-outcome---2026-10-30).
- Review branch: `feat/phase64-owner-delivery`. The exact frozen PR-head SHA and passing protected checks must accompany the grouped approval. Any code change invalidates that candidate's checks.
- [PR #80](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/80) preserves sequential implementation `7d4de79` and documentation `65f2d5c` / `b15f544`. Following grouped owner approval, normal protected squash merge completed at **2026-10-30T20:02:37Z**, yielding exact main candidate **`19c92a463b99b8eee21469ad290399875ac08102`**. Its [own post-merge CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226) succeeded. No protections were bypassed.
- Normal protected integration, never bypass: Quality, Tests / MariaDB 11.4 and Secret scan at the final PR head. After authorized merge, obtain the resulting **exact main SHA** and passing push CI. Download only its `release-candidate-<sha>-<attempt>` artifact, strictly verify the four files/checksums/inventory, and record its archive digest. PR-head results are not release evidence for a different main revision.
- Proposed unused version: **v1.0.2**, following the existing publication validator's `v1.0.<patch>` convention. No versioning-policy expansion, tag overwrite or publication is authorized by this document. The exact main archive, not a local rebuild or the old v1.0.1 archive, must pass acceptance.

## Implemented owner delivery

```sh
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --no-interaction
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --recover --no-interaction
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --receipt=<opaque-evidence-reference> --no-interaction
```

Only a protected operator shell can invoke this command; there is no HTTP sending route, recipient argument, administration UI or mail queue job. A queue redelivery cannot dispatch mail. Evaluation jobs and inference accounting are untouched.

Protected `DEMO_AI_OWNER_DELIVERY_ENABLED` (default false), `DEMO_AI_OWNER_RECIPIENT`, `DEMO_AI_OWNER_RECIPIENT_SHA256` (exact address allowlist digest), `DEMO_AI_OWNER_RECIPIENT_VERSION` and `DEMO_AI_OWNER_VERIFICATION_REFERENCE` must all be supplied. The reference identifies the existing explicit owner-control/sole-recipient attestation, not a fabricated machine verification. Configure through the host-only environment; never put the actual address, credentials or allowlist digest in chat, repository evidence or logs.

The command requires the guarded demo MariaDB and server-configured demo workspace. It refuses missing/foreign/private, unfinished, purged/expired or unavailable stored reports and non-public profile snapshots. It renders the existing immutable `demo-report-v1` using the identical `review.ai-report` Blade view as the screen/download, sends that HTML and attaches exactly the same HTML bytes under a server-owned ULID filename. It never reconstructs results from current inputs, runs intake or calls AI.

The existing Laravel mail infrastructure builds an isolated SMTP mailer from protected SMTP host/credentials, valid From and port 465/587. TLS and certificate validation are required, timeout ten seconds; no log, array, failover, round-robin or global recipient override is selected in production. Controlled tests substitute only the transport. This is intentionally synchronous so the operator records the immediate outcome and no separately running mail worker can retry it.

### Durable state and recovery

The additive `demo_ai_deliveries` table retains report hash, rendered HTML hash, HMAC recipient/configuration identity, configuration version, hashed verification/receipt references and timestamps; no address, HTML content, credentials or raw exception. A run-row lock and committed atomic `prepared -> sending` transition precede transport access. Primary uniqueness on **run ID** is deliberately stronger than `(run, snapshot, recipient version)`: rotating configuration, changing code or redelivering a trigger cannot send a second copy of this report.

| State | Meaning / operator action |
| --- | --- |
| `prepared` | Durable intent, not external acceptance. Repeated invocation does not send. Interrupted preparation may be classified `failed` with `--recover`. |
| `sending` | Transport may have begun; never resend. After 150 seconds, `--recover` can classify process loss as `uncertain`. Quiesce the original process before investigation. |
| `accepted` | Laravel transport returned confirmed acceptance; **not owner receipt**. Repeated commands return existing state without sending. |
| `failed` | Known local pre-send failure/interrupted preparation; no automatic retry or state reset. Further mail requires a separate reviewed operator decision, outside this one-email allowance. |
| `uncertain` | Exception/null result/process loss can hide SMTP acceptance. Retain uncertainty; **no automatic or blind resend**. Resolve from authorized transport/owner evidence, not by testing another send. |

`--receipt` only records a separate immutable timestamp/reference hash after `accepted` or `uncertain`; it does not send or rewrite SMTP state. It is **OPERATOR-REPORTED** receipt, not agent-inspected inbox evidence. References must be opaque identifiers, never addresses or transport dumps. No automatic IMAP inspection of a personal inbox.

Mail failure cannot release inference liabilities, change results, extend expiry or restart evaluation. Seven-day cleanup removes report content but retains delivery/accounting identity. A committed sending row plus uniqueness is **not exactly-once external delivery proof**. If the process disappears after SMTP acceptance and before persistence, its durable outcome remains uncertain.

## New offline evidence

Controlled Symfony array/ambiguous transports exercise the real Laravel HTML/MIME path. No mailbox, count, inference or external email calls occur in CI. Tests cover stored HTML/attachment equality and escaping; recipient configuration/overrides; missing, expired, purged, foreign/private and unavailable reports; repeated commands/configuration rotation; real four-process MariaDB contention; known preparation failure; SMTP ambiguity and stale-process recovery; separate receipt; unchanged AI tables/provider calls/queue; and retention of unresolved inference/delivery liabilities.

Local checks already executed during implementation:

| Check | Result / scope |
| --- | --- |
| Guarded disposable MariaDB | Dedicated task-labelled MariaDB 11.4.13, loopback 3307, exact `_test` database and hostname verified; repository safety guard passed |
| Focused owner-delivery suite | 21 tests / 132 assertions passed before the final metadata-only addition; final-head CI must revalidate all changes |
| Final full PHPUnit with PCOV | **320 tests / 2,555 assertions**, including final metadata, null-transport, disable-recheck and outer-transaction cases; coverage **90.44% overall / 93.64% parser-domain / 96.90% triage**, passing existing thresholds |
| Full installed Chromium suite | **24 passed**, including unchanged private/seeded journeys; controlled application adapters only |
| PHPStan / Pint | Passed on the final implementation, zero static-analysis errors |
| Composer validate / locked audit / npm high-severity audit | Passed, no advisories reported |
| Production Vite build | Passed with installed packages; not represented as a fresh locked install |
| Release / sheet-sync contracts | 6 / 25 passed |

Fresh locked CI (including audits/secret scan/inventory) at the exact final PR head is required before execution approval; its durable run/check records accompany the candidate rather than substituting older results. The local browser/build used existing installed packages, not a fresh locked install. Initial failed test attempts (output matcher/expected wording and incomplete test-case match) were corrected, not suppressed or described as passes. The complete implementation, tests, migration and documentation diff was reviewed before proposing execution.

### First fresh locked draft-candidate CI - 2026-10-30

VERIFIED: [CI 41118856780](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41118856780) succeeded for exact PR head `65f2d5c27eec599e65059e01ed8028ce247e50af`. Quality, Tests / MariaDB 11.4 and Secret scan all passed. Logs explicitly show fresh `composer install` and `npm ci`, successful production build, locked Composer/npm audits without reported vulnerabilities, **320 PHPUnit tests / 2,555 assertions**, coverage **90.44% / 93.64% / 96.90%**, **24 browser tests**, release/sync contracts, verified Syft inventory and candidate preparation/validation. This is new **6.4 offline** evidence, not live inference/email acceptance or post-merge release-archive evidence. This documentation-closure update must also pass its own final-head checks; the durable PR records identify that revision/run.

Task infrastructure was cleaned after validation: exact container `116c405882df284a7047742ab1142e51b7d3dc8b8e38509aee9344260fdf9976`, task label, loopback binding and SQL hostname/database were reverified before removing only that disposable container/anonymous volume. Ports 3307, 8011, 8012 and 8013 were confirmed closed. No unrelated container, deployed database or host process was changed.

### Final PR-head and post-merge verification

VERIFIED: final PR head **`b15f54425ce58e9989e4d04a866e34861e333357`** passed its own [CI 41119464142](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41119464142), then merged main candidate **`19c92a463b99b8eee21469ad290399875ac08102`** independently passed [CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226). Both completed Quality, Tests / MariaDB 11.4 and Secret scan successfully; fresh locked installations/build, audits, **320 tests / 2,555 assertions**, **24 browser tests**, **90.44% / 93.64% / 96.90%** coverage and inventory/candidate validation passed. Logs were inspected, not inferred from the previous head. Exact archive download/checksum/host acceptance remains **NOT VERIFIED**; a green CI artifact producer is not a downloaded/deployed artifact.

## Protected read-only feasibility

VERIFIED on 2026-10-30 through the existing `fotp-public-demo` SSH alias, with strict known-host checking and no host changes:

- Correct public URL `https://fotp.kontentwave.com`, demo review mode, dedicated `_demo` MariaDB, independent `fotp-public-demo-session`, shared environment mode 0600 and parent mode 0700. Private intake disabled; configuration uncached.
- Current link `~/apps/fotp-demo/current -> releases/7806994bfe639ffc3185f6d3dd742511d36b9870`; webroot points to this account's `current/public`. This matches the recorded v1.0.1 deployment, not private or retired stage.
- Candidate target: `~/apps/fotp-demo/releases/<exact-main-sha>`, `.env -> ~/apps/fotp-demo/shared/.env`, `storage -> ~/apps/fotp-demo/shared/storage`. No private key/session/database/storage sharing.
- Host default `php` is **8.5.12**; explicit **`/usr/bin/php8.4` is 8.4.27**, with pcntl. Use that absolute binary in every worker, Artisan and scheduler command. `timeout`/`flock` available. No existing crontab lines; no schedule installed.
- Read-only SQL transaction: 16 existing migrations, no `demo_ai_*` tables; 2 workspaces, 2 users, 28 opportunities, 3 reviews, 2 enrichments, zero jobs/failed jobs. No data rows read out or changed.
- Existing protected staging directory `~/.fotp-phase61-operator` is 0700; all four populated owner-held files are 0600. Recipient syntax/separation and provider-key presence verified without disclosure. Existing owner-control, project/billing/data-setting attestations remain **OPERATOR-REPORTED**; not repeated as machine-verified facts.
- **SMTP is not ready:** current default is array, localhost/2525, no SMTP credentials. No SMTP connection or send attempted. Dedicated demo folder is not configured. These require protected operator input and explicit corpus-population authorization before live work.
- No isolated active acceptance installation exists. Port availability, hard-stop execution and integrated scheduler behavior must be established during the authorized setup; a CLI/symlink check alone is not web-version evidence.

Official model and pricing pages were re-read on 2026-10-30: `gpt-6-luna`, Responses/structured outputs/reasoning none, Standard USD 0.10 input / 0.01 cached / 0.125 default 30-minute writes / 0.50 output per million still match ADR-010. Recheck immediately before use and stop for a revised decision on a material change. Unknown counting fees/endpoint retention remain accepted but **NOT VERIFIED**; no support inquiry, free-counting assertion or guaranteed prepaid hard cap.

[Official Websupport client configuration](https://www.websupport.sk/podpora/kb/nastavenie-posty-v-outlooku-2019/) was also checked: outgoing SMTP uses port **465, SSL/TLS**, with the exact server supplied in the owner's control panel. Do not invent the outgoing hostname from the known IMAP hostname, disable provider protections or assume SMTP authentication from historical IMAP success. The grouped decision can authorize reuse of the protected demo-mailbox credentials/From if the owner chooses; otherwise stage separate SMTP settings in a 0600 `~/.fotp-phase61-operator/smtp.env` file, never chat. No authentication or send was performed.

## Approved grouped execution decision and remaining gate

**APPROVED by the owner in this session:** full bounded plan, normal protected merge, exact resulting main CI/archive verification, public-demo-only backup/migrations/loopback staging, authorized corpus APPEND, one bounded acceptance run, at most one owner report email, disable/code rollback, and conditional opt-in/v1.0.2 publication only after successful acceptance and owner receipt. Do not request these permissions again unless the scope/material assumptions change. No action may bypass a failed audit/check, change code/model/rates/caps, use private/retired targets, or repeat the run to improve results.

Owner inputs and decisions:

1. Full sequence and unused **v1.0.2** conditionally approved, not published.
2. Reuse of the protected **dedicated project demo-mailbox** credentials and mailbox identity as From approved. The owner supplied **`smtp.m1.websupport.sk:465`** (OPERATOR-REPORTED; no SMTP connection/authentication/send yet). The initially reported ready `smtp.env` file was actually absent; the subsequent explicit hostname/port resolves that input without requesting any secret in chat. Existing protected recipient remains the sole approved destination.
3. Creation/use of **`FOTP-Authored-Demo-Phase6`** and IMAP APPEND of exactly the 20 approved RFC822 files authorized, **not executed**. No fabricated marketplace sends, real alerts or unrelated mailbox edits.
4. Protected backup/recovery preparation, bounded loopback staging/scheduler and short public code-switch/rollback window authorized, **not executed**. Read-only follow-up confirmed `mariadb-dump`, Composer and the staging port available.

### Bounded operations and budget

- Exactly **one acceptance run**, at most 20 approved unique fictional sources, original 30-minute deadline. No repeat whole run after failure/uncertainty.
- At most one existing classified retry/item (two attempts maximum), only for existing approved classifications. Count/inference share concurrency one, rolling 30 operations/minute and >=2-second spacing; no separate counting experiments. At most 40 inference attempts and their request-bound counts, not a new unconditional retry allowance.
- Full-run inference reservation **75,680 micro-USD = USD 0.075680** (both attempts, margin included). Before admission, inspect all-day unresolved reservations plus current UTC settled ledger. There are presently no feature tables, so expected fresh capacity is USD 1.000000, leaving **USD 0.924320** after this reservation, **conditional on the new ledger check**; no observed live ledger balance is claimed. Preserve uncertainty across days. Stop additional paid dispatch on uncertain accounting.
- USD 1.00/day bounds modeled inference only. Counting fees remain independently unknown/null and endpoint retention unverified. Report measured inference usage/cost from actual attempts separately; prepaid balance/no recharge is not a hard cap.
- At most **one external report email**, no retry after SMTP ambiguity. Duplicate triggers must return stored state and produce no additional send. Owner receipt requires authorized evidence or explicit owner report.
- One bounded setup/acceptance window (maximum 45 minutes for helpers; run deadline remains 30 minutes), with automatic no-work deadline for the temporary scheduler. No permanent helper/cron until explicitly accepted opt-in. Cleanup removes only this task's helper files/owned cron entry; preserve mailbox sources, successful results, historical review data and sent/uncertain liabilities.

### Approved-sequence checklist (checkpoint plan; actual execution below)

1. Freeze PR head, verify protected checks/fresh locked install and review complete diff. After authorized normal merge, wait for **that main revision's** green CI and verify/download its four-file archive/inventory. Record full SHA, run/attempt, archive SHA-256 and UTC times. Any source change restarts candidate validation, not paid acceptance.
2. Reverify SSH account/public hostname/current link/demo DB/storage/session/key isolation and disabled private intake. Back up demo DB consistently plus shared environment/storage/current link/crontab into a new 0700 recovery directory with 0600 files outside webroot. Check completion/hash/table inventory; verify readable recovery material and rehearse non-destructive recovery on an explicitly isolated target if available. Provider-backup history is not new restore proof; do not reset live data.
3. Extract the exact archive into the inactive SHA release; install **locked production Composer dependencies** under PHP 8.4 and verify platform requirements. Do not rebuild compiled assets. Link only public-demo shared config/storage and verify paths.
4. Apply only these three pending additive migrations to the verified demo DB, preserving the existing 16 and all history:
   - `2026_10_28_142713_create_demo_ai_state_tables`
   - `2026_10_29_211053_add_report_snapshot_to_demo_ai_runs_table`
   - `2026_10_30_193616_create_demo_ai_deliveries_table`
   Never run fresh/seed/down migrations. Delivery table restricts deletion of its run; all feature identity/ledger rows survive code rollback.
5. Configure protected synthetic IMAP, selected provider and exact accepted-risk reference `ADR-010:2026-10-29:accepted-counting-risk` with empty policy-evidence hash, owner allowlist/version/attestation and suitable TLS SMTP. Verify no private override. Stage remains inactive publicly: **old public v1.0.1 has no paid routes**.
6. Bind the inactive candidate web server **only to `127.0.0.1:8014`**, after verifying port availability, and access over an authenticated SSH tunnel. Do not proxy/expose its port publicly. Use the same candidate application/real bindings, not the controlled browser-test router. Verify server responsiveness and exact served compiled asset hashes. Keep old public root unchanged throughout live acceptance; general visitor admission remains unavailable.
7. Under the grouped corpus authorization, import/APPEND only the approved 20 messages to the dedicated folder; compare exact normalized hashes/IDs and preserve pre-existing unrelated contents. Record approved-source selection, bounded UID snapshot/UIDVALIDITY, duplicates, flags/content before/after real certificate-validated EXAMINE/BODY.PEEK.
8. Run the candidate's real `schedule:run` from an owned temporary minute-crontab entry with `/usr/bin/php8.4`, fixed deadline and suppressed cron mail; retain baseline for exact restoration. Its configured worker remains dedicated `demo-ai`, database retry_after 150, `--stop-when-empty --max-time=40 --timeout=45 --sleep=1 --tries=1`, outer timeout 100 + 5-second kill grace, overlap 180 seconds. Do not drain default/private queues.
9. Through the tunneled real web session, explicitly prepare/admit **one** run, observe stored progress, actual request-bound count/inference provenance and validated outputs. Preserve partial/honest failures; stop on uncertainty, never rerun to clean up. Record actual inference token/cost settlement and unknown count fees separately. Review recommendations against public rubric for obvious hard-constraint/unsupported-evidence violations; expected bands are aids, not general accuracy proof.
10. Compare finalized stored report and actual downloaded HTML; use operator command to send that report once. Repeat the trigger to verify no extra external send. Inspect sanitized delivery state; obtain explicit owner receipt or authorized transport/owner evidence. SMTP acceptance alone leaves receipt pending. Disable new admissions/paid dispatch if anything is uncertain.
11. Quiesce scheduling/new work, wait for bounded workers/requests or classify process loss as uncertain; run recovery without releasing unresolved liabilities. Disable **feature, counting, scheduler and owner delivery** in host config, then clear each release's cached config with explicit PHP 8.4 (rebuild only if deliberately using caching). Confirm seeded demo and no admission/provider dispatch.
12. With switches disabled, atomically switch `current` to the candidate; verify **actual HTTPS-served** compiled asset bytes/HTML against the archive, not CLI/current alone. Allow for known shared-host OPcache delay and fail if old assets remain served. Exercise seeded queue/feedback/session/foreign/private isolation, then roll code/assets back to `releases/7806994bfe639ffc3185f6d3dd742511d36b9870` against the **same additive schema/config/storage**, verify served bytes and unchanged history/liabilities, restore the candidate and recheck. Do not down-migrate/drop/delete feature rows.
13. Only if every applicable acceptance item passes (including owner receipt), deliberately enable feature/counting/scheduler under the approved opt-in; keep private intake disabled and owner delivery disabled except the bounded command. Install only the accepted minute scheduler with bounded worker/recovery. Reverify configuration/cache behavior and public served version. If acceptance fails, leave switches disabled, stop owned helpers and keep seeded demo/previous safe code.
14. Publish only accepted exact main archive through the existing [release workflow](phase5-release.md#publication-and-partial-failure), with approved unused v1.0.2, exact CI run, archive digest and post-restoration smoke timestamp. Verify tag, all four asset digests, actual web-served code and previous release immutability. Partial release failure requires inspection, not automatic retry/tag replacement.

## Actual live/release outcome

**Historical checkpoint at `4cac97bf0f49acb86607ab6c12d53d02917346a9`, before this continuation.** The following unconsumed-allowance and authorization-blocker statements describe that checkpoint, not the subsequent execution below.

**NOT VERIFIED / NOT EXECUTED:** corpus population/retrieval, paid count/inference, live worker/scheduler, SMTP acceptance, owner receipt, migration/backup/restore, host changes, disable/code rollback rehearsal, activation and release publication. Newly incurred inference/email cost is **USD 0** because none was executed; unknown historical counting charges remain unverified. No new release or owner receipt is claimed. Phase 6 cannot be marked complete until these applicable criteria have actual evidence.

**Concrete blocker:** the tool authorization layer refused the exact-main candidate download/preparation call and reported that it could not obtain the required runtime permission. The operation was not retried through another path/tool/location. Read-only GitHub inspection independently verified the merged candidate's green CI afterward. Owner approval remains recorded; this is a runtime execution-permission limitation, not missing owner consent or a reason to reopen counting policy. Resume at exact candidate artifact verification only after that execution gate is available, preserving the existing one-run/one-email allowance. No paid allowance was consumed and no host/helper/scheduler cleanup is outstanding.

## Resumed bounded execution - 2026-10-30

### Frozen candidate and runtime gate

VERIFIED: the worktree initially was clean on `docs/phase64-live-gate-checkpoint`, exactly `4cac97bf0f49acb86607ab6c12d53d02917346a9`. [PR #81](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/81) was OPEN, unmerged at that head. No checkout, merge, rebuild, dependency-manifest change, commit or push substituted another source candidate.

Read-only GitHub metadata reconfirmed push-to-main [CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226), exact **`19c92a463b99b8eee21469ad290399875ac08102`**, latest completed **attempt 1**, success, with Quality, Tests / MariaDB 11.4 and Secret scan all successful. The matching artifact `release-candidate-19c92a463b99b8eee21469ad290399875ac08102-1`, ID `13006989577`, was unexpired until `2026-11-29T20:06:16Z`. Its GitHub-reported ZIP digest was `ea48ee875acabd7c5f575e3d294e26ecc937fd441ef814015abf5f757610d033`; this is distinct from the independently checked application archive.

The first normal `gh run download` invocation in this continuation succeeded through the supported execution interface. No current denial was routed around or blanket permission granted. This establishes that retrieval was permitted now, **not the cause of the earlier runtime denial**. Existing owner authorization was not requested again.

VERIFIED locally and after protected SSH transfer: exactly four regular files, strict `SHA256SUMS`, exact manifest repository/SHA/run/attempt, safe archive inventory and complete dependency inventory against both archived lockfiles. The repository's `validateFiles` validator passed. No branch checkout, old release archive or local build was used.

| File | Independently verified SHA-256 |
| --- | --- |
| `freelance-opportunity-triage-platform-19c92a463b99b8eee21469ad290399875ac08102.tar.gz` | `043303428b0582cef203b99b9490b0638d4db95da341daabb2872e3244578712` |
| `candidate.json` | `3499a48e084c5bfdc03a09abdd55e749d987217b39b3580a40f73b0b7564d552` |
| `sbom.cdx.json` | `c6d8c499154142aaf4d4febc17ae30fe4041d17582ca4e1b342e52632de6a383` |
| `SHA256SUMS` | `7bd5b0dae0aabe775844a8ef59bcd0536bd7919c3c9587f7e37021ca34d306c0` |

The 320 PHPUnit tests / 2,555 assertions, 24 browser tests and 90.44% / 93.64% / 96.90% coverage remain the **existing exact-revision CI evidence**, not a rerun in this continuation.

### Protected target, recovery and isolated execution

VERIFIED with `fotp-public-demo`, BatchMode and strict host-key checking: original v1.0.1 link/webroot, dedicated MariaDB `_demo`, demo mode, disabled private intake, independent shared environment/storage, valid application key and secure host-only public session configuration. Protected directory/file ownership, non-symlink regular files and 0700/0600 modes passed without disclosure. `/usr/bin/php8.4` was **8.4.27**. The first port probe could not use absent `ss`; a subsequent actual loopback bind and later `/proc/net/tcp` inspection established availability/isolation. An initial overly narrow `mysql`-connection-name check was corrected read-only to the existing supported `mariadb` driver; no target or database was changed to satisfy it.

At **20:18:47Z**, a new protected same-host recovery set held a complete consistent SQL dump, original environment, readable storage archive/inventory, original current link, absent crontab baseline, table inventory and historical row digests. File modes/checksums and dump completion passed; historical application rows remained unchanged during preparation. **Restore into an isolated database was NOT VERIFIED.** Readability is not restore proof; SQL/storage are not a cross-resource atomic snapshot, and same-host copies do not prove host-loss recovery or renew private REL-03 backup evidence.

The exact archive was installed inactive at `releases/19c92a463b99b8eee21469ad290399875ac08102`, with the existing public-demo shared environment/storage links. Locked production Composer installation and platform checks passed under explicit PHP 8.4; assets were not rebuilt. Rechecking the actual 16 applied migrations found exactly the three approved pending additive migrations. Only those ran, completing at **20:19:14Z** with **19 applied migrations**. No reset, seeder, historical deletion or down migration ran.

A protected, host-only runtime overlay supplied the approved dedicated source/SMTP identity and sole recipient. The shared public `.env` remained byte-for-byte unchanged and disabled. Only the isolated candidate processes received enabled feature/counting/scheduler values; owner delivery stayed disabled except for its bounded command. Loopback browser cookies were separately named; public HTTPS session settings were unchanged.

VERIFIED: real candidate served at **127.0.0.1:8014 only**, accessed through authenticated loopback-only SSH forwarding, with automatic 45-minute hard stop. Actual served CSS/JS matched archive bytes at **20:24:00Z**. The public seeded site remained old code during paid acceptance. No controlled browser-test router was used.

Exactly the approved **20 authored-demo-v2 RFC822 files** were APPENDed once to `FOTP-Authored-Demo-Phase6`; per-message durable intent/acceptance evidence prevents silent repetition. Real certificate-validated EXAMINE/BODY.PEEK established **UIDVALIDITY 1793117440, UIDNEXT 21**, 20 unique approved normalized hashes/IDs. The real application selected descending UIDs 20 through 1, with no duplicate/rejected source. Before/after raw content hashes and persistent flags matched at **20:32:54Z**; session-local `\Recent` was not misrepresented as a persistent flag. No genuine alerts, SMTP-fabricated marketplace sends, moves, deletion or unrelated mailbox changes were performed.

The temporary owned minute cron invoked the real `schedule:run`, dedicated database worker and recovery with the committed timeout/queue/overlap controls. An initial long-filename `crontab <file>` invocation failed before installation; its supported stdin interface succeeded with exact readback. This was an OS command failure, not the earlier runtime authorization denial. A fixed helper deadline and stop sentinel bounded further work. Default/private queues were not drained.

### One live run: actual failure and reconciled usage

Immediately before admission at **20:24:02Z**, the actual UTC ledger showed **0 settled / 0 outstanding reserved micro-USD**, no runs/attempts/deliveries and no accounting block. The measured remaining capacity after the planned **75,680 micro-USD** reservation was **924,320 micro-USD**. This was measured here, not inferred from the fresh-schema expectation.

The real browser admitted exactly one run, **`01m677qrdnpzan84tj7shz9jkv`**, at **20:24:05Z**, original deadline **20:54:05Z**. Replay of its original server-issued intent returned the same run without a second admission. Stored progress was observed from queued through running to **failed**, finalized **20:32:18Z**, expiring **2026-11-06T20:32:18Z**.

| Actual observation | Result |
| --- | --- |
| Selected / succeeded / failed | **20 / 0 / 20**; no accepted recommendation |
| Count / inference operations | **40 / 40**, one request-bound count per actual inference attempt |
| Attempts | **40**, maximum **2 per item**; only the existing classified retry |
| Classified failures | **22 `demo_ai.evidence_invalid`**, **17 `demo_ai.output_invalid`**, **1 `demo_ai.response_incomplete`** |
| Actual full-input counts | **1,081-1,122**, all below 8,000; current attempt provenance, not reused historical corpus measurements |
| Requested / returned model | `gpt-6-luna`; current official model/settings/rates rechecked unchanged before use |
| Shared operation authorization spacing / rolling minute | Minimum **2 seconds**, maximum **16 operations** in any rolling minute; sequential attempts, no overlapping durable attempt intervals |
| Actual usage | **120 ordinary input / 22,072 cache-read / 22,072 cache-write / 11,649 output tokens** |
| Settled modeled inference | **8,835 micro-USD = USD 0.008835**, per-attempt upward rounding |
| Inference uncertainty / remaining reservations | **0 / 0**; all 40 attempts known, accounting unblocked |
| Counting fees / endpoint retention | **NOT VERIFIED**; all 40 potentially charged counts retain null/unverified fees, separately from inference settlement |
| Queue after completion | **0 jobs / 0 failed jobs** |

The usage is actual application/provider-response reconciliation, not an independently inspected provider invoice. Unknown counting fees and any separate email charge are not claimed zero. The USD 1.00/day contract still covers modeled inference only. No counting-policy support inquiry, model/rate/cap change, fabricated evidence, paid replay or second whole run occurred.

The finalized failure report is honest: all items are unsuccessful, not invented SKIP recommendations. No recommendation-quality or hard-constraint accuracy pass can be claimed when none succeeded. The retained codes identify validation/incomplete-response failures, **not their exact raw-response root cause**; raw responses were deliberately not retained, and no new paid diagnostic request was made. Do not weaken the validators to make this candidate pass.

### Stored report, single owner send and nonreceipt

VERIFIED through the owning real browser session: on-screen report and actual HTML download endpoint returned identical bodies, with the expected safe filename. Finalized snapshot SHA-256 **`7565225013bd5acbd5ed40d639586d6677998b7be7be0084c1cd4dba3ad39453`**; rendered/delivered HTML SHA-256 **`6c886573cc857d77ad7ac3ed5bd2a918e205aa9a7390d9fd3e37c9474b34bd43`**. The stored report contains the failed outcomes and remains immutable through recovery and rollback.

At **20:33:23Z**, one invocation of `demo-ai:deliver-owner 01m677qrdnpzan84tj7shz9jkv` returned **accepted** using the approved protected source identity and `smtp.m1.websupport.sk:465` with required TLS/certificate verification. This verifies application-observed SMTP acceptance/authentication, not owner receipt. A duplicate invocation returned the same durable accepted state without another send; exactly one delivery row remains. AI attempts/results/accounting were not restarted by delivery.

**OPERATOR-REPORTED: owner has not received the report.** The owner clarified that the newly supplied address referred to the dedicated project source/SMTP sender, not the recipient. A host-only comparison VERIFIED that the already-used protected source identity exactly matched that clarification and that the protected sole recipient was distinct. No identity/address was changed or exposed. No positive `--receipt` command was run; `receipt_reported_at` remains null. Nonreceipt is retained separately from the durable accepted SMTP state. No resend, alternate destination or personal-inbox inspection was attempted.

### Disable, same-schema rollback and safe handoff

VERIFIED: owned cron removed with exact restoration of the originally absent crontab; bounded work quiesced, recovery run with feature/counting/scheduler/owner-delivery disabled. The staging SSH/server stop initially left two identifiable remote web processes; only those exact PIDs were terminated, then host/tunnel port closure was verified. The runtime overlay was explicitly disabled and retired into protected recovery; owned mailbox/scheduler helper files were removed. Final host ports **3307, 8011, 8012, 8013 and 8014** had no listeners. No permanent scheduler was installed.

Both releases' config caches were explicitly cleared with PHP 8.4, without flushing sessions, data caches, locks or historical data. With all switches disabled and no outstanding work, `current` briefly selected the candidate at **20:42:56Z**. After a bounded 130-second cache-convergence wait, **actual HTTPS HTML and exact candidate CSS/JS** passed at **20:45:16.516Z**. The existing public session, queue, three reviewed opportunities, retained feedback/enrichment, foreign-workspace 404 and disabled admission were checked. A no-origin authenticated API-context tokenless write returned **419**. An earlier same-origin browser-fetch proxy for this check reached validation (422); it was not falsely reported as a successful negative-CSRF test.

Code/assets alone rolled back at **20:46:44Z**, retaining the same shared configuration/storage and all **19 migrations**. After the same bounded wait, **actual HTTPS v1.0.1 HTML/CSS/JS** passed at **20:49:04.943Z**. The same browser session retained saved feedback, then logout/guest JSON 401 passed. Historical table counts/digests, original application key/shared environment, report identity, all 40 attempts, the one delivery and settled accounting were preserved. The candidate was **not reactivated after failed acceptance**; safe final public code is **v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**.

VERIFIED GitHub readback: v1.0.0/v1.0.1 tag targets, publication dates and four-asset inventories retained their recorded identities/archive digests; no release was edited. No v1.0.2 tag/release or release-workflow dispatch was created.

**BLOCKED / NOT ACCEPTED:** live recommendation acceptance and owner receipt. The one-run/one-email allowance is now consumed; **do not automatically rerun or resend**. Next work is a separately reviewed offline investigation of the evidence/output/incomplete-response failures and authorized delivery/nonreceipt investigation. Any changed source needs new exact-candidate checks; another paid acceptance run or external send needs a new bounded decision. The existing conditional opt-in/publication authorization cannot make a failed run or nonreceipt pass. Phase 6 stays incomplete.

Protected host recovery at `~/apps/fotp-demo/recovery-phase64-19c92a463b99-20261030/` retains SQL/environment/storage, original state, per-APPEND receipts, before/after mailbox hashes, `actual-attempt-provenance.json`, immutable report, `owner-receipt-observation.json` and `final-handoff.json`. The disabled staging overlay is retained there as `staging-runtime.disabled.env`; do not source it as an enabled configuration. The four verified files remain in protected `~/apps/fotp-demo/incoming-phase64-19c92a463b99/`; the inactive candidate release is retained. No protected values or raw transport errors were copied into these documents. Repository evidence updates in this continuation remain local on the checkpoint branch; PR #81 was not pushed or merged, and the frozen candidate was not changed.

## One-item diagnostic and offline schema correction - 2026-10-31

The preceding 2026-10-30 no-further-diagnostic statements remain historical. The owner subsequently approved **one DEMO-001 diagnostic: one count, one inference, no retry or email**, and explicitly clarified consent to the existing temporary full-run/item reservation mechanics. Before dispatch, the dedicated UTC ledger showed zero settled/reserved, no work or uncertainty, and all public switches disabled. Official selected-model settings and rates were rechecked unchanged. The diagnostic reused the already approved/stored synthetic input and snapshot through the real ledger, counting, provider and validator; it did **not** repeat IMAP retrieval/APPEND, web admission, scheduler/worker acceptance, host deployment or migrations.

| Diagnostic evidence | VERIFIED application/provider result |
| --- | --- |
| Unmodified candidate / run | `19c92a463b99b8eee21469ad290399875ac08102` / `01m69rgs00twm1rpczfnsqj9nt` |
| Single attempt / outcome | `01m69rgs1ne76vkeem1jgqq83z` / known `demo_ai.evidence_invalid`, terminal failed |
| Count / inference / retry / email | **1 / 1 / 0 / 0**; diagnostic retry suppressed, no recommendation fabricated |
| Original reservation mechanics | 75,680 micro-USD admission pool; one selected item reduces to 3,784; one dispatch reserves 1,892; all remaining reservation released after known terminal settlement |
| Full input count / request hash | **1,111** / `4441c7293c7b13349777dca3faabfdc19b8fbf659255beb12dc9889fcf2f85b6` |
| Provider request / returned model | `req_376ddc3cdcfa456db39045457806ca09` / `gpt-6-luna` |
| Disjoint actual usage | **3 ordinary input / 0 cache-read / 1,108 cache-write / 304 output tokens** |
| Modeled inference settled / remaining reserved | **291 micro-USD = USD 0.000291 / 0**; no uncertainty or jobs |
| Counting fee | **NOT VERIFIED; null**, separately from inference settlement |

Protected temporary successful synthetic-response inspection established exact valid job quotes under **`field: "description"`**, while the unchanged validator requires **`input.description`**. Profile references were correctly namespaced. Local offline replay reproduced the original rejection; changing only the job-field prefixes in an **offline causal control** passed the unchanged shape, exact-quote, missing-information and hard-constraint validators. No production/model result was repaired. Xdebug launches completed without pausing; breakpoint/variable inspection is **not** claimed. This proves one schema/prompt-contract ambiguity, **not** every historical evidence failure or the 17 output-invalid/one incomplete outcomes.

Under the subsequent explicit offline-fix decision, `EvaluationOutput` now shares its existing non-null evidence-field map between schema and exact-quote validation. Schema `ai-evaluation-v2` / provider format `ai_evaluation_v2` constrains `field` to actual `input.*` / `profile.*` names and `missing_information` to the existing bare input-field/criterion IDs. Descriptions explain exact substring/namespace requirements. Validators still reject bare/unknown evidence, invented quotes and unsupported missing identifiers; no output normalization, scoring/rubric/profile/prompt/model/rate/cap change. The schema version enters the admission version hash, and the exact input-dependent schema enters the full counted/inferred request hash. Old counts cannot certify this new request.

**New local offline evidence:** focused provider/counting tests **50 tests / 1,324 assertions PASS**, including all 20 authored inputs, rejection of observed bare names, valid namespaced job/profile quotes, preserved invented-quote rejection and identical counted/inferred schema. Dirty PHP formatting and project PHPStan pass. Existing 320-test/full-browser/coverage CI remains evidence only for the frozen original revision; no fresh whole-suite/coverage, main CI or live v2 inference is claimed.

**OPERATOR-REPORTED:** the owner observed USD **0.01** for the earlier full run; this is not a verified invoice/fee breakdown and does not replace its measured modeled inference USD **0.008835**. The owner also supplied a Support reply acknowledging that general API no-training-by-default/abuse-log rules do not answer counting-specific questions. Successful/failed counting fees, account/tier conditions, Response/cache/application state, endpoint retention/deletion/backups/exceptions and effective dates remain **NOT VERIFIED**. Counting is not proved free and Responses `store=false` is not transferred onto the counting endpoint. Support requires no test requests/screenshots to clarify policy; no inquiry was reopened. The existing accepted-risk exception remains unchanged.

At **2026-10-31T20:01:21Z**, host readback verified original historical row digests and the immutable first report hash unchanged, one diagnostic item/attempt, no uncertainty/reservations/jobs, still one original delivery, 19 migrations, disabled private intake/all four switches, and current v1.0.1 code. Protected temporary response/input copies were removed after sanitized causal/provenance evidence was written; diagnostic intent/outcome and original accounting/recovery remain retained. No new SMTP connection/send or positive receipt occurred. PR #81 remains OPEN at `4cac97bf0f49acb86607ab6c12d53d02917346a9`; local evidence/source changes have not been published into it or the frozen candidate.

Before the sheet/roadmap edits, protected read-only shared-original comparisons at **20:00:42Z / 20:00:44Z** confirmed unchanged remote baselines: sheet `71d35f9f917f61c1fd9c7007988b73a909502b1494c4852dce3210b2329858d9`, roadmap `10ce119dc3f834b56351ee75928207cf9528024f2869755004166c347862fc21`. Pre-edit local hashes were `08132134ee154b33a606beff0458fc3f8fde1a73637c9790edb41329f05d635d` / `6b0c774587a21e44b5c6ea00fb84d5ecc121ae8f804eeaad8908689fab694586`. No files/sync state were replaced, force-pulled or shared-published.

**Remaining gate:** the newly approved diagnostic allowance is consumed. The offline fix is not a released/accepted candidate. Further integration/deployment and a new exact archive with independent checks need a separately reviewed next scope before additional paid validation; no automatic repeat/send. Other historical failure causes, recommendation acceptance and owner nonreceipt remain unresolved. v1.0.1 stays public, opt-in/counting/scheduler/delivery disabled, v1.0.2 unpublished and Phase 6 incomplete.

Final offline recheck still passed **50 tests / 1,324 assertions** after PHP formatting; `git diff --check` passed. At **2026-10-31T20:04:32.298Z**, certificate-validated actual public HTTPS CSS/JS matched the v1.0.1 release files byte-for-byte, with the current release identity unchanged. No app navigation/admission/provider/mailbox path was invoked for this read-only asset check. Disposable local diagnostic helper/replay/response/input files and their empty directory were removed; the original four-file candidate artifact remains retained.
