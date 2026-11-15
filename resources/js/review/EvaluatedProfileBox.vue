<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    profileData: { type: Object, default: null },
    subtitle: { type: String, required: true },
    idPrefix: { type: String, default: "evaluated-profile" },
});

const FIELD_LABELS = {
    identity: "Identity",
    capabilities: "Capabilities",
    availability: "Availability",
    devices: "Devices",
    geography: "Geography",
    rate: "Rate",
    scope: "Scope",
};

const expanded = ref(false);
const headingId = computed(() => `${props.idPrefix}-heading`);
const panelId = computed(() => `${props.idPrefix}-panel`);
const textareaId = computed(() => `${props.idPrefix}-textbox`);
const profileVersion = computed(() =>
    typeof props.profileData?.profile_version === "string"
        ? props.profileData.profile_version
        : null,
);

function formatFieldLabel(key) {
    if (FIELD_LABELS[key]) {
        return FIELD_LABELS[key];
    }

    const normalized = String(key).replaceAll("_", " ").trim();
    return normalized
        ? normalized.charAt(0).toUpperCase() + normalized.slice(1)
        : key;
}

const formattedProfileText = computed(() => {
    const profile = props.profileData?.profile;
    if (!profile || typeof profile !== "object") {
        return "";
    }

    const sections = Object.entries(profile)
        .filter(([, value]) => typeof value === "string" && value.trim() !== "")
        .map(([key, value]) => `${formatFieldLabel(key)}:\n${value.trim()}`);

    return sections.join("\n\n");
});
</script>

<template>
    <section
        v-if="formattedProfileText"
        :aria-labelledby="headingId"
        class="min-w-0 border border-[#aab3ad] border-l-4 border-l-[#174d3b] bg-white shadow-xs"
    >
        <button
            type="button"
            class="flex w-full min-w-0 cursor-pointer items-start justify-between gap-3 px-4 py-3 text-left hover:bg-[#f7f9f7]"
            :aria-expanded="expanded ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="expanded = !expanded"
        >
            <span class="grid min-w-0 grow gap-1">
                <span class="flex flex-wrap items-center gap-2">
                    <span
                        :id="headingId"
                        class="font-serif text-lg font-semibold leading-snug text-[#17221d] break-words"
                    >
                        Evaluated freelancer profile
                    </span>
                    <span
                        v-if="profileVersion"
                        class="rounded bg-[#e8efe9] px-2 py-0.5 text-xs font-semibold text-[#174d3b]"
                    >
                        {{ profileVersion }}
                    </span>
                </span>
                <span class="text-xs text-[#536159] break-words">
                    {{ subtitle }}
                </span>
            </span>
            <span
                class="shrink-0 pt-0.5 text-xs font-bold whitespace-nowrap text-[#174d3b]"
                aria-hidden="true"
            >
                {{ expanded ? "Hide ▴" : "Show ▾" }}
            </span>
        </button>

        <div
            v-if="expanded"
            :id="panelId"
            class="grid min-w-0 gap-2 border-t border-[#dce3de] px-4 pb-4 pt-3"
        >
            <label
                :for="textareaId"
                class="text-xs font-bold uppercase tracking-wide text-[#536159] break-words"
            >
                Approved public profile evaluated against job alerts (read-only)
            </label>
            <textarea
                :id="textareaId"
                readonly
                rows="12"
                class="w-full max-w-full min-w-0 resize-y rounded border border-[#718078] bg-[#f8f9f5] p-3 text-sm leading-relaxed text-[#17221d]"
                :value="formattedProfileText"
            ></textarea>
        </div>
    </section>
</template>
