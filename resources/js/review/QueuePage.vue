<script setup>
import { onMounted, reactive, ref, watch } from "vue";
import { reviewRequest } from "./http.js";

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
const query = new URLSearchParams(window.location.search);
const filters = reactive({
    recommendation: query.get("recommendation") ?? "ALL",
    review: query.get("review") ?? "all",
    missing: query.get("missing") ?? "all",
    page: Number(query.get("page") ?? 1),
});
const opportunities = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const error = ref("");
const initialized = ref(false);

async function load() {
    loading.value = true;
    error.value = "";
    const params = new URLSearchParams();

    if (filters.recommendation !== "ALL")
        params.set("recommendation", filters.recommendation);
    if (filters.review !== "all") params.set("review", filters.review);
    if (filters.missing !== "all") params.set("missing", filters.missing);
    if (filters.page > 1) params.set("page", String(filters.page));

    window.history.replaceState(
        {},
        "",
        `${window.location.pathname}${params.size ? `?${params}` : ""}`,
    );

    try {
        const payload = await reviewRequest(
            `/review/v1/opportunities?${params}`,
        );
        opportunities.value = payload.data;
        meta.value = payload.meta;
    } catch (requestError) {
        error.value =
            requestError.status === 401
                ? "Your session expired. Sign in again to continue."
                : requestError.message;
    } finally {
        loading.value = false;
    }
}

function resetFilters() {
    filters.recommendation = "ALL";
    filters.review = "all";
    filters.missing = "all";
    filters.page = 1;
}

function detailUrl(opportunity) {
    const returnQuery = window.location.search;
    return `/opportunities/${opportunity.id}${returnQuery}`;
}

watch(
    () => [filters.recommendation, filters.review, filters.missing],
    () => {
        if (initialized.value) filters.page = 1;
    },
);
watch(filters, load);
onMounted(async () => {
    await load();
    initialized.value = true;
});
</script>

<template>
    <div class="review-shell">
        <header class="app-header">
            <div>
                <p class="eyebrow">Private workspace</p>
                <h1>Opportunity review queue</h1>
                <p>
                    Saved experimental suggestions for human review, never
                    automatic decisions.
                </p>
            </div>
            <form method="POST" action="/logout">
                <input type="hidden" name="_token" :value="csrfToken" />
                <button class="secondary-button" type="submit">Sign out</button>
            </form>
        </header>

        <section aria-labelledby="filters-heading" class="filter-band">
            <h2 id="filters-heading">Filter queue</h2>
            <div class="filter-grid">
                <label
                    >Suggestion
                    <select v-model="filters.recommendation">
                        <option value="ALL">All suggestions</option>
                        <option value="UNSCORED">Unscored</option>
                        <option value="APPLY">Apply</option>
                        <option value="MAYBE">Maybe</option>
                        <option value="SKIP">Skip</option>
                    </select>
                </label>
                <label
                    >Review status
                    <select v-model="filters.review">
                        <option value="all">All</option>
                        <option value="unreviewed">Unreviewed</option>
                        <option value="reviewed">Reviewed</option>
                    </select>
                </label>
                <label
                    >Missing data
                    <select v-model="filters.missing">
                        <option value="all">All</option>
                        <option value="present">Missing data present</option>
                        <option value="none">No missing data</option>
                    </select>
                </label>
                <button class="text-button" type="button" @click="resetFilters">
                    Clear filters
                </button>
            </div>
        </section>

        <p class="status-line" role="status" aria-live="polite">
            {{
                loading
                    ? "Loading opportunities"
                    : `${meta.total} ${meta.total === 1 ? "opportunity" : "opportunities"} found`
            }}
        </p>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>

        <section
            v-if="!loading && !error"
            aria-label="Ranked opportunities"
            class="queue-list"
        >
            <article
                v-for="opportunity in opportunities"
                :key="opportunity.id"
                class="queue-row"
            >
                <div class="queue-main">
                    <div class="queue-title">
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
                        <span v-if="opportunity.stale" class="stale-label"
                            >Evidence changed</span
                        >
                    </div>
                    <h2>
                        <a :href="detailUrl(opportunity)">{{
                            opportunity.title
                        }}</a>
                    </h2>
                    <p>
                        {{
                            opportunity.hourly_max
                                ? `${opportunity.currency} ${opportunity.hourly_min ?? "?"}–${opportunity.hourly_max}/hr`
                                : "Hourly rate unknown"
                        }}
                        <span aria-hidden="true"> · </span>
                        {{ opportunity.posted_on ?? "Posted date unknown" }}
                    </p>
                </div>
                <dl class="queue-meta">
                    <div>
                        <dt>Basis</dt>
                        <dd>{{ opportunity.basis ?? "Not evaluated" }}</dd>
                    </div>
                    <div>
                        <dt>Missing</dt>
                        <dd>
                            {{
                                opportunity.missing_fields.length
                                    ? opportunity.missing_fields.join(", ")
                                    : opportunity.recommendation === "UNSCORED"
                                      ? "Unassessed"
                                      : "None"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Human review</dt>
                        <dd>
                            {{
                                opportunity.reviewed ? "Reviewed" : "Unreviewed"
                            }}
                        </dd>
                    </div>
                </dl>
            </article>

            <div v-if="opportunities.length === 0" class="empty-state">
                <h2>No jobs match these filters</h2>
                <p>Clear the filters to return to the complete review queue.</p>
                <button
                    class="primary-button"
                    type="button"
                    @click="resetFilters"
                >
                    Clear filters
                </button>
            </div>
        </section>

        <nav
            v-if="meta.last_page > 1"
            aria-label="Queue pages"
            class="pagination"
        >
            <button
                :disabled="filters.page <= 1"
                type="button"
                @click="filters.page--"
            >
                Previous
            </button>
            <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button
                :disabled="filters.page >= meta.last_page"
                type="button"
                @click="filters.page++"
            >
                Next
            </button>
        </nav>
    </div>
</template>
