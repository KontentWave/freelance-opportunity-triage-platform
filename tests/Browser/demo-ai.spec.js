import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { readFile } from "node:fs/promises";

const origin = "http://127.0.0.1:8013";
let server;
let foreignRun;
const environment = {
    ...process.env,
    APP_ENV: "e2e",
    APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
    APP_URL: origin,
    APP_DEBUG: "false",
    SESSION_DRIVER: "file",
    CACHE_STORE: "array",
    MAIL_MAILER: "array",
    DB_CONNECTION: process.env.DB_CONNECTION ?? "mariadb",
    DB_HOST: process.env.DB_HOST ?? "127.0.0.1",
    DB_PORT: process.env.DB_PORT ?? "3307",
    DB_DATABASE: process.env.DB_DATABASE ?? "freelance_opportunity_triage_platform_test",
    DB_USERNAME: process.env.DB_USERNAME ?? "app",
    DB_PASSWORD: process.env.DB_PASSWORD ?? "app",
    OPPORTUNITY_REVIEW_MODE: "demo",
    OPPORTUNITY_REVIEW_DEMO_USER_ID: "41001",
    OPPORTUNITY_REVIEW_PROFILE_PATH: "resources/triage/profiles/demo-v1.json",
    FOTP_BROWSER_LIVE: "true",
};

function artisan(args) {
    const result = spawnSync("php", ["artisan", ...args], {
        env: { ...environment, APP_ENV: "testing" }, encoding: "utf8",
    });
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
}

function ledgerCounts() {
    const result = spawnSync("php", ["tests/Browser/demo-ai-server.php", "ledger-counts"], { env: environment, encoding: "utf8" });
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
    return JSON.parse(result.stdout);
}

async function enter(page) {
    await page.goto(`${origin}/demo`);
    await page.getByRole("button", { name: "Start demo" }).click();
    await page.getByRole("link", { name: "Evaluate fictional alerts with AI" }).click();
}

function record(status, changes = {}) {
    return {
        id: "01AAAAAAAAAAAAAAAAAAAAAAAA", status,
        selected_count: 2, succeeded_count: 0, failed_count: 0,
        finalized_at: null, expires_at: null, report_url: null, ...changes,
    };
}

async function mockIntent(page, run = null, available = true) {
    await page.route("**/demo-ai/admission-key", (route) => route.fulfill({
        json: { available, idempotency_key: "k".repeat(48), key_expires_at: Math.floor(Date.now() / 1000) + 1800, run },
    }));
}

test.describe.serial("demo AI progress and reports", () => {
    test.beforeAll(async () => {
        if (!environment.DB_DATABASE.endsWith("_test") || !["127.0.0.1", "localhost"].includes(environment.DB_HOST)) {
            throw new Error("Refusing browser setup outside the disposable local test database.");
        }
        artisan(["test", "tests/Feature/MariaDbTestingEnvironmentTest.php", "--compact"]);
        artisan(["migrate:fresh", "--env=testing", "--force"]);
        artisan(["db:seed", "--class=ReviewDemoSeeder", "--env=testing", "--force"]);
        const fixture = spawnSync("php", ["tests/Browser/demo-ai-server.php", "seed-foreign"], { env: environment, encoding: "utf8" });
        if (fixture.status !== 0) throw new Error(fixture.stderr || fixture.stdout);
        foreignRun = fixture.stdout.trim();
        server = spawn("php", ["-S", "127.0.0.1:8013", "-t", "public", "tests/Browser/demo-ai-server.php"], {
            env: environment, stdio: "ignore",
        });
        for (let attempt = 0; attempt < 80; attempt++) {
            try { if ((await fetch(`${origin}/up`)).ok) return; } catch { /* Wait for the local server. */ }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        throw new Error("Controlled Laravel server did not start.");
    });
    test.afterAll(() => { if (server?.pid) process.kill(server.pid, "SIGTERM"); });

    test("real browser to Laravel intake, ledger, validated provider and matching immutable download", async ({ page, context, browser }) => {
        test.setTimeout(60000);
        const external = [];
        context.on("request", (request) => { if (new URL(request.url()).origin !== origin) external.push(request.url()); });
        await enter(page);
        const keys = await page.request.get(`${origin}/demo-ai/admission-key`);
        const key = (await keys.json()).idempotency_key;
        const admissions = [];
        page.on("request", (request) => {
            if (request.url() === `${origin}/demo-ai/runs` && request.method() === "POST") admissions.push(request.postDataJSON());
        });
        await page.waitForTimeout(2100);
        await page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" }).dblclick();
        await expect(page.getByRole("status")).toContainText("Queued");
        await expect(page.getByRole("status")).toContainText("Running", { timeout: 8000 });
        await expect(page.getByRole("status")).toContainText("Completed.", { timeout: 10000 });
        expect(admissions).toEqual([{ idempotency_key: key }]);
        const id = new URL(page.url()).searchParams.get("run");
        for (const suffix of ["", "/report", "/report.html"]) {
            expect((await page.request.get(`${origin}/demo-ai/runs/${foreignRun}${suffix}`, { headers: { Accept: "application/json" } })).status()).toBe(404);
        }
        const replay = await page.request.post(`${origin}/demo-ai/runs`, {
            data: { idempotency_key: key },
            headers: { Accept: "application/json", "X-CSRF-TOKEN": await page.locator('meta[name="csrf-token"]').getAttribute("content") },
        });
        expect((await replay.json()).id).toBe(id);
        await page.reload();
        await expect(page.getByRole("status")).toContainText("Completed.");
        await page.goto(`${origin}/opportunities`);
        await page.getByRole("link", { name: "Evaluate fictional alerts with AI" }).click();
        await expect(page.getByText(`Run ${id}`, { exact: true })).toBeVisible();
        const downloadPromise = page.waitForEvent("download");
        await page.getByRole("link", { name: "Download matching HTML" }).click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(`fictional-demo-${id}.html`);
        const downloaded = await readFile(await download.path(), "utf8");
        const screenResponse = await page.request.get(`${origin}/demo-ai/runs/${id}/report`);
        expect(await screenResponse.text()).toBe(downloaded);
        await page.getByRole("link", { name: "Open stored report" }).click();
        await expect(page.getByRole("heading", { name: /Fictional demo alerts evaluated/ })).toBeVisible();
        await expect(page.getByText(/Bounded manual testing.*<img/)).toBeVisible();
        await expect(page.locator("script, img, link, iframe")).toHaveCount(0);
        await page.setViewportSize({ width: 320, height: 720 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.evaluate(() => { document.documentElement.style.zoom = "200%"; });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.emulateMedia({ media: "print" });
        await expect(page.getByText(/^Evaluate exactly one fictional alert against/)).toBeVisible();
        await page.emulateMedia({ media: "screen" });
        expect(external).toEqual([]);
        await page.goto(`${origin}/demo-ai?run=${id}`);
        for (const gap of [100, 200, 300]) {
            await page.waitForTimeout(2100);
            const prepare = page.getByRole("button", { name: "Prepare a new evaluation" });
            const bounds = await prepare.boundingBox();
            const before = admissions.length;
            const ledgerBefore = ledgerCounts();
            await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
            await page.waitForTimeout(gap);
            await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
            const evaluate = page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" });
            await expect(evaluate).toBeVisible();
            await page.waitForTimeout(2300);
            expect(admissions).toHaveLength(before);
            expect(ledgerCounts()).toEqual(ledgerBefore);
            await evaluate.dblclick();
            await expect(page.getByRole("status")).toContainText("Rejected");
            expect(admissions).toHaveLength(before + 1);
            expect(ledgerCounts()).toEqual({ ...ledgerBefore, runs: ledgerBefore.runs + 1 });
        }
        expect(new Set(admissions.map((admission) => admission.idempotency_key)).size).toBe(4);
        expect(external).toEqual([]);
        const foreign = await browser.newContext();
        const visitor = await foreign.newPage();
        await visitor.goto(`${origin}/demo`);
        await visitor.getByRole("button", { name: "Start demo" }).click();
        for (const suffix of ["", "/report", "/report.html"]) {
            expect((await visitor.request.get(`${origin}/demo-ai/runs/${id}${suffix}`, { headers: { Accept: "application/json" } })).status()).toBe(404);
        }
        await foreign.close();
        await context.clearCookies();
        expect((await page.request.get(`${origin}/demo-ai/runs/${id}/report`, { headers: { Accept: "application/json" } })).status()).toBe(401);
    });

    test("ambiguous admission retry uses exactly the same server intent and prevents double clicks", async ({ page }) => {
        await mockIntent(page);
        let attempts = 0;
        const keys = [];
        await page.route("**/demo-ai/runs", async (route) => {
            keys.push(route.request().postDataJSON().idempotency_key);
            attempts++;
            if (attempts === 1) return route.abort("connectionreset");
            await route.fulfill({ status: 202, json: record("queued") });
        });
        await page.route("**/demo-ai/runs/*", (route) => route.fulfill({ json: record("completed", { succeeded_count: 2 }) }));
        await enter(page);
        await page.waitForTimeout(2100);
        await page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" }).dblclick();
        await expect(page.getByRole("alert")).toContainText("Admission could not be confirmed");
        await page.waitForTimeout(2100);
        await page.getByRole("button", { name: "Retry same request" }).click();
        await expect(page.getByRole("status")).toContainText("Completed.");
        expect(keys).toEqual(["k".repeat(48), "k".repeat(48)]);
    });

    test("admission rate limiting respects HTTP-date Retry-After and retries the same intent", async ({ page }) => {
        await page.clock.install();
        await mockIntent(page);
        const keys = [];
        await page.route("**/demo-ai/runs", async (route) => {
            keys.push(route.request().postDataJSON().idempotency_key);
            return route.fulfill(keys.length === 1
                ? { status: 429, headers: { "Retry-After": await page.evaluate(() => new Date(Date.now() + 10000).toUTCString()) }, json: {} }
                : { status: 202, json: record("rejected", { selected_count: 0 }) });
        });
        await enter(page);
        await page.clock.runFor(2100);
        await page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" }).click();
        await page.clock.runFor(2100);
        await expect(page.getByRole("alert")).toContainText("Request limit reached");
        await page.getByRole("button", { name: "Retry same request" }).click({ force: true });
        await page.clock.runFor(8000);
        expect(keys).toHaveLength(1);
        await page.clock.runFor(2100);
        expect(keys).toHaveLength(1);
        await page.getByRole("button", { name: "Retry same request" }).click();
        await expect(page.getByRole("status")).toContainText("Rejected");
        expect(keys).toEqual(["k".repeat(48), "k".repeat(48)]);
    });

    test("a fresh intent and evaluation require separate deliberate actions", async ({ page }) => {
        const keys = [];
        await page.route("**/demo-ai/admission-key", (route) => route.fulfill({
            json: {
                available: true, idempotency_key: route.request().method() === "POST" ? "n".repeat(48) : "k".repeat(48),
                key_expires_at: Math.floor(Date.now() / 1000) + 1800,
                run: route.request().method() === "POST" ? null : record("completed", { succeeded_count: 2 }),
            },
        }));
        await page.route("**/demo-ai/runs", (route) => {
            keys.push(route.request().postDataJSON().idempotency_key);
            return route.fulfill({ status: 429, json: record("rejected") });
        });
        await enter(page);
        await page.getByRole("button", { name: "Prepare a new evaluation" }).click();
        await expect(page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" })).toBeVisible();
        await expect(page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" })).toBeFocused();
        expect(keys).toEqual([]);
        await page.waitForTimeout(2100);
        await page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" }).click();
        await expect(page.getByRole("status")).toContainText("Rejected");
        expect(keys).toEqual(["n".repeat(48)]);
    });

    for (const gap of [100, 200, 300]) {
        test(`fixed-coordinate preparation clicks ${gap}ms apart never admit or schedule admission`, async ({ page }) => {
            const keys = [];
            await page.route("**/demo-ai/admission-key", (route) => route.fulfill({
                json: {
                    available: true, idempotency_key: "n".repeat(48),
                    key_expires_at: Math.floor(Date.now() / 1000) + 1800,
                    run: route.request().method() === "POST" ? null : record("completed"),
                },
            }));
            await page.route("**/demo-ai/runs", (route) => {
                keys.push(route.request().postDataJSON().idempotency_key);
                return route.fulfill({ status: 202, json: record("queued") });
            });
            await enter(page);
            await page.waitForTimeout(2100);
            const bounds = await page.getByRole("button", { name: "Prepare a new evaluation" }).boundingBox();
            await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
            await page.waitForTimeout(gap);
            await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
            const evaluate = page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" });
            await expect(evaluate).toBeVisible();
            await page.waitForTimeout(2300);
            expect(keys).toEqual([]);
            await evaluate.dblclick();
            await expect(page.getByRole("status")).toContainText("Queued");
            expect(keys).toEqual(["n".repeat(48)]);
        });
    }

    test("idle expiry requires explicit keyboard renewal and a separate submission after Retry-After", async ({ page }) => {
        await page.clock.install();
        let intents = 0;
        const keys = [];
        await page.route("**/demo-ai/admission-key", async (route) => {
            intents++;
            const now = await page.evaluate(() => Math.floor(Date.now() / 1000));
            await route.fulfill({
                headers: { "Retry-After": "5" },
                json: { available: true, idempotency_key: (intents === 1 ? "k" : "n").repeat(48), key_expires_at: now + 10, run: null },
            });
        });
        await page.route("**/demo-ai/runs", (route) => {
            keys.push(route.request().postDataJSON().idempotency_key);
            return route.fulfill({ status: 202, json: record("queued") });
        });
        await enter(page);
        await expect(page.getByRole("status")).toContainText("Ready for a deliberate");
        await page.clock.runFor(10100);
        await expect(page.getByRole("status")).toContainText("submission window expired");
        await expect(page.getByRole("button", { name: /Evaluate up to/ })).toHaveCount(0);
        const prepare = page.getByRole("button", { name: "Prepare a new evaluation" });
        await prepare.focus();
        await page.keyboard.press("Enter");
        const evaluate = page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" });
        await expect(evaluate).toBeFocused();
        await expect(evaluate).toHaveAttribute("aria-disabled", "true");
        await page.keyboard.press("Enter");
        await page.clock.runFor(5100);
        expect(keys).toEqual([]);
        await expect(evaluate).toHaveAttribute("aria-disabled", "false");
        await page.keyboard.press("Enter");
        await expect(page.getByRole("status")).toContainText("Queued");
        expect(keys).toEqual(["n".repeat(48)]);
        expect(intents).toBe(2);
    });

    test("renewal replaces expiry timers and unmount cancels pending callbacks", async ({ page }) => {
        await page.clock.install();
        let intents = 0;
        let admissions = 0;
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        await page.route("**/demo-ai/admission-key", async (route) => {
            intents++;
            const now = await page.evaluate(() => Math.floor(Date.now() / 1000));
            await route.fulfill({
                json: {
                    available: true, idempotency_key: (intents === 1 ? "k" : "n").repeat(48),
                    key_expires_at: now + (intents === 1 ? 10 : 60),
                    run: intents === 1 ? record("completed") : null,
                },
            });
        });
        await page.route("**/demo-ai/runs", (route) => { admissions++; return route.abort(); });
        await enter(page);
        await page.clock.runFor(2100);
        await page.getByRole("button", { name: "Prepare a new evaluation" }).click();
        const evaluate = page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" });
        await expect(evaluate).toBeFocused();
        await page.keyboard.press("Enter");
        await page.clock.runFor(10100);
        await expect(evaluate).toBeVisible();
        await expect(page.getByRole("status")).toContainText("Ready for a deliberate");
        expect(admissions).toBe(0);
        await page.evaluate(() => document.querySelector("#demo-ai-app").__vue_app__.unmount());
        await page.clock.runFor(120000);
        await expect(page.locator("#demo-ai-app")).toBeEmpty();
        expect(admissions).toBe(0);
        expect(intents).toBe(2);
        expect(errors).toEqual([]);
    });

    test("unmount cancels delayed preparation and ignores a late renewal response on re-entry", async ({ page }) => {
        await page.clock.install();
        let preparations = 0;
        let admissions = 0;
        let release;
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        await page.route("**/demo-ai/admission-key", async (route) => {
            if (route.request().method() === "POST") {
                preparations++;
                await new Promise((resolve) => { release = resolve; });
            }
            await route.fulfill({
                json: {
                    available: true, idempotency_key: (route.request().method() === "POST" ? "n" : "k").repeat(48),
                    key_expires_at: Math.floor(Date.now() / 1000) + 1800,
                    run: route.request().method() === "POST" ? null : record("completed"),
                },
            });
        });
        await page.route("**/demo-ai/runs", (route) => { admissions++; return route.abort(); });
        await enter(page);
        await page.getByRole("button", { name: "Prepare a new evaluation" }).click();
        await page.evaluate(() => document.querySelector("#demo-ai-app").__vue_app__.unmount());
        await page.clock.runFor(2100);
        expect(preparations).toBe(0);
        await page.goto(`${origin}/demo-ai`);
        await expect(page.getByRole("status")).toContainText("Completed.");
        await page.clock.runFor(2100);
        await page.getByRole("button", { name: "Prepare a new evaluation" }).click();
        await expect.poll(() => Boolean(release)).toBe(true);
        await page.evaluate(() => document.querySelector("#demo-ai-app").__vue_app__.unmount());
        release();
        await page.clock.runFor(20000);
        await expect(page.locator("#demo-ai-app")).toBeEmpty();
        await page.goto(`${origin}/demo-ai`);
        await expect(page.getByRole("status")).toContainText("Completed.");
        await page.clock.runFor(2100);
        await expect(page.getByRole("button", { name: /Evaluate up to/ })).toHaveCount(0);
        expect(preparations).toBe(1);
        expect(admissions).toBe(0);
        expect(errors).toEqual([]);
    });

    test("admission pending is announced only after submission and an expired intent requires explicit renewal", async ({ page }) => {
        await mockIntent(page);
        let release;
        await page.route("**/demo-ai/runs", async (route) => {
            await new Promise((resolve) => { release = resolve; });
            await route.fulfill({ status: 422, json: { errors: { idempotency_key: ["Expired"] } } });
        });
        await enter(page);
        await expect(page.getByRole("status")).toContainText("Ready for a deliberate");
        await page.waitForTimeout(2100);
        await page.getByRole("button", { name: "Evaluate up to 20 fictional alerts" }).click();
        await expect(page.getByRole("status")).toContainText("Admission pending");
        await expect.poll(() => Boolean(release)).toBe(true);
        release();
        await expect(page.getByRole("alert")).toContainText("submission window");
        await expect(page.getByRole("button", { name: "Prepare a new evaluation" })).toBeVisible();
        await expect(page.getByRole("button", { name: "Retry same request" })).toHaveCount(0);
    });

    for (const [status, changes, text] of [
        ["completed", { selected_count: 0 }, "empty selection"],
        ["partial", { succeeded_count: 1, failed_count: 1 }, "Partial"],
        ["failed", { failed_count: 2 }, "Failed"],
        ["rejected", { selected_count: 0 }, "Rejected"],
    ]) {
        test(`renders ${status} honestly and stops terminal polling`, async ({ page }) => {
            await page.clock.install();
            await mockIntent(page, record("queued"));
            let polls = 0;
            await page.route("**/demo-ai/runs/*", (route) => {
                polls++;
                return route.fulfill({ json: record(status, changes) });
            });
            await enter(page);
            await expect(page.getByRole("status")).toContainText("Queued");
            await page.clock.runFor(2100);
            await expect(page.getByRole("status")).toContainText(text);
            await page.clock.runFor(20000);
            expect(polls).toBe(1);
        });
    }

    test("polls sequentially at two seconds, respects Retry-After and bounds network recovery", async ({ page }) => {
        await page.clock.install();
        await mockIntent(page, record("running"));
        let polls = 0;
        await page.route("**/demo-ai/runs/*", (route) => {
            polls++;
            if (polls === 1) return route.fulfill({ status: 429, headers: { "Retry-After": "10" }, json: {} });
            return route.abort("connectionreset");
        });
        await enter(page);
        await page.clock.runFor(1999);
        expect(polls).toBe(0);
        await page.clock.runFor(100);
        await expect(page.getByRole("alert")).toContainText("temporarily unavailable");
        await page.clock.runFor(9900);
        expect(polls).toBe(1);
        await page.clock.runFor(200);
        await expect.poll(() => polls).toBe(2);
        await page.clock.runFor(8100);
        await expect(page.getByRole("alert")).toContainText("paused after three");
        await page.clock.runFor(60000);
        expect(polls).toBe(3);
        await page.getByRole("button", { name: "Resume status checks" }).click();
        await page.clock.runFor(2100);
        await expect.poll(() => polls).toBe(4);
    });

    test("temporary failure recovers without stealing focus or overlapping slow responses", async ({ page }) => {
        await mockIntent(page, record("running"));
        let polls = 0;
        let release;
        await page.route("**/demo-ai/runs/*", async (route) => {
            polls++;
            if (polls === 1) return route.abort("connectionreset");
            await new Promise((resolve) => { release = resolve; });
            await route.fulfill({ json: record("completed", { succeeded_count: 2 }) });
        });
        await enter(page);
        const link = page.getByRole("link", { name: "Return to seeded review queue" });
        await link.focus();
        await expect(page.getByRole("alert")).toContainText("temporarily unavailable", { timeout: 5000 });
        await expect.poll(() => polls, { timeout: 7000 }).toBe(2);
        await page.waitForTimeout(2200);
        expect(polls).toBe(2);
        release();
        await expect(page.getByRole("status")).toContainText("Completed.");
        await expect(link).toBeFocused();
    });

    test("restores selected runs and refuses stale or out-of-order payloads for a different identity", async ({ page }) => {
        await page.clock.install();
        const oldRun = record("running");
        const current = record("running", { id: "01BBBBBBBBBBBBBBBBBBBBBBBB", selected_count: 7 });
        await mockIntent(page, oldRun);
        let polls = 0;
        await page.route("**/demo-ai/runs/*", (route) => {
            polls++;
            return route.fulfill({ json: polls === 1 ? record("completed") : { ...current, status: "completed", succeeded_count: 7 } });
        });
        await enter(page);
        await page.goto(`${origin}/demo-ai?run=${current.id}`);
        await expect(page.getByText(`Run ${current.id}`, { exact: true })).toBeVisible();
        await page.clock.runFor(2100);
        await expect(page.getByRole("alert")).toContainText("temporarily unavailable");
        await expect(page.getByText(`Run ${current.id}`, { exact: true })).toBeVisible();
        await expect(page.getByRole("status")).not.toContainText("Completed.");
        await page.clock.runFor(4100);
        await expect(page.getByRole("status")).toContainText("Completed.");
        await expect(page.getByText("7 selected; 7 evaluated successfully; 0 unsuccessful or unconfirmed.")).toBeVisible();
    });

    for (const status of [410, 401, 404]) {
        test(`stops polling on ${status} and never silently starts another run`, async ({ page }) => {
            await page.clock.install();
            await mockIntent(page, record("running"));
            let polls = 0;
            let admissions = 0;
            await page.route("**/demo-ai/runs", (route) => { admissions++; return route.abort(); });
            await page.route("**/demo-ai/runs/*", (route) => { polls++; return route.fulfill({ status, json: {} }); });
            await enter(page);
            await page.clock.runFor(2100);
            await expect(page.getByRole("status")).toContainText(status === 410 ? "expired" : "unavailable to this session");
            await page.clock.runFor(60000);
            expect(polls).toBe(1);
            expect(admissions).toBe(0);
        });
    }

    test("disabled mode, keyboard, narrow screens, long text and 200 percent zoom", async ({ page }) => {
        await mockIntent(page, null, false);
        await enter(page);
        await expect(page.getByText(/seeded review queue remains a static/)).toBeVisible();
        await expect(page.getByRole("button", { name: /Evaluate up to/ })).toHaveCount(0);
        await page.setViewportSize({ width: 320, height: 720 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.evaluate(() => { document.documentElement.style.zoom = "200%"; });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.keyboard.press("Tab");
        await expect(page.getByRole("link", { name: "Skip to evaluation" })).toBeFocused();
        await page.keyboard.press("Enter");
        await page.keyboard.press("Tab");
        await expect(page.getByRole("link", { name: "Return to seeded review queue" })).toBeFocused();
    });
});
