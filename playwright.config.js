import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
    testDir: "./tests/Browser",
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: "line",
    use: {
        ...devices["Desktop Chrome"],
        trace: "retain-on-failure",
    },
    projects: [
        {
            name: "chromium",
            use: { ...devices["Desktop Chrome"] },
        },
    ],
});
