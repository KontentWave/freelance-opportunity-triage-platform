<script setup>
import { onMounted, ref } from "vue";
import ScoreExplanation from "./ScoreExplanation.vue";
import { reviewRequest } from "./http.js";

const props = defineProps({ opportunityId: { type: String, required: true } });
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
const opportunity = ref(null);
const loading = ref(true);
const evaluating = ref(false);
const error = ref("");
const notice = ref("");
const returnUrl = `/opportunities${window.location.search}`;

async function load() {
    loading.value = true;
    error.value = "";

    try {
        const payload = await reviewRequest(
            `/review/v1/opportunities/${props.opportunityId}`,
        );
        opportunity.value = payload.data;
    } catch (requestError) {
        error.value =
            requestError.status === 401
                ? "Your session expired. Sign in again to continue."
                : requestError.message;
    } finally {
        loading.value = false;
    }
}

async function evaluate() {
    evaluating.value = true;
    error.value = "";
    notice.value = "";

    try {
        const payload = await reviewRequest(
            `/review/v1/opportunities/${props.opportunityId}/evaluations`,
            {
                method: "POST",
                body: "{}",
            },
        );
        opportunity.value = payload.data;
        notice.value =
            "The saved email evidence was evaluated and selected for review.";
    } catch (requestError) {
        error.value =
            requestError.status === 419 || requestError.status === 401
                ? "Your session expired. Sign in again before evaluating."
                : requestError.message;
    } finally {
        evaluating.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="review-shell">
        <header class="detail-header">
            <a class="back-link" :href="returnUrl">← Back to queue</a>
            <form method="POST" action="/logout">
                <input type="hidden" name="_token" :value="csrfToken" />
                <button class="secondary-button" type="submit">Sign out</button>
            </form>
        </header>

        <p v-if="loading" class="status-line" role="status">
            Loading opportunity
        </p>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <p class="sr-only" role="status" aria-live="polite">{{ notice }}</p>

        <template v-if="opportunity">
            <section class="detail-lead">
                <div>
                    <p class="eyebrow">
                        {{ opportunity.basis ?? "Awaiting evaluation" }}
                    </p>
                    <h1>{{ opportunity.title }}</h1>
                    <p class="detail-summary">
                        {{
                            opportunity.excerpt ||
                            "No email excerpt was supplied."
                        }}
                    </p>
                </div>
                <div class="decision-panel">
                    <span
                        :class="[
                            'suggestion',
                            `suggestion-${opportunity.recommendation.toLowerCase()}`,
                        ]"
                    >
                        {{ opportunity.recommendation
                        }}<template v-if="opportunity.score !== null">
                            · {{ opportunity.score }}</template
                        >
                    </span>
                    <p v-if="opportunity.stale" class="stale-label">
                        The imported evidence changed after this score.
                    </p>
                    <button
                        class="primary-button"
                        type="button"
                        :disabled="evaluating"
                        @click="evaluate"
                    >
                        {{
                            evaluating
                                ? "Evaluating…"
                                : opportunity.evaluation_id
                                  ? "Evaluate current email"
                                  : "Evaluate"
                        }}
                    </button>
                </div>
            </section>

            <section aria-labelledby="facts-heading" class="facts-section">
                <h2 id="facts-heading">Email evidence</h2>
                <dl class="facts-grid">
                    <div>
                        <dt>Hourly range</dt>
                        <dd>
                            {{
                                opportunity.hourly_max
                                    ? `${opportunity.currency} ${opportunity.hourly_min ?? "?"}–${opportunity.hourly_max}`
                                    : "Unknown"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Posted</dt>
                        <dd>{{ opportunity.posted_on ?? "Unknown" }}</dd>
                    </div>
                    <div>
                        <dt>Client rating</dt>
                        <dd>
                            {{
                                opportunity.client_rating &&
                                opportunity.client_rating !== "0.00"
                                    ? opportunity.client_rating
                                    : "Unrated"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Payment</dt>
                        <dd>
                            {{
                                opportunity.payment_verified === null
                                    ? "Unknown"
                                    : opportunity.payment_verified
                                      ? "Verified"
                                      : "Not verified"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Duration</dt>
                        <dd>
                            {{ opportunity.estimated_duration ?? "Unknown" }}
                        </dd>
                    </div>
                    <div>
                        <dt>Skills</dt>
                        <dd>
                            {{
                                opportunity.skills.length
                                    ? opportunity.skills.join(", ")
                                    : "Unknown"
                            }}<template v-if="opportunity.hidden_skill_count">
                                (+{{
                                    opportunity.hidden_skill_count
                                }}
                                hidden)</template
                            >
                        </dd>
                    </div>
                </dl>
                <a
                    v-if="opportunity.canonical_url"
                    class="listing-link"
                    :href="opportunity.canonical_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Open canonical listing</a
                >
            </section>

            <ScoreExplanation :result="opportunity.displayed_result" />

            <aside class="review-reminder" aria-labelledby="reminder-heading">
                <h2 id="reminder-heading">Before acting</h2>
                <p>
                    Check the full scope, verify credible delivery capability,
                    and confirm the work fits within 20 hours per week. This
                    suggestion is experimental and requires human judgment.
                </p>
            </aside>
        </template>
    </div>
</template>
