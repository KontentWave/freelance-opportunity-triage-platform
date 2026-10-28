# ADR-010: Bounded AI Provider and Hard Usage Budget

- **Date:** 2026-10-27
- **Decision owner:** Marcel
- **Status:** APPROVED for planning; model metadata access VERIFIED and account/data settings OPERATOR-REPORTED on 2026-10-28; paid inference acceptance deferred
- **Scope:** Planning, not account access verification or permission for paid calls

## Decision and current sources

Select **OpenAI `gpt-6-luna`**, Responses API, standard processing, `reasoning.effort=none`, `store=false`, strict JSON Schema, no tools, no streaming, no background responses, no conversations/files/URL retrieval. Retain the default implicit prompt caching with a 30-minute lifetime; do not select 12-hour caching. Only synthetic normalized fields and the approved public profile/prompt cross this boundary. Use the existing Laravel HTTP client behind a replaceable provider contract; no SDK or dependency installation is needed for planning.

Marcel initially approved `gpt-5.4-mini-2026-03-17` on 2026-10-27, then explicitly selected `gpt-6-luna` for the application and approved the revised cache-write-inclusive estimates/reservations below on the same day. The earlier model is superseded, not an automatic fallback. On 2026-10-28, a protected-key read-only model-metadata GET returned HTTP 200 (VERIFIED). Marcel confirmed a dedicated project/key with no other callers, API billing readiness, Responses creation permission, no model-improvement sharing opt-in and acceptance of standard documented retention without a ZDR claim (OPERATOR-REPORTED). These satisfy the planning account-input gate, not a claim of agent-inspected console settings or tested inference; see the [dated evidence](../runbooks/phase6-readiness.md#feasibility-evidence).

Official documentation consulted **2026-10-27**:

| Source | Documented fact (not an account/host observation) |
| --- | --- |
| [Model](https://developers.openai.com/api/docs/models/gpt-6-luna) | Documents `gpt-6-luna` as the available alias/default snapshot, Responses API, structured outputs and reasoning `none`; 1,050,000 context / 922,000 maximum input / 128,000 maximum output tokens. Our limits are much smaller. No dated immutable snapshot is listed. |
| [Pricing](https://developers.openai.com/api/docs/pricing) | Standard input USD 0.10 / million, cached input 0.01 / million, default 30-minute cache writes 0.125 / million, output 0.50 / million. Twelve-hour writes cost 0.25 / million and are not selected. Regional processing adds 10%; regional endpoints are not selected. Long-context premiums above 272,000 input tokens cannot apply within our 8,000-token input cap. |
| [Prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching) | Default implicit caching can create billable writes: 30-minute writes cost 1.25 times ordinary input; this is the write-token rate, not an additional ordinary-input charge for those same tokens. Cache reads and ordinary uncached input have their separate rates. Default cache retention is distinct from report retention and `store=false`. |
| [Structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs) | Strict schema support does not establish factual correctness; refusals and incomplete responses require explicit handling. |
| [Data controls](https://developers.openai.com/api/docs/guides/your-data) | API data is not used for training by default unless opted in. Abuse monitoring normally retains content up to 30 days, subject to documented legal/safety exceptions. ZDR/modified abuse monitoring require approval; neither is assumed. `store=false` is not a ZDR guarantee. |

This is a low-volume, structured text classification demonstration, not a need for a flagship model or tools. The owner's revised selection is documented for focused, high-volume tasks and supports explicit no-reasoning operation. It is not claimed to be the cheapest or to have measured recommendation quality. Because only the undated identifier is listed, do not claim immutable snapshot reproducibility: persist requested and returned model IDs, provider request ID, documentation/rate revision and request settings with attempt provenance. Recheck price, availability and settings before Slice 6.4; a newly available dated snapshot requires an explicit selection decision. No automatic alternative model or provider fallback.

## Allowlisted input and validated output (APPROVED design)

Input: source ID; title (160 characters); description (2,400 characters); up to 12 skills (40 characters each); contract type; known hourly USD min/max; maximum weekly hours; required devices; eligible locations; posted timestamp; proposal count; optional payment-verified, rating, spend-USD and client-country facts. Nullable facts remain null; field-specific string limits and final byte/token limits both apply. Profile/prompt are the fixed approved versions, not visitor input. Do not send recipients, headers, raw MIME, mailbox identities, credentials, tracking links, human feedback, private enrichments or private profiles. Exclude source URLs entirely.

Bound lists to 8 device strings of 120 characters and 20 location strings of 80 characters; client country to 80 characters. Contract type is hourly/fixed/null; nonnegative finite monetary values use at most two decimal places, weekly hours are 0-168, proposal count a nonnegative integer, rating 0-5, verification boolean/null and posted time ISO-8601/null. Reject inconsistent bounds/types or oversized content, never silently clip a hard requirement. Final serialized byte/token guards apply even when individual fields fit.

Response schema `ai-evaluation-v1`, all keys required, `additionalProperties=false` throughout; cap the received HTTP response at 64 KiB before decoding (oversize/absent usage retains the maximum attempt reservation):

- `source_id`: exact requested source identity.
- `recommendation`: enum APPLY / MAYBE / SKIP.
- `reasons`: 1-4 objects `{criterion_id, explanation, evidence}`; explanation at most 240 characters. Criterion ID must refer to the versioned rubric.
- `evidence`: within each reason, 1-3 objects `{field, quote}` referring only to allowed supplied input/profile fields; quote at most 160 characters and an exact substring of the cited normalized field's canonical text.
- `missing_information`: 0-8 unique allowlisted field/criterion identifiers.

Validate byte length, JSON/types/enums, all bounds, source identity, criterion/field membership and evidence quotes locally. Check approved hard constraints independently: a known incompatible requirement cannot receive APPLY; SKIP must cite an evidenced hard incompatibility, and a material unresolved requirement must produce MAYBE. An unknown specialist requirement is not automatically proof of incompatibility. Contradictory output is a validation failure, not a fabricated corrected AI result. Schema-valid unsupported semantic claims remain a known model risk; display evidence and the human-review caveat. Refusals, truncation, invalid output and unavailable AI are explicit item failures, never deterministic scores labelled AI.

An advertised maximum workload alone is not a mandatory minimum: never infer a hard availability exclusion solely from `hours_per_week_max > 20`. The authored exclusion cases also explicitly state a nonnegotiable over-20-hour commitment in their descriptions; require that evidence. The absence of client metrics alone need not prevent APPLY on an otherwise supported match; distinguish material requirement gaps from optional unknown facts.

## Estimates and ceiling

APPROVED: global **USD 1.00/day**, resetting at **00:00 UTC**, across all public visitors, retries and unresolved liabilities. This is API usage spend, not a promise about tax, FX or unrelated account activity; use a dedicated provider project/key with no other callers. Provider alerts are secondary, not a hard cap.

APPROVED planning envelope: **8,000 total input tokens**, including system/profile/schema/framing; **1,440 maximum output tokens**, including any billable reasoning; one initial attempt and at most one retry per item. `none` is explicit, but usage reconciliation still counts all billed output. Never estimate from just email text. Slice 6.2 must prove a conservative preflight input-token upper bound including framing/schema; absent a reliable bound, reject before dispatch, not after incurring spend. Serialized input also has a 12 KiB limit; reject oversized input rather than silently truncating requirements.

| Scenario, 20 items | Calculation in USD | Result |
| --- | --- | --- |
| Typical assumption: 2,000 input + 400 output tokens/item, no retries; conservatively price all input as new 30-minute cache writes | `20 * (2000 * 0.125 + 400 * 0.50) / 1,000,000` | **0.009000** |
| Maximum tokens, initial + one retry for every item; all input at the write rate, no cache-read savings | `40 * (8000 * 0.125 + 1440 * 0.50) / 1,000,000` | **0.068800** |
| Conservative reservation, 10% margin | `0.068800 * 1.10` | **0.075680**; round upward to **0.076** when displaying three decimals |

These are estimates, not measured usage. Each attempt reserves **1,892 micro-USD**; a full run reserves **75,680 micro-USD**. Price every input token at the higher default write rate for reservation safety, whether or not caching is eligible; do not assume cache hits. Reconcile disjoint ordinary-input, cache-read, cache-write and billable-output categories at their documented rates, without counting aggregate input again. Missing/ambiguous usage categories retain the full reservation until reconciliation. No tool/storage/search/batch/priority charge applies because those features are not selected. A change to endpoint, service tier, regional processing, cache lifetime/mode, reasoning, model or rates invalidates this envelope and requires renewed approval. The margin is not permission to increase caps.

Superseded planning history, not measured usage: the original model used USD 0.066000 typical / 0.499200 maximum / 0.549120 reserved per 20-item run, with 13,728 micro-USD per attempt. These values are not the current admission prices.

### Concurrent reservations and uncertain outcomes (APPROVED design)

Use integer micro-USD, round costs upward, and transactionally lock the global UTC-day budget row before run/item/attempt rows in a fixed order. Admission reserves the full selected maximum (20 items before discovery, release unused item allocations after discovery); require `settled + reserved + carried_uncertain + new_reservation <= 1,000,000`. Insufficient capacity/budget is a visible rejection, not an unlimited queue. One active/two queued remains the capacity ceiling even though three full current-model reservations total only USD 0.227040; accumulated settled usage and carried liabilities can still prevent admission.

Before any network call, commit a unique attempt row and transfer its allocation from the run's reserved pool into an in-flight allocation; never briefly release it. Dispatch after commit. Workers must claim a database lease/fencing token and recheck deadline, kill switch, snapshot and reservation immediately before calling. Queue redelivery cannot create another attempt for an in-flight/settled attempt identity.

On a complete response, persist validated result or explicit failure and provider request ID plus ordinary-input/cache-read/cache-write/output usage and requested/returned model provenance, then settle actual cost and release only the proven-unused allocation in one transaction. Invalid JSON/refusal still incurs measured usage. Retry needs its own pre-reserved allocation; at most two attempts total. Do not assume HTTP-library automatic retries or provider POST idempotency.

Timeout after possible send, connection loss, ambiguous 5xx, or crash between response and commit becomes `uncertain`: retain the **full attempt maximum**, do not resend, and freeze further paid dispatch until the single-provider-slot outcome is resolved. Operator reconciliation may use protected provider usage/billing evidence; `store=false` means response retrieval is not a recovery guarantee. A proven pre-send failure or explicit nonprocessed rate-limit response can use the one retry; a complete schema-invalid result can retry with a fresh allocation. Permanent authentication/permission errors stop the run and require operator repair.

At UTC rollover do not clear old liabilities. Track each attempt's dispatch day; carry unresolved reserved/uncertain allocations as deductions against each new day's available capacity until settled. A dispatch crossing midnight must be reauthorized against the new day's row before sending. Old-day accounting remains immutable evidence; carry is conservative and does not create extra capacity. A stale lease/report expiry never refunds an uncertain attempt. Retain the minimal non-content budget/attempt ledger until reconciliation, even after seven-day report deletion.

## Consequences

The application can reject new runs well before the monetary limit when conservative reservations consume headroom. It cannot retroactively stop already-sent requests; pre-reservation is mandatory. Missing prices, usage, approval/configuration or a safe token bound fail closed. Slice 6.2 must test atomic concurrent admission, duplicate delivery, uncertain outcomes and UTC rollover offline. Slice 6.1 account readiness is supported by verified model metadata and explicitly owner-reported billing/permission/data settings, not paid testing; Slice 6.4 alone measures real inference usage and verifies integrated live behavior.
