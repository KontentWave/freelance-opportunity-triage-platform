import { createApp } from "vue";
import OpportunityPage from "./review/OpportunityPage.vue";
import QueuePage from "./review/QueuePage.vue";

const queueRoot = document.querySelector("#review-queue-app");

if (queueRoot) {
    createApp(QueuePage).mount(queueRoot);
}

const opportunityRoot = document.querySelector("#opportunity-app");

if (opportunityRoot) {
    createApp(OpportunityPage, {
        opportunityId: opportunityRoot.dataset.opportunityId,
    }).mount(opportunityRoot);
}
