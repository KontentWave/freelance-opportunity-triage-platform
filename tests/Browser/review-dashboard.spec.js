import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import process from "node:process";

const root = process.cwd();
let server;
const foreignOpportunityId = `01J${String(27).padStart(23, "0")}`;

function artisan(args, environment = {}) {
    const result = spawnSync("php", ["artisan", ...args], {
        cwd: root,
        env: { ...process.env, APP_ENV: "testing", ...environment },
        encoding: "utf8",
    });

    if (result.status !== 0) {
        throw new Error(
            result.stderr || result.stdout || "Artisan command failed.",
        );
    }

    return result.stdout.trim();
}

async function startServer(mode, port) {
    const origin = `http://127.0.0.1:${port}`;
    server = spawn(
        "php",
        ["artisan", "serve", "--host=127.0.0.1", `--port=${port}`],
        {
            cwd: root,
            detached: true,
            stdio: "ignore",
            env: {
                ...process.env,
                APP_ENV: "e2e",
                APP_KEY:
                    process.env.APP_KEY ??
                    "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
                APP_URL: origin,
                APP_DEBUG: "false",
                SESSION_DRIVER: "file",
                CACHE_STORE: "array",
                DB_CONNECTION: process.env.DB_CONNECTION ?? "mariadb",
                DB_HOST: process.env.DB_HOST ?? "127.0.0.1",
                DB_PORT: process.env.DB_PORT ?? "3307",
                DB_DATABASE:
                    process.env.DB_DATABASE ??
                    "freelance_opportunity_triage_platform_test",
                DB_USERNAME: process.env.DB_USERNAME ?? "app",
                DB_PASSWORD: process.env.DB_PASSWORD ?? "app",
                OPPORTUNITY_REVIEW_MODE: mode,
                OPPORTUNITY_REVIEW_DEMO_USER_ID: "41001",
                OPPORTUNITY_REVIEW_PROFILE_PATH:
                    "resources/triage/profiles/demo-v1.json",
            },
        },
    );

    for (let attempt = 0; attempt < 80; attempt += 1) {
        try {
            const response = await fetch(`${origin}/up`);
            if (response.ok) return origin;
        } catch {
            // The PHP server has not bound the port yet.
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
    }

    throw new Error(`Laravel ${mode} server did not start.`);
}

function stopServer() {
    if (server?.pid) {
        process.kill(-server.pid, "SIGTERM");
        server = undefined;
    }
}

async function keyboardActivate(page, accessibleName) {
    for (let attempt = 0; attempt < 80; attempt += 1) {
        await page.keyboard.press("Tab");
        const name = await page.evaluate(() =>
            document.activeElement?.textContent?.trim(),
        );

        if (name?.includes(accessibleName)) {
            await page.keyboard.press("Enter");
            return;
        }
    }

    throw new Error(`Keyboard focus did not reach ${accessibleName}.`);
}

test.describe.serial("review dashboard acceptance", () => {
    test.beforeAll(() => {
        artisan(["migrate:fresh", "--env=testing", "--force"]);
        artisan(
            ["db:seed", "--class=ReviewDemoSeeder", "--env=testing", "--force"],
            {
                OPPORTUNITY_REVIEW_MODE: "demo",
                OPPORTUNITY_REVIEW_DEMO_USER_ID: "41001",
            },
        );
    });

    test.afterEach(() => stopServer());

    test("private mode rejects demo entry and protects review data", async ({
        page,
    }) => {
        const origin = await startServer("private", 8011);

        await expect((await page.request.get(`${origin}/demo`)).status()).toBe(
            404,
        );
        await expect(
            (await page.request.post(`${origin}/demo-session`)).status(),
        ).toBe(404);
        await page.goto(`${origin}/opportunities`);
        await expect(page).toHaveURL(/\/login$/);
        const response = await page.request.get(
            `${origin}/review/v1/opportunities`,
            { headers: { Accept: "application/json" } },
        );
        expect(response.status()).toBe(401);
    });

    test("keyboard review journey and recoverable failures", async ({
        page,
        context,
    }) => {
        const origin = await startServer("demo", 8012);
        const externalRequests = [];
        context.on("request", (request) => {
            if (new URL(request.url()).origin !== origin)
                externalRequests.push(request.url());
        });

        await page.goto(`${origin}/demo`);
        await keyboardActivate(page, "Start demo");
        await expect(page.getByText("Synthetic shared demo")).toBeVisible();
        await expect(page.getByRole("article")).toHaveCount(25);
        await expect(page.getByRole("status")).toContainText(
            "26 opportunities found",
        );
        await expect(
            (
                await page.request.get(
                    `${origin}/review/v1/opportunities/${foreignOpportunityId}`,
                    { headers: { Accept: "application/json" } },
                )
            ).status(),
        ).toBe(404);

        await page.setViewportSize({ width: 320, height: 720 });
        await expect(
            page.evaluate(() => document.documentElement.scrollWidth <= 320),
        ).resolves.toBe(true);
        await page.setViewportSize({ width: 640, height: 900 });
        await page.evaluate(() => {
            document.documentElement.style.zoom = "2";
        });
        await expect(
            page.evaluate(() => document.documentElement.scrollWidth <= 640),
        ).resolves.toBe(true);
        await page.evaluate(() => {
            document.documentElement.style.zoom = "";
        });

        await keyboardActivate(page, "Synthetic opportunity 03");
        await expect(
            page.getByText("<script>syntheticBrowserProbe()</script>"),
        ).toBeVisible();
        await expect(
            page.evaluate(() => typeof window.syntheticBrowserProbe),
        ).resolves.toBe("undefined");
        await page.getByRole("link", { name: "Back to queue" }).click();

        await keyboardActivate(page, "Synthetic opportunity 05");
        await expect(
            page.getByLabel("Synthetic confirmation preset"),
        ).toBeVisible();
        await expect(page.getByLabel("Full description")).toHaveCount(0);
        await expect(page.getByLabel("Sample provenance")).toHaveCount(0);
        await expect(page.getByLabel("Notes")).toHaveCount(0);

        const detail = await page.evaluate(async () => {
            const response = await fetch(
                window.location.pathname.replace(
                    "/opportunities/",
                    "/review/v1/opportunities/",
                ),
                {
                    headers: { Accept: "application/json" },
                },
            );
            return response.json();
        });
        const opportunityId = detail.data.id;
        const evaluationId = detail.data.evaluation_id;

        const csrfResponse = await page.request.post(
            `${origin}/review/v1/opportunities/${opportunityId}/enrichments`,
            {
                headers: { Accept: "application/json" },
                data: {
                    evaluation_id: evaluationId,
                    expected_enrichment_id: null,
                    preset_key: "confirm_hourly_rate",
                },
            },
        );
        expect(csrfResponse.status()).toBe(419);
        const unchangedDetail = await page.request.get(
            `${origin}/review/v1/opportunities/${opportunityId}`,
            { headers: { Accept: "application/json" } },
        );
        expect(
            (await unchangedDetail.json()).data.current_enrichment,
        ).toBeNull();

        const forgedStatus = await page.evaluate(
            async ({ opportunityId, evaluationId }) => {
                const csrf = document.querySelector(
                    'meta[name="csrf-token"]',
                ).content;
                const response = await fetch(
                    `/review/v1/opportunities/${opportunityId}/enrichments`,
                    {
                        method: "POST",
                        credentials: "same-origin",
                        headers: {
                            Accept: "application/json",
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": csrf,
                        },
                        body: JSON.stringify({
                            evaluation_id: evaluationId,
                            expected_enrichment_id: null,
                            preset_key: "forged",
                        }),
                    },
                );
                return response.status;
            },
            { opportunityId, evaluationId },
        );
        expect(forgedStatus).toBe(422);

        const enrichmentRegion = page.getByRole("region", {
            name: "Confirm additional details",
        });
        const feedbackRegion = page.getByRole("region", {
            name: "Record your decision",
        });
        const preset = page.getByLabel("Synthetic confirmation preset");
        await preset.selectOption("confirm_quality_skills");
        await page.route(
            `**/review/v1/opportunities/${opportunityId}/enrichments`,
            async (route) => {
                await route.fulfill({
                    status: 422,
                    contentType: "application/json",
                    body: JSON.stringify({
                        error_code: "review.validation_failed",
                        message: "The submitted data is invalid.",
                        errors: {
                            preset_key: ["The selected preset is unavailable."],
                        },
                    }),
                });
            },
            { times: 1 },
        );
        await page
            .getByRole("button", { name: "Save confirmed details" })
            .click();
        await expect(enrichmentRegion.getByRole("alert")).toBeFocused();
        await expect(preset).toHaveValue("confirm_quality_skills");

        await page
            .getByRole("button", { name: "Save confirmed details" })
            .click();
        await expect(enrichmentRegion.getByRole("status")).toContainText(
            "Confirmed details saved",
        );

        await page.route(
            `**/review/v1/opportunities/${opportunityId}/enrichments`,
            async (route) => {
                await route.fulfill({
                    status: 409,
                    contentType: "application/json",
                    body: JSON.stringify({
                        error_code: "review.stale_context",
                        message: "The displayed review context changed.",
                    }),
                });
            },
            { times: 1 },
        );
        await page
            .getByRole("button", { name: "Save confirmed details" })
            .click();
        await expect(enrichmentRegion.getByRole("alert")).toBeFocused();
        await expect(preset).toHaveValue("confirm_quality_skills");

        await page.getByLabel("APPLY").check();
        await page.getByLabel("Disagreement reason").selectOption("economics");
        await page.getByLabel("Outcome").selectOption("applied");
        await page
            .getByLabel("Synthetic note")
            .selectOption({ label: "Use fixed demonstration note" });
        await page.route(
            `**/review/v1/opportunities/${opportunityId}/review`,
            async (route) => {
                await route.fulfill({
                    status: 401,
                    contentType: "application/json",
                    body: JSON.stringify({ message: "Unauthenticated." }),
                });
            },
            { times: 1 },
        );
        await page.getByRole("button", { name: "Save feedback" }).click();
        await expect(feedbackRegion.getByRole("alert")).toBeFocused();
        await expect(page.getByLabel("Synthetic note")).toHaveValue(
            "Reviewed in the synthetic shared demonstration.",
        );

        await page.getByRole("button", { name: "Save feedback" }).click();
        await expect(feedbackRegion.getByRole("status")).toContainText(
            "Feedback saved",
        );
        await page.reload();
        await expect(page.getByLabel("APPLY")).toBeChecked();
        await expect(page.getByLabel("Synthetic note")).toHaveValue(
            "Reviewed in the synthetic shared demonstration.",
        );
        expect(externalRequests).toEqual([]);
    });
});
