<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    sourceMode: { type: String, default: "authored" },
    intentUrl: { type: String, required: true },
    admissionUrl: { type: String, required: true },
    statusBase: { type: String, required: true },
});
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
const serverSourceMode = ref(null);
const upworkMode = computed(() => (serverSourceMode.value ?? props.sourceMode) === "upwork");
const available = ref(false);
const ready = ref(false);
const busy = ref(false);
const admitting = ref(false);
const submissionButton = ref(null);
const run = ref(null);
const key = ref(null);
const keyExpiry = ref(null);
const cooldownUntil = ref(null);
const reusedBatch = ref(false);
const error = ref("");
const inaccessible = ref(false);
const expired = ref(false);
const paused = ref(false);
const intentFailed = ref(false);
const submissionInvalid = ref(false);
const prepared = ref(false);
const currentTime = ref(Date.now());
const nextRequestAt = ref(0);
const terminal = computed(() => run.value && ["completed", "partial", "failed", "rejected"].includes(run.value.status));
const keyExpired = computed(() => keyExpiry.value !== null && keyExpiry.value * 1000 <= currentTime.value);
const coolingDown = computed(() => currentTime.value < nextRequestAt.value);
const onBatchCooldown = computed(() => upworkMode.value && cooldownUntil.value !== null && cooldownUntil.value * 1000 > currentTime.value);
const progressPercent = computed(() => {
    if (!run.value || !Number.isInteger(run.value.selected_count) || run.value.selected_count <= 0) return 0;
    const done = (run.value.succeeded_count ?? 0) + (run.value.failed_count ?? 0);
    return Math.min(100, Math.round((done / run.value.selected_count) * 100));
});
let generation = 0;
let controller;
let timer;
let availabilityTimer;
let failures = 0;
let nextStatusAt = 0;

const statusText = computed(() => {
    if (admitting.value) return "Admission pending. No confirmed run yet.";
    if (expired.value) return "This report has expired. Evaluation will not restart.";
    if (inaccessible.value) return "This run is unavailable to this session.";
    if (!ready.value) return "Loading your evaluation session.";
    if (!run.value && keyExpired.value) return "This submission window expired. Prepare a new evaluation explicitly.";
    if (!run.value) return available.value ? "Ready for a deliberate evaluation request." : "Live evaluation is disabled or unavailable.";
    const labels = {
        queued: "Queued. Waiting for the bounded demo worker.",
        running: "Running. Showing actual stored counts, not an estimated percentage.",
        completed: run.value.selected_count === 0 ? "Completed with an empty selection. No alerts were evaluated." : "Completed.",
        partial: "Partial. Some alerts could not be evaluated.",
        failed: "Failed. No confirmed successful evaluation.",
        rejected: "Rejected. No evaluation was started.",
        loading: "Checking the selected run in this session.",
    };
    return labels[run.value.status] ?? "Evaluation state unavailable.";
});

function refreshAvailability() {
    clearTimeout(availabilityTimer);
    currentTime.value = Date.now();
    const deadlines = [
        nextRequestAt.value,
        keyExpiry.value === null ? 0 : keyExpiry.value * 1000,
        cooldownUntil.value === null ? 0 : cooldownUntil.value * 1000,
    ].filter((deadline) => deadline > currentTime.value);
    if (deadlines.length) {
        availabilityTimer = setTimeout(refreshAvailability, Math.min(Math.min(...deadlines) - currentTime.value, 2147483647));
    }
}

function formatCooldownTime(unixSeconds) {
    if (!unixSeconds) return "";
    try {
        return new Date(unixSeconds * 1000).toISOString().replace("T", " ").slice(0, 16) + " UTC";
    } catch {
        return "";
    }
}

function formatTerms(input) {
    if (!input) return null;
    const parts = [];
    if (input.contract_type === "hourly") {
        parts.push(
            input.hourly_min_usd !== null && input.hourly_max_usd !== null
                ? `Hourly: $${input.hourly_min_usd}–$${input.hourly_max_usd}`
                : "Hourly (rate unspecified)",
        );
    } else if (input.contract_type === "fixed") {
        parts.push(input.fixed_budget_usd !== null ? `Fixed-price: $${input.fixed_budget_usd}` : "Fixed-price (budget unspecified)");
    }
    if (input.estimated_duration) parts.push(`Est. ${input.estimated_duration}`);
    if (input.client_payment_verified === true) parts.push("Payment verified");
    if (input.client_rating !== null && input.client_rating !== undefined) parts.push(`${input.client_rating}★`);
    if (input.client_spend_usd !== null && input.client_spend_usd !== undefined) parts.push(`$${input.client_spend_usd} spent`);
    if (input.client_country) parts.push(input.client_country);
    return parts.join(" · ");
}

function itemBadgeLabel(item) {
    if (item.status === "succeeded" && item.result?.recommendation) return item.result.recommendation;
    const map = {
        pending: "QUEUED",
        in_flight: "EVALUATING",
        retry_wait: "RETRYING",
        failed: "UNEVALUATED",
        uncertain: "UNCERTAIN",
        expired: "EXPIRED",
    };
    return map[item.status] ?? item.status?.toUpperCase() ?? "PENDING";
}

function itemBadgeClass(item) {
    if (item.status === "succeeded" && item.result?.recommendation) {
        return `suggestion-${item.result.recommendation.toLowerCase()}`;
    }
    return "suggestion-unscored";
}

function resetRequests() {
    generation += 1;
    clearTimeout(timer);
    controller?.abort();
    busy.value = false;
    admitting.value = false;
    return generation;
}

function retryDelay(value) {
    if (!value) return 2000;
    const seconds = Number(value);
    const delay = Number.isFinite(seconds) ? seconds * 1000 : Date.parse(value) - Date.now();
    return Number.isFinite(delay) ? Math.max(2000, delay) : 2000;
}

async function request(url, method = "GET", data = null) {
    const activeController = new AbortController();
    controller = activeController;
    const timeout = setTimeout(() => activeController.abort(), 15000);
    try {
        const response = await fetch(url, {
            method,
            credentials: "same-origin",
            signal: activeController.signal,
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrfToken ?? "",
            },
            ...(data === null ? {} : { body: JSON.stringify(data) }),
        });
        const delay = retryDelay(response.headers.get("Retry-After"));
        let payload = null;
        if (response.headers.get("Content-Type")?.includes("application/json")) {
            payload = await response.json();
        }
        return { status: response.status, ok: response.ok, payload, delay };
    } finally {
        clearTimeout(timeout);
    }
}

function selected(record) {
    run.value = record;
    expired.value = Boolean(record?.expires_at && Date.parse(record.expires_at) <= Date.now());
    const url = new URL(window.location.href);
    if (record) url.searchParams.set("run", record.id);
    else url.searchParams.delete("run");
    window.history.replaceState({}, "", url);
}

function denial(status) {
    if ([401, 403, 404, 419].includes(status)) {
        inaccessible.value = true;
        error.value = "Your session was lost, or this run is not available to it. Re-enter the demo to continue; no new evaluation has been started.";
        return true;
    }
    if (status === 410) {
        expired.value = true;
        error.value = "";
        return true;
    }
    return false;
}

function schedule(token, delay = 2000) {
    if (token !== generation || terminal.value || expired.value || inaccessible.value) return;
    const wait = Math.max(2000, delay, nextStatusAt - Date.now());
    timer = setTimeout(() => {
        if (Date.now() < nextStatusAt) return schedule(token);
        poll(token);
    }, Math.min(wait, 2147483647));
}

async function poll(token) {
    if (token !== generation || !run.value || terminal.value || expired.value || inaccessible.value) return;
    const id = run.value.id;
    nextStatusAt = Date.now() + 2000;
    try {
        const response = await request(`${props.statusBase}/${encodeURIComponent(id)}`);
        if (token !== generation || run.value?.id !== id) return;
        nextStatusAt = Math.max(nextStatusAt, Date.now() + response.delay);
        if (denial(response.status)) return;
        if (!response.ok || response.payload?.id !== id) {
            throw new Error("Status temporarily unavailable");
        }
        run.value = response.payload;
        error.value = "";
        failures = 0;
        paused.value = false;
        schedule(token);
    } catch {
        if (token !== generation || run.value?.id !== id) return;
        failures += 1;
        error.value = failures >= 3
            ? "Status checks paused after three temporary failures. Resume to check the same run; it will not be evaluated again."
            : "Status temporarily unavailable. The same run will be checked again.";
        paused.value = failures >= 3;
        if (!paused.value) schedule(token, Math.max(2000 * 2 ** failures, nextStatusAt - Date.now()));
    }
}

async function loadIntent(fresh = false) {
    if (busy.value) return;
    const token = resetRequests();
    busy.value = true;
    error.value = "";
    intentFailed.value = false;
    try {
        const response = await request(props.intentUrl, fresh ? "POST" : "GET");
        if (token !== generation) return;
        nextRequestAt.value = Date.now() + response.delay;
        refreshAvailability();
        if (denial(response.status)) return;
        if (!response.ok || typeof response.payload?.available !== "boolean") {
            intentFailed.value = true;
            error.value = "Evaluation is temporarily unavailable. Retry loading the same submission intent.";
            return;
        }
        if (typeof response.payload.source_mode === "string") {
            serverSourceMode.value = response.payload.source_mode;
        }
        available.value = response.payload.available;
        key.value = response.payload.idempotency_key;
        keyExpiry.value = response.payload.key_expires_at;
        cooldownUntil.value = Number.isInteger(response.payload.cooldown_until) ? response.payload.cooldown_until : null;
        reusedBatch.value = Boolean(response.payload.reused_batch);
        refreshAvailability();
        submissionInvalid.value = false;
        prepared.value = fresh;
        const requested = fresh ? null : new URLSearchParams(window.location.search).get("run");
        if (requested && requested !== response.payload.run?.id) {
            selected({ id: requested, status: "loading" });
        } else {
            selected(response.payload.run);
        }
        failures = 0;
        paused.value = false;
        if (run.value) schedule(token);
    } catch {
        if (token !== generation) return;
        intentFailed.value = true;
        error.value = "Evaluation is temporarily unavailable. Retry loading the same submission intent.";
    } finally {
        if (token === generation) {
            ready.value = true;
            busy.value = false;
            if (fresh && !intentFailed.value && !inaccessible.value) {
                await nextTick();
                submissionButton.value?.focus();
            }
        }
    }
}

function delayed(action, notBefore) {
    if (Date.now() >= notBefore) return action();
    busy.value = true;
    timer = setTimeout(() => {
        busy.value = false;
        delayed(action, notBefore);
    }, Math.min(notBefore - Date.now(), 2147483647));
}

async function submit() {
    refreshAvailability();
    if (busy.value || !key.value || run.value || inaccessible.value || intentFailed.value
        || submissionInvalid.value || keyExpired.value || coolingDown.value) return;
    const token = resetRequests();
    busy.value = true;
    admitting.value = true;
    error.value = "";
    nextRequestAt.value = Date.now() + 2000;
    refreshAvailability();
    try {
        const response = await request(props.admissionUrl, "POST", { idempotency_key: key.value });
        if (token !== generation) return;
        nextRequestAt.value = Date.now() + response.delay;
        refreshAvailability();
        if (denial(response.status)) return;
        if (response.payload?.id && [200, 202, 429].includes(response.status)) {
            if (Number.isInteger(response.payload.cooldown_until)) {
                cooldownUntil.value = response.payload.cooldown_until;
            }
            reusedBatch.value = Boolean(response.payload.reused_batch);
            refreshAvailability();
            selected(response.payload);
            failures = 0;
            schedule(token, response.delay);
        } else {
            submissionInvalid.value = response.status === 422;
            error.value = response.status === 422
                ? "The submission window or approved inputs changed. Request a new evaluation explicitly."
                : response.status === 429
                  ? "Request limit reached. Wait before retrying this same request."
                  : "Admission could not be confirmed. Retry the same request; a new evaluation will not be created.";
        }
    } catch {
        if (token === generation) error.value = "Admission could not be confirmed. Retry the same request; a new evaluation will not be created.";
    } finally {
        if (token === generation) {
            busy.value = false;
            admitting.value = false;
        }
    }
}

function resume() {
    if (busy.value || !run.value) return;
    const token = resetRequests();
    failures = 0;
    paused.value = false;
    schedule(token);
}

function newIntent() {
    if (busy.value || (run.value && !terminal.value && !expired.value)) return;
    delayed(() => loadIntent(true), nextRequestAt.value);
}

onMounted(() => loadIntent());
onBeforeUnmount(() => {
    resetRequests();
    clearTimeout(availabilityTimer);
});
</script>

<template>
    <div class="review-shell grid gap-6">
        <header class="app-header">
            <div class="grid gap-4">
                <p class="eyebrow">{{ upworkMode ? "Live incoming Upwork alerts" : "Fictional / synthetic demo alerts" }}</p>
                <h1>{{ upworkMode ? "Fresh Upwork alert evaluation" : "Evaluate authored alerts" }}</h1>
                <p>
                    {{
                        upworkMode
                            ? "Pulls up to 20 newest direct-link Upwork job-alert emails over TLS IMAP and evaluates them live with OpenAI against Marcel's approved public profile. Protected by a 30-minute refresh cooldown and daily budget cap."
                            : "Request evaluation of up to 20 approved fictional alerts against Marcel's approved public profile, not your profile. Suggestions require human review."
                    }}
                </p>
                <a class="inline-link" href="/opportunities">Return to seeded review queue</a>
            </div>
        </header>
        <section class="filter-band grid grid-cols-1 gap-4" aria-labelledby="evaluation-heading">
            <h2 id="evaluation-heading">Evaluation run</h2>
            <p v-if="ready && !available">Live mode is disabled or unavailable. The seeded review queue remains a static, synthetic demonstration, not a live AI result.</p>
            <p role="status" aria-live="polite" aria-atomic="true">{{ statusText }}</p>
            <p v-if="upworkMode && reusedBatch && run" class="text-sm text-[#4a5750]">
                Showing the latest evaluated Upwork batch<span v-if="onBatchCooldown"> — next live mailbox refresh available after {{ formatCooldownTime(cooldownUntil) }} (30-minute cooldown)</span>.
            </p>
            <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
            <a v-if="inaccessible" href="/demo" class="inline-link">Re-enter demo</a>
            <button v-if="intentFailed" type="button" class="secondary-button" :disabled="busy" @click="delayed(() => loadIntent(), nextRequestAt)">Retry loading session</button>
            <template v-if="ready && available && !inaccessible && !intentFailed">
                <p v-if="!run && keyExpired">This submission window expired. A new evaluation requires a deliberate new action.</p>
                <button v-if="terminal || expired || (!run && (prepared || keyExpired || submissionInvalid))" type="button" class="secondary-button"
                    :disabled="busy || onBatchCooldown || (!run && prepared && !keyExpired && !submissionInvalid)" @click="newIntent">
                    {{ upworkMode ? (onBatchCooldown ? `30-min refresh cooldown active (until ${formatCooldownTime(cooldownUntil)})` : "Check & load newer Upwork alerts") : "Prepare a new evaluation" }}
                </button>
                <button v-if="!run && !keyExpired && !submissionInvalid" ref="submissionButton" type="button" class="primary-button" :disabled="busy || !key || onBatchCooldown" :aria-disabled="coolingDown" @click="submit">
                    {{ busy ? "Admission pending" : error ? "Retry same request" : upworkMode ? "Load & evaluate latest Upwork alerts" : "Evaluate up to 20 fictional alerts" }}
                </button>
                <p v-if="!run && !keyExpired && coolingDown">Please wait for the request cooldown, then activate Evaluate explicitly. No evaluation is scheduled.</p>
            </template>
        </section>
        <section v-if="run && !expired && !inaccessible" class="filter-band grid grid-cols-1 gap-4" aria-labelledby="progress-heading">
            <h2 id="progress-heading">Stored progress</h2>
            <div class="grid min-w-0 gap-3">
                <p class="break-all">Run {{ run.id }}</p>
                <p v-if="Number.isInteger(run.selected_count)">{{ run.selected_count }} selected; {{ run.succeeded_count }} evaluated successfully; {{ run.failed_count }} unsuccessful or unconfirmed.</p>
                <div v-if="Number.isInteger(run.selected_count) && run.selected_count > 0" class="h-2.5 w-full overflow-hidden rounded bg-[#dce8df]" aria-hidden="true">
                    <div class="h-full bg-[#174d3b] transition-all duration-300" :style="{ width: `${progressPercent}%` }"></div>
                </div>
                <p v-if="run.status === 'queued'">Selection has not completed yet; zero selected does not mean an empty inbox. The minute cron worker starts within 60 seconds.</p>
                <p v-else-if="run.status === 'running'" class="text-sm text-[#4a5750]">Bounded cron worker processes alerts sequentially (previously evaluated alerts reuse immediately; new alerts take ~5–10s each). Recommendations appear live below.</p>
                <p v-if="run.explanation">{{ run.explanation }}</p>
                <p v-if="run.admitted_at" class="text-sm text-[#4a5750]">Batch requested (UTC): {{ run.admitted_at }}</p>
                <p v-if="run.finalized_at">Finalized: {{ run.finalized_at }}</p>
                <p v-if="run.expires_at">Report expires: {{ run.expires_at }}</p>
                <button v-if="paused" type="button" class="secondary-button" :disabled="busy" @click="resume">Resume status checks</button>
                <div v-if="terminal && run.report_url" class="flex flex-wrap gap-4">
                    <a :href="run.report_url" class="primary-button">Open stored report</a>
                    <a :href="run.download_url" class="secondary-button" download>Download matching HTML</a>
                </div>
                <p v-else-if="terminal">A stored report is not available for this historical run. Opening this run never restarts evaluation.</p>
            </div>
        </section>
        <section v-if="run && !expired && !inaccessible && Array.isArray(run.items) && run.items.length" class="grid gap-4" aria-labelledby="live-items-heading">
            <h2 id="live-items-heading">{{ upworkMode ? `Evaluated Upwork alerts (${run.items.length})` : `Alerts in this run (${run.items.length})` }}</h2>
            <div class="grid gap-4">
                <article v-for="item in run.items" :key="item.source_id" class="grid min-w-0 gap-3 border-t-2 border-[#718078] bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="['suggestion', itemBadgeClass(item)]">{{ itemBadgeLabel(item) }}</span>
                            <span class="text-xs font-bold uppercase tracking-wide text-[#536159]">{{ item.source_id }}</span>
                        </div>
                        <span v-if="item.input?.posted_at" class="text-xs text-[#536159]">Alert / posted UTC: {{ item.input.posted_at }}</span>
                    </div>
                    <div class="grid min-w-0 gap-1">
                        <h3 class="text-lg font-semibold leading-snug break-words">{{ item.input?.title ?? item.source_id }}</h3>
                        <p v-if="formatTerms(item.input)" class="text-sm font-medium text-[#4a5750] break-words">{{ formatTerms(item.input) }}</p>
                        <p v-if="item.canonical_url">
                            <a :href="item.canonical_url" target="_blank" rel="noopener noreferrer" class="inline-link text-sm font-semibold break-all">View job on Upwork ({{ item.canonical_url }})</a>
                        </p>
                    </div>
                    <p v-if="item.input?.description" class="text-sm leading-relaxed text-[#2c3832] break-words">{{ item.input.description }}</p>
                    <div v-if="Array.isArray(item.input?.skills) && item.input.skills.length" class="flex flex-wrap gap-1.5">
                        <span v-for="skill in item.input.skills" :key="skill" class="rounded bg-[#e8efe9] px-2 py-0.5 text-xs font-medium text-[#174d3b]">{{ skill }}</span>
                    </div>
                    <div v-if="item.status === 'succeeded' && item.result" class="grid min-w-0 gap-2 border-t border-[#dce3de] pt-2">
                        <div v-for="(reason, rIdx) in item.result.reasons ?? []" :key="rIdx" class="grid min-w-0 gap-1 text-sm">
                            <p class="break-words"><strong class="uppercase text-xs tracking-wide text-[#174d3b]">{{ reason.criterion_id }}:</strong> {{ reason.explanation }}</p>
                            <blockquote v-for="(ev, eIdx) in reason.evidence ?? []" :key="eIdx" class="border-l-2 border-[#174d3b] pl-3 text-xs italic text-[#4a5750] break-words">
                                “{{ ev.quote }}” <span class="not-italic font-semibold">({{ ev.field }})</span>
                            </blockquote>
                        </div>
                        <p v-if="Array.isArray(item.result.missing_information) && item.result.missing_information.length" class="text-xs font-medium text-[#895800] break-words">
                            Needs clarification: {{ item.result.missing_information.join(", ") }}
                        </p>
                    </div>
                    <p v-else-if="item.explanation" class="text-sm text-[#a32f23]">{{ item.explanation }}</p>
                </article>
            </div>
        </section>
    </div>
</template>
