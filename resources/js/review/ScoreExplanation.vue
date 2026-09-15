<script setup>
defineProps({
    result: { type: Object, default: null },
});
</script>

<template>
    <section
        aria-labelledby="score-explanation-heading"
        class="evidence-section"
    >
        <div class="section-heading">
            <div>
                <p class="eyebrow">Saved evidence</p>
                <h2 id="score-explanation-heading">Why this suggestion?</h2>
            </div>
            <p class="judgment-note">
                Experimental suggestion. Use your own judgment before deciding.
            </p>
        </div>

        <p v-if="!result" class="empty-copy">
            No evaluation has been saved for the active profile.
        </p>
        <div v-else class="contribution-grid">
            <article
                v-for="contribution in result.contributions"
                :key="contribution.rule"
                class="contribution"
            >
                <div class="contribution-score">
                    <strong>{{
                        contribution.rule.replaceAll("_", " ")
                    }}</strong>
                    <span
                        >{{ contribution.points }}/{{
                            contribution.maximum_points
                        }}</span
                    >
                </div>
                <p>{{ contribution.explanation }}</p>
            </article>
        </div>

        <div v-if="result" class="evidence-summary">
            <div>
                <h3>Missing information</h3>
                <p>
                    {{
                        result.missing_fields.length
                            ? result.missing_fields.join(", ")
                            : "None identified"
                    }}
                </p>
            </div>
            <div>
                <h3>Hard exclusions</h3>
                <p>
                    {{
                        result.hard_exclusions.length
                            ? result.hard_exclusions.join(", ")
                            : "None identified"
                    }}
                </p>
            </div>
        </div>
    </section>
</template>
