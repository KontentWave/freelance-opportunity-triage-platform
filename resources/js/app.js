import { createApp } from "vue";
import OpportunityPage from "./review/OpportunityPage.vue";
import QueuePage from "./review/QueuePage.vue";
import DemoAiPage from "./review/DemoAiPage.vue";

function parseDatasetJson(raw) {
    if (!raw) return null;
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

const queueRoot = document.querySelector("#review-queue-app");

if (queueRoot) {
    createApp(QueuePage, {
        evaluatedProfile: parseDatasetJson(queueRoot.dataset.evaluatedProfile),
    }).mount(queueRoot);
}

const opportunityRoot = document.querySelector("#opportunity-app");

if (opportunityRoot) {
    createApp(OpportunityPage, {
        opportunityId: opportunityRoot.dataset.opportunityId,
    }).mount(opportunityRoot);
}

const demoAiRoot = document.querySelector("#demo-ai-app");

if (demoAiRoot) {
    createApp(DemoAiPage, {
        sourceMode: demoAiRoot.dataset.sourceMode ?? "authored",
        intentUrl: demoAiRoot.dataset.intentUrl,
        admissionUrl: demoAiRoot.dataset.admissionUrl,
        statusBase: demoAiRoot.dataset.statusBase,
        evaluatedProfile: parseDatasetJson(demoAiRoot.dataset.evaluatedProfile),
    }).mount(demoAiRoot);
}
