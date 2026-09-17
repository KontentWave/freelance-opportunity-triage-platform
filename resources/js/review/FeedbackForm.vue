<script setup>
import { nextTick, ref } from "vue";
import { reviewRequest } from "./http.js";

const props = defineProps({ opportunity: { type: Object, required: true } });
const emit = defineEmits(["saved", "started"]);
const current = props.opportunity.current_review;
const humanLabel = ref(current?.human_label ?? "");
const reasonCode = ref(current?.reason_code ?? "");
const notes = ref(current?.notes ?? "");
const outcome = ref(current?.outcome ?? "");
const sampleKind = ref(current?.sample_kind ?? "demo");
const saving = ref(false);
const errors = ref({});
const failure = ref("");
const status = ref("");
const errorSummary = ref(null);

async function submit() {
    emit("started");
    saving.value = true;
    errors.value = {};
    failure.value = "";
    status.value = "";

    try {
        const payload = await reviewRequest(
            `/review/v1/opportunities/${props.opportunity.id}/review`,
            {
                method: "PUT",
                body: JSON.stringify({
                    evaluation_id: props.opportunity.evaluation_id,
                    enrichment_id: props.opportunity.current_enrichment_id,
                    human_label: humanLabel.value,
                    reason_code: reasonCode.value || null,
                    notes: notes.value || null,
                    outcome: outcome.value || null,
                    sample_kind: sampleKind.value,
                }),
            },
        );
        status.value = "Feedback saved.";
        emit("saved", payload.data, status.value);
    } catch (requestError) {
        errors.value = requestError.errors ?? {};
        failure.value =
            requestError.status === 409
                ? "This feedback is based on an older context. Reload before saving."
                : requestError.status === 401 || requestError.status === 419
                  ? "Your session expired. Sign in again; your draft remains on this page."
                  : requestError.status === 422
                    ? "Check the highlighted fields."
                    : requestError.status === undefined
                      ? "You appear to be offline. Your draft has not been cleared."
                      : requestError.message;
        await nextTick();
        errorSummary.value?.focus();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="form-section" aria-labelledby="feedback-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Human judgment</p>
                <h2 id="feedback-heading">Record your decision</h2>
            </div>
            <p class="judgment-note">
                A reason is required when your label differs from the original
                email suggestion.
            </p>
        </div>
        <div
            v-if="failure"
            ref="errorSummary"
            class="error-banner"
            role="alert"
            tabindex="-1"
        >
            {{ failure }}
        </div>
        <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>

        <form class="review-form" @submit.prevent="submit">
            <fieldset>
                <legend>Your suitability label</legend>
                <div class="choice-row">
                    <label
                        v-for="label in ['APPLY', 'MAYBE', 'SKIP']"
                        :key="label"
                        ><input
                            v-model="humanLabel"
                            type="radio"
                            name="human-label"
                            :value="label"
                            required
                        />
                        {{ label }}</label
                    >
                </div>
                <p v-if="errors.human_label" class="field-error">
                    {{ errors.human_label[0] }}
                </p>
            </fieldset>

            <div class="form-grid">
                <div class="field-group">
                    <label for="reason-code">Disagreement reason</label>
                    <select
                        id="reason-code"
                        v-model="reasonCode"
                        :aria-invalid="Boolean(errors.reason_code)"
                        :aria-describedby="
                            errors.reason_code ? 'reason-error' : undefined
                        "
                    >
                        <option value="">No disagreement</option>
                        <option value="fit">Fit</option>
                        <option value="availability">Availability</option>
                        <option value="economics">Economics</option>
                        <option value="client_risk">Client risk</option>
                        <option value="missing_information">
                            Missing information
                        </option>
                        <option value="other">Other</option>
                    </select>
                    <p
                        v-if="errors.reason_code"
                        id="reason-error"
                        class="field-error"
                    >
                        {{ errors.reason_code[0] }}
                    </p>
                </div>
                <div class="field-group">
                    <label for="outcome">Outcome</label>
                    <select
                        id="outcome"
                        v-model="outcome"
                        :aria-invalid="Boolean(errors.outcome)"
                        :aria-describedby="
                            errors.outcome ? 'outcome-error' : undefined
                        "
                    >
                        <option value="">Not recorded</option>
                        <option value="not_applied">Not applied</option>
                        <option value="applied">Applied</option>
                        <option value="in_discussion">In discussion</option>
                        <option value="hired">Hired</option>
                        <option value="closed">Closed</option>
                    </select>
                    <p
                        v-if="errors.outcome"
                        id="outcome-error"
                        class="field-error"
                    >
                        {{ errors.outcome[0] }}
                    </p>
                </div>
                <div class="field-group">
                    <label for="sample-kind">Sample provenance</label>
                    <select
                        id="sample-kind"
                        v-model="sampleKind"
                        :aria-invalid="Boolean(errors.sample_kind)"
                        :aria-describedby="
                            errors.sample_kind
                                ? 'sample-help sample-error'
                                : 'sample-help'
                        "
                    >
                        <option value="demo">Demo</option>
                        <option value="real">Real alert</option>
                    </select>
                    <p id="sample-help" class="field-help">
                        Real means this came from a genuine alert and requires
                        the active personal profile.
                    </p>
                    <p
                        v-if="errors.sample_kind"
                        id="sample-error"
                        class="field-error"
                    >
                        {{ errors.sample_kind[0] }}
                    </p>
                </div>
                <div class="field-group field-wide">
                    <label for="review-notes">Notes</label>
                    <textarea
                        id="review-notes"
                        v-model="notes"
                        rows="5"
                        maxlength="2000"
                        :aria-invalid="Boolean(errors.notes)"
                        :aria-describedby="
                            errors.notes
                                ? 'notes-help notes-error'
                                : 'notes-help'
                        "
                    />
                    <p id="notes-help" class="field-help">
                        Optional private notes, up to 2,000 characters.
                    </p>
                    <p v-if="errors.notes" id="notes-error" class="field-error">
                        {{ errors.notes[0] }}
                    </p>
                </div>
            </div>

            <button class="primary-button" type="submit" :disabled="saving">
                {{ saving ? "Saving…" : "Save feedback" }}
            </button>
        </form>
    </section>
</template>
