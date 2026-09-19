<script setup>
import { nextTick, reactive, ref } from "vue";
import { reviewRequest } from "./http.js";

const props = defineProps({ opportunity: { type: Object, required: true } });
const emit = defineEmits(["saved", "started"]);
const demoMode = props.opportunity.demo?.enabled === true;
const demoPresets = props.opportunity.demo?.presets ?? {};
const presetKey = ref(Object.keys(demoPresets)[0] ?? "");
const existingOverrides = props.opportunity.current_enrichment?.overrides ?? {};
const currentInput =
    props.opportunity.current_enrichment?.input ??
    props.opportunity.email_input ??
    {};
const fullDescription = ref(
    props.opportunity.current_enrichment?.full_description ?? "",
);
const modes = reactive({
    contract_type: modeFor("contract_type"),
    currency: modeFor("currency"),
    hourly_max: modeFor("hourly_max"),
    skills: Object.hasOwn(existingOverrides, "skills") ? "value" : "inherit",
    hidden_skill_count: Object.hasOwn(existingOverrides, "hidden_skill_count")
        ? "value"
        : "inherit",
    payment_verified: paymentMode(),
    client_rating: modeFor("client_rating"),
});
const values = reactive({
    currency: currentInput.currency ?? "USD",
    hourly_max: currentInput.hourly_max ?? "",
    skills: (currentInput.skills ?? []).join("\n"),
    hidden_skill_count: currentInput.hidden_skill_count ?? 0,
    client_rating: currentInput.client_rating ?? "",
});
const saving = ref(false);
const errors = ref({});
const failure = ref("");
const status = ref("");
const errorSummary = ref(null);

function modeFor(key) {
    if (!Object.hasOwn(existingOverrides, key)) return "inherit";
    return existingOverrides[key] === null ? "unknown" : "value";
}

function paymentMode() {
    if (!Object.hasOwn(existingOverrides, "payment_verified")) return "inherit";
    if (existingOverrides.payment_verified === null) return "unknown";
    return existingOverrides.payment_verified ? "true" : "false";
}

function describedBy(field, helpId) {
    return errors.value[field] ? `${helpId} ${field}-error` : helpId;
}

function sparseOverrides() {
    const overrides = {};
    const nullableValues = {
        contract_type: "hourly",
        currency: values.currency,
        hourly_max: values.hourly_max,
        client_rating: values.client_rating,
    };

    for (const [key, value] of Object.entries(nullableValues)) {
        if (modes[key] === "value") overrides[key] = value;
        if (modes[key] === "unknown") overrides[key] = null;
    }

    if (modes.skills === "value") {
        overrides.skills = values.skills
            .split("\n")
            .map((skill) => skill.trim())
            .filter(Boolean);
    }
    if (modes.hidden_skill_count === "value") {
        overrides.hidden_skill_count = Number(values.hidden_skill_count);
    }
    if (modes.payment_verified !== "inherit") {
        overrides.payment_verified =
            modes.payment_verified === "unknown"
                ? null
                : modes.payment_verified === "true";
    }

    return overrides;
}

async function submit() {
    emit("started");
    saving.value = true;
    errors.value = {};
    failure.value = "";
    status.value = "";

    try {
        const requestBody = demoMode
            ? {
                  evaluation_id: props.opportunity.evaluation_id,
                  expected_enrichment_id:
                      props.opportunity.current_enrichment_id,
                  preset_key: presetKey.value,
              }
            : {
                  evaluation_id: props.opportunity.evaluation_id,
                  expected_enrichment_id:
                      props.opportunity.current_enrichment_id,
                  full_description: fullDescription.value,
                  overrides: sparseOverrides(),
              };
        const payload = await reviewRequest(
            `/review/v1/opportunities/${props.opportunity.id}/enrichments`,
            {
                method: "POST",
                body: JSON.stringify(requestBody),
            },
        );
        fullDescription.value =
            payload.data.current_enrichment.full_description;
        status.value = "Confirmed details saved.";
        emit("saved", payload.data, status.value);
    } catch (requestError) {
        errors.value = requestError.errors ?? {};
        failure.value =
            requestError.status === 409
                ? "These details are based on an older context. Reload before saving."
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
    <section class="form-section" aria-labelledby="enrichment-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Manual context</p>
                <h2 id="enrichment-heading">Confirm additional details</h2>
            </div>
            <p class="judgment-note">
                {{
                    demoMode
                        ? "Choose a fixed synthetic preset. Visitor-supplied descriptions and overrides are not accepted."
                        : "Pasted text supports your review. Only fields you explicitly confirm affect the score."
                }}
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
            <div v-if="demoMode" class="field-group">
                <label for="demo-preset">Synthetic confirmation preset</label>
                <select
                    id="demo-preset"
                    v-model="presetKey"
                    required
                    :aria-invalid="Boolean(errors.preset_key)"
                    :aria-describedby="
                        errors.preset_key ? 'preset-error' : 'preset-help'
                    "
                >
                    <option
                        v-for="(label, key) in demoPresets"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
                <p id="preset-help" class="field-help">
                    The server resolves this key to fixed synthetic text and
                    confirmed fields.
                </p>
                <p
                    v-if="errors.preset_key"
                    id="preset-error"
                    class="field-error"
                >
                    {{ errors.preset_key[0] }}
                </p>
            </div>

            <div v-else class="field-group">
                <label for="full-description">Full description</label>
                <textarea
                    id="full-description"
                    v-model="fullDescription"
                    rows="9"
                    maxlength="20000"
                    required
                    aria-describedby="description-help full_description-error"
                    :aria-invalid="Boolean(errors.full_description)"
                />
                <p id="description-help" class="field-help">
                    Plain text, 1 to 20,000 characters. It is never scored
                    automatically.
                </p>
                <p
                    v-if="errors.full_description"
                    id="full_description-error"
                    class="field-error"
                >
                    {{ errors.full_description[0] }}
                </p>
            </div>

            <fieldset v-if="!demoMode">
                <legend>Confirmed scoring details</legend>
                <div class="override-grid">
                    <div class="field-group">
                        <label for="contract-mode">Contract type</label>
                        <select
                            id="contract-mode"
                            v-model="modes.contract_type"
                            :aria-describedby="
                                describedBy(
                                    'overrides.contract_type',
                                    'contract-help',
                                )
                            "
                        >
                            <option value="inherit">Use email value</option>
                            <option value="value">Hourly</option>
                            <option value="unknown">Unknown</option>
                        </select>
                        <p id="contract-help" class="field-help">
                            Confirm hourly, mark unknown, or retain the email
                            value.
                        </p>
                        <p
                            v-if="errors['overrides.contract_type']"
                            id="overrides.contract_type-error"
                            class="field-error"
                        >
                            {{ errors["overrides.contract_type"][0] }}
                        </p>
                    </div>

                    <div class="field-group">
                        <label for="currency-mode">Currency</label>
                        <select id="currency-mode" v-model="modes.currency">
                            <option value="inherit">Use email value</option>
                            <option value="value">Confirm value</option>
                            <option value="unknown">Unknown</option>
                        </select>
                        <input
                            v-if="modes.currency === 'value'"
                            id="currency-value"
                            v-model="values.currency"
                            maxlength="3"
                            aria-label="Confirmed currency code"
                            :aria-invalid="
                                Boolean(errors['overrides.currency'])
                            "
                            :aria-describedby="
                                errors['overrides.currency']
                                    ? 'currency-error'
                                    : undefined
                            "
                        />
                        <p
                            v-if="errors['overrides.currency']"
                            id="currency-error"
                            class="field-error"
                        >
                            {{ errors["overrides.currency"][0] }}
                        </p>
                    </div>

                    <div class="field-group">
                        <label for="hourly-mode">Maximum hourly rate</label>
                        <select id="hourly-mode" v-model="modes.hourly_max">
                            <option value="inherit">Use email value</option>
                            <option value="value">Confirm value</option>
                            <option value="unknown">Unknown</option>
                        </select>
                        <input
                            v-if="modes.hourly_max === 'value'"
                            id="hourly-value"
                            v-model="values.hourly_max"
                            inputmode="decimal"
                            placeholder="40.00"
                            aria-label="Confirmed maximum hourly rate"
                            :aria-invalid="
                                Boolean(errors['overrides.hourly_max'])
                            "
                            :aria-describedby="
                                errors['overrides.hourly_max']
                                    ? 'hourly-error'
                                    : undefined
                            "
                        />
                        <p
                            v-if="errors['overrides.hourly_max']"
                            id="hourly-error"
                            class="field-error"
                        >
                            {{ errors["overrides.hourly_max"][0] }}
                        </p>
                    </div>

                    <div class="field-group">
                        <label for="rating-mode">Client rating</label>
                        <select id="rating-mode" v-model="modes.client_rating">
                            <option value="inherit">Use email value</option>
                            <option value="value">Confirm value</option>
                            <option value="unknown">Unknown</option>
                        </select>
                        <input
                            v-if="modes.client_rating === 'value'"
                            id="rating-value"
                            v-model="values.client_rating"
                            inputmode="decimal"
                            placeholder="4.90"
                            aria-label="Confirmed client rating"
                            :aria-invalid="
                                Boolean(errors['overrides.client_rating'])
                            "
                            :aria-describedby="
                                errors['overrides.client_rating']
                                    ? 'rating-error'
                                    : undefined
                            "
                        />
                        <p
                            v-if="errors['overrides.client_rating']"
                            id="rating-error"
                            class="field-error"
                        >
                            {{ errors["overrides.client_rating"][0] }}
                        </p>
                    </div>

                    <div class="field-group">
                        <label for="payment-mode">Payment verification</label>
                        <select
                            id="payment-mode"
                            v-model="modes.payment_verified"
                            :aria-invalid="
                                Boolean(errors['overrides.payment_verified'])
                            "
                            :aria-describedby="
                                errors['overrides.payment_verified']
                                    ? 'payment-error'
                                    : undefined
                            "
                        >
                            <option value="inherit">Use email value</option>
                            <option value="true">Verified</option>
                            <option value="false">Not verified</option>
                            <option value="unknown">Unknown</option>
                        </select>
                        <p
                            v-if="errors['overrides.payment_verified']"
                            id="payment-error"
                            class="field-error"
                        >
                            {{ errors["overrides.payment_verified"][0] }}
                        </p>
                    </div>

                    <div class="field-group">
                        <label for="hidden-mode">Hidden skill count</label>
                        <select
                            id="hidden-mode"
                            v-model="modes.hidden_skill_count"
                        >
                            <option value="inherit">Use email value</option>
                            <option value="value">Confirm value</option>
                        </select>
                        <input
                            v-if="modes.hidden_skill_count === 'value'"
                            id="hidden-value"
                            v-model="values.hidden_skill_count"
                            type="number"
                            min="0"
                            max="100"
                            aria-label="Confirmed hidden skill count"
                            :aria-invalid="
                                Boolean(errors['overrides.hidden_skill_count'])
                            "
                            :aria-describedby="
                                errors['overrides.hidden_skill_count']
                                    ? 'hidden-error'
                                    : undefined
                            "
                        />
                        <p
                            v-if="errors['overrides.hidden_skill_count']"
                            id="hidden-error"
                            class="field-error"
                        >
                            {{ errors["overrides.hidden_skill_count"][0] }}
                        </p>
                    </div>

                    <div class="field-group field-wide">
                        <label for="skills-mode">Visible skills</label>
                        <select id="skills-mode" v-model="modes.skills">
                            <option value="inherit">Use email values</option>
                            <option value="value">Confirm values</option>
                        </select>
                        <textarea
                            v-if="modes.skills === 'value'"
                            id="skills-value"
                            v-model="values.skills"
                            rows="4"
                            aria-label="Confirmed visible skills, one per line"
                            :aria-invalid="Boolean(errors['overrides.skills'])"
                            :aria-describedby="
                                errors['overrides.skills']
                                    ? 'skills-help skills-error'
                                    : 'skills-help'
                            "
                        />
                        <p id="skills-help" class="field-help">
                            Enter one skill per line. An empty confirmed list
                            means no visible skills.
                        </p>
                        <p
                            v-if="errors['overrides.skills']"
                            id="skills-error"
                            class="field-error"
                        >
                            {{ errors["overrides.skills"][0] }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <button class="primary-button" type="submit" :disabled="saving">
                {{ saving ? "Saving…" : "Save confirmed details" }}
            </button>
        </form>
    </section>
</template>
