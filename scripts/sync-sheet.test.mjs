import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { spawnSync } from "node:child_process";
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import { hash, syncSheet } from "./sync-sheet.mjs";

const env = {
    SYNC_SHEET_URL: "https://script.google.com/macros/s/test-deployment/exec",
    SYNC_SHEET_TOKEN: "synthetic-test-token",
};
const reply = (data) => new Response(JSON.stringify(data));

async function fixture(context) {
    const root = await mkdtemp(join(tmpdir(), "sheet-sync-test-"));
    context.after(() => rm(root, { recursive: true, force: true }));
    await mkdir(join(root, "docs"));
    const path = join(root, "docs/project_sheet.md");
    await writeFile(path, "# Local original\n");
    const run = (args, request) => syncSheet({ root, env, args, request });
    return { root, path, run };
}

test("pull backs up the previous file and records a verified baseline", async (context) => {
    const { root, path, run } = await fixture(context);
    const markdown = "# Remote phase\n\n**Formatted** text.\n";
    await run(["pull"], async (_, options) => {
        assert.equal(options.method, "POST");
        assert.deepEqual(JSON.parse(options.body), { action: "pull", document: "sheet", token: env.SYNC_SHEET_TOKEN });
        return reply({ ok: true, markdown, hash: hash(markdown) });
    });
    assert.equal(await readFile(path, "utf8"), markdown);
    const backups = join(root, ".vscode/sync-sheet/backups");
    const names = await readdir(backups);
    assert.equal(names.length, 1);
    assert.equal(await readFile(join(backups, names[0]), "utf8"), "# Local original\n");
    const state = JSON.parse(await readFile(join(root, ".vscode/sync-sheet/state.json"), "utf8"));
    assert.deepEqual(state, { remoteHash: hash(markdown), localHash: hash(markdown) });
});

test("HTML, HTTP failures, authorization errors and corrupt payloads never replace local Markdown", async (context) => {
    const { path, run } = await fixture(context);
    const failures = [
        () => new Response("<!DOCTYPE html><html>Authorization required</html>"),
        () => new Response("Unavailable", { status: 503 }),
        () => reply({ ok: false, error: "Unauthorized." }),
        () => new Response("SUCCESS"),
        () => reply({ ok: true, markdown: "# Wrong hash", hash: "a".repeat(64) }),
        () => reply({ ok: true, markdown: "", hash: hash("") }),
        () => reply({ ok: true, markdown: "<html>Error</html>", hash: hash("<html>Error</html>") }),
        () => reply(null),
        () => { throw new Error("Network failure"); },
    ];
    for (const request of failures) {
        await assert.rejects(run(["pull"], request));
        assert.equal(await readFile(path, "utf8"), "# Local original\n");
    }
});

test("pull refuses edits made while the network request is running", async (context) => {
    const { path, run } = await fixture(context);
    await assert.rejects(run(["pull"], async () => {
        await writeFile(path, "# New local edit\n");
        return reply({ ok: true, markdown: "# Remote\n", hash: hash("# Remote\n") });
    }), /changed during the request/);
    assert.equal(await readFile(path, "utf8"), "# New local edit\n");
});

test("local changes block pull unless force is explicit; force still keeps a backup", async (context) => {
    const { path, run } = await fixture(context);
    const remote = "# Remote\n";
    const request = async () => reply({ ok: true, markdown: remote, hash: hash(remote) });
    await run(["pull"], request);
    await writeFile(path, "# Unsynced local changes\n");
    await assert.rejects(run(["pull"], () => assert.fail("Must fail before networking")), /Local edits/);
    await run(["pull", "--force"], request);
    assert.equal(await readFile(path, "utf8"), remote);
});

test("push requires a prior pull and sends the last remote hash without rewriting local Markdown", async (context) => {
    const { root, path, run } = await fixture(context);
    await assert.rejects(run(["push"], () => assert.fail("Must not send data")), /Pull once/);
    const remote = "# Remote\n";
    await run(["pull"], async () => reply({ ok: true, markdown: remote, hash: hash(remote) }));
    const local = "# Local change\n";
    await writeFile(path, local);
    const normalized = "# Local change\n\n";
    await run(["push"], async (_, options) => {
        assert.deepEqual(JSON.parse(options.body), {
            action: "push", document: "sheet", token: env.SYNC_SHEET_TOKEN, markdown: local, baseHash: hash(remote),
        });
        return reply({ ok: true, hash: hash(normalized) });
    });
    assert.equal(await readFile(path, "utf8"), local);
    const state = JSON.parse(await readFile(join(root, ".vscode/sync-sheet/state.json"), "utf8"));
    assert.deepEqual(state, { remoteHash: hash(normalized), localHash: hash(local) });
});

test("failed pushes preserve the baseline and reject empty or HTML input before networking", async (context) => {
    const { root, path, run } = await fixture(context);
    const remote = "# Remote\n";
    await run(["pull"], async () => reply({ ok: true, markdown: remote, hash: hash(remote) }));
    const statePath = join(root, ".vscode/sync-sheet/state.json");
    const baseline = await readFile(statePath, "utf8");
    await writeFile(path, "# Local update\n");
    await assert.rejects(run(["push"], async () => reply({ ok: false, error: "Remote conflict" })), /Remote conflict/);
    assert.equal(await readFile(statePath, "utf8"), baseline);
    for (const invalid of ["", "  ", "<!DOCTYPE html><html>Error</html>"]) {
        await writeFile(path, invalid);
        await assert.rejects(run(["push"], () => assert.fail("Must not send data")), /Refusing to push/);
    }
});

test("pull after push preserves local formatting when the remote export is unchanged", async (context) => {
    const { root, path, run } = await fixture(context);
    const remote = "# Remote\n";
    await run(["pull"], async () => reply({ ok: true, markdown: remote, hash: hash(remote) }));
    const local = "# Local update\n";
    const normalized = "# Local update\n\n";
    await writeFile(path, local);
    await run(["push"], async () => reply({ ok: true, hash: hash(normalized) }));
    const backups = join(root, ".vscode/sync-sheet/backups");
    const before = await readdir(backups);
    const message = await run(["pull"], async () => reply({
        ok: true, markdown: normalized, hash: hash(normalized),
    }));
    assert.match(message, /formatting was preserved/);
    assert.equal(await readFile(path, "utf8"), local);
    assert.deepEqual(await readdir(backups), before);
});

test("pull still applies genuine remote changes after a successful push", async (context) => {
    const { path, run } = await fixture(context);
    const remote = "# Remote\n";
    await run(["pull"], async () => reply({ ok: true, markdown: remote, hash: hash(remote) }));
    await run(["push"], async () => reply({ ok: true, hash: hash(remote) }));
    const changed = "# Updated in Google Docs\n";
    await run(["pull"], async () => reply({ ok: true, markdown: changed, hash: hash(changed) }));
    assert.equal(await readFile(path, "utf8"), changed);
});

test("roadmap uses its own file, baseline and backups without changing sheet sync state", async (context) => {
    const { root, path, run } = await fixture(context);
    const sheet = "# Sheet remote\n";
    await run(["pull"], async () => reply({ ok: true, markdown: sheet, hash: hash(sheet) }));
    const sheetStatePath = join(root, ".vscode/sync-sheet/state.json");
    const sheetState = await readFile(sheetStatePath, "utf8");
    const roadmapPath = join(root, "docs/PROJECT_ROADMAP.md");
    await writeFile(roadmapPath, "# Original roadmap\n");
    await assert.rejects(run(["push", "roadmap"], () => assert.fail("Cannot reuse sheet baseline")), /Pull once/);
    const roadmap = "# Roadmap remote\n";
    await run(["pull", "roadmap"], async (_, options) => {
        assert.deepEqual(JSON.parse(options.body), {
            action: "pull-roadmap", document: "roadmap", token: env.SYNC_SHEET_TOKEN,
        });
        return reply({ ok: true, document: "roadmap", markdown: roadmap, hash: hash(roadmap) });
    });
    assert.equal(await readFile(roadmapPath, "utf8"), roadmap);
    const backups = join(root, ".vscode/sync-sheet/roadmap/backups");
    const names = await readdir(backups);
    assert.equal(names.length, 1);
    assert.equal(await readFile(join(backups, names[0]), "utf8"), "# Original roadmap\n");
    const changed = "# Updated roadmap\n";
    await writeFile(roadmapPath, changed);
    await run(["push", "roadmap"], async (_, options) => {
        assert.deepEqual(JSON.parse(options.body), {
            action: "push-roadmap", document: "roadmap", token: env.SYNC_SHEET_TOKEN,
            markdown: changed, baseHash: hash(roadmap),
        });
        return reply({ ok: true, document: "roadmap", hash: hash(changed) });
    });
    const state = JSON.parse(await readFile(join(root, ".vscode/sync-sheet/roadmap/state.json"), "utf8"));
    assert.deepEqual(state, { remoteHash: hash(changed), localHash: hash(changed) });
    assert.equal(await readFile(sheetStatePath, "utf8"), sheetState);
    assert.equal(await readFile(path, "utf8"), sheet);
});

test("roadmap dirty pulls require force and unchanged exports preserve formatting", async (context) => {
    const { root, path, run } = await fixture(context);
    const roadmapPath = join(root, "docs/PROJECT_ROADMAP.md");
    const remote = "# Roadmap\n";
    const request = async () => reply({ ok: true, document: "roadmap", markdown: remote, hash: hash(remote) });
    await run(["pull", "roadmap"], request);
    await writeFile(roadmapPath, "# Unpublished roadmap\n");
    await assert.rejects(run(["pull", "roadmap"], () => assert.fail("Must preserve local edits")), /Local edits/);
    await run(["pull", "roadmap", "--force"], request);
    assert.equal(await readFile(roadmapPath, "utf8"), remote);
    await writeFile(roadmapPath, "# Roadmap update\n");
    const normalized = "# Roadmap update\n\n";
    await run(["push", "roadmap"], async () => reply({ ok: true, document: "roadmap", hash: hash(normalized) }));
    const result = await run(["pull", "roadmap"], async () => reply({
        ok: true, document: "roadmap", markdown: normalized, hash: hash(normalized),
    }));
    assert.match(result, /formatting was preserved/);
    assert.equal(await readFile(roadmapPath, "utf8"), "# Roadmap update\n");
    assert.equal(await readFile(path, "utf8"), "# Local original\n");
});

test("roadmap rejects missing or mismatched document confirmation without replacing either file", async (context) => {
    const { root, path, run } = await fixture(context);
    const roadmapPath = join(root, "docs/PROJECT_ROADMAP.md");
    await writeFile(roadmapPath, "# Original roadmap\n");
    for (const document of [undefined, "sheet", "unknown"]) {
        await assert.rejects(run(["pull", "roadmap"], async () => reply({
            ok: true, document, markdown: "# Wrong document\n", hash: hash("# Wrong document\n"),
        })), /did not confirm/);
    }
    assert.equal(await readFile(path, "utf8"), "# Local original\n");
    assert.equal(await readFile(roadmapPath, "utf8"), "# Original roadmap\n");
});

test("roadmap actions are rejected by a legacy server before it can write the sheet", async (context) => {
    const { root, run } = await fixture(context);
    const remote = "# Roadmap\n";
    await run(["pull", "roadmap"], async () => reply({
        ok: true, document: "roadmap", markdown: remote, hash: hash(remote),
    }));
    const statePath = join(root, ".vscode/sync-sheet/roadmap/state.json");
    const before = await readFile(statePath, "utf8");
    for (const action of ["pull", "push"]) {
        await assert.rejects(run([action, "roadmap"], async (_, options) => {
            const input = JSON.parse(options.body);
            assert.ok(!["pull", "push"].includes(input.action), "Legacy server must reject the action before writing");
            return reply({ ok: false, error: "Invalid sync action." });
        }), /Invalid sync action/);
    }
    assert.equal(await readFile(statePath, "utf8"), before);
});

test("shell entry point resolves the client outside the repository working directory", () => {
    const result = spawnSync("bash", [
        fileURLToPath(new URL("./sync-sheet.sh", import.meta.url)),
    ], { cwd: tmpdir(), encoding: "utf8" });
    assert.equal(result.status, 1);
    assert.match(result.stderr, /Usage: bash scripts\/sync-sheet.sh/);
    assert.ok(!result.stderr.includes("MODULE_NOT_FOUND"));
});

test("loads ignored local configuration and lets environment values override it", async (context) => {
    const { root } = await fixture(context);
    await mkdir(join(root, ".vscode"));
    await writeFile(join(root, ".vscode/sync-sheet.json"), JSON.stringify({
        url: env.SYNC_SHEET_URL, token: env.SYNC_SHEET_TOKEN,
    }));
    for (const overrides of [{}, { SYNC_SHEET_TOKEN: "synthetic-override-token" }]) {
        await syncSheet({
            root, args: ["pull"], env: overrides,
            request: async (url, options) => {
                assert.equal(url, env.SYNC_SHEET_URL);
                assert.equal(JSON.parse(options.body).token, overrides.SYNC_SHEET_TOKEN || env.SYNC_SHEET_TOKEN);
                return reply({ ok: true, markdown: "# Remote\n", hash: hash("# Remote\n") });
            },
        });
    }
});

test("configuration, VS Code task and Apps Script manifest templates are valid", async () => {
    const config = JSON.parse(await readFile(new URL("./sync-sheet.config.example.json", import.meta.url), "utf8"));
    assert.equal(config.token, "REPLACE_WITH_A_NEW_RANDOM_TOKEN");
    const tasks = JSON.parse(await readFile(new URL("./sync-sheet.tasks.example.json", import.meta.url), "utf8"));
    assert.deepEqual(tasks.tasks.map((task) => task.args.slice(1).join(" ")).sort(), [
        "pull", "pull roadmap", "push", "push roadmap",
    ]);
    const manifest = JSON.parse(await readFile(new URL("./sync-sheet-appsscript.json", import.meta.url), "utf8"));
    assert.ok(manifest.oauthScopes.includes("https://www.googleapis.com/auth/script.external_request"));
    assert.ok(manifest.oauthScopes.includes("https://www.googleapis.com/auth/drive"));
    assert.ok(manifest.dependencies.enabledAdvancedServices.some((service) => service.serviceId === "drive" && service.version === "v3"));
});

test("invalid actions and non-deployment URLs fail before networking", async (context) => {
    const { root } = await fixture(context);
    for (const args of [
        [], ["delete"], ["push", "--force"], ["pull", "--force", "extra"],
        ["pull", "../roadmap"], ["push", "roadmap", "--force"], ["pull", "unknown"],
    ]) {
        await assert.rejects(syncSheet({ root, args, env }), /Usage:/);
    }
    for (const url of [
        "http://script.google.com/macros/s/test/exec",
        "https://example.com/exec",
        "https://script.google.com/macros/s/test/dev",
        `${env.SYNC_SHEET_URL}?token=secret`,
    ]) {
        await assert.rejects(syncSheet({
            root, args: ["pull"], env: { ...env, SYNC_SHEET_URL: url },
            request: () => assert.fail("Must not send data"),
        }), /deployed/);
    }
});

async function appsScript({
    current = "# Remote\n",
    roadmapCurrent = "# Roadmap\n",
    supported = true,
    failExport = false,
    properties = {},
} = {}) {
    const calls = [];
    const contents = { "synthetic-doc-id": current, "synthetic-roadmap-id": roadmapCurrent };
    let locked = false;
    const context = vm.createContext({
        console,
        PropertiesService: { getScriptProperties: () => ({
            getProperty: (key) => ({
                PROJECT_SHEET_DOC_ID: "synthetic-doc-id",
                PROJECT_ROADMAP_DOC_ID: "synthetic-roadmap-id",
                SECRET_TOKEN: env.SYNC_SHEET_TOKEN,
                ...properties,
            })[key],
        }) },
        ContentService: {
            MimeType: { JSON: "application/json" },
            createTextOutput: (text) => ({ setMimeType: () => JSON.parse(text) }),
        },
        ScriptApp: { getOAuthToken: () => "synthetic-oauth-token" },
        LockService: { getScriptLock: () => ({
            waitLock: () => { locked = true; },
            hasLock: () => locked,
            releaseLock: () => { locked = false; },
        }) },
        Utilities: {
            DigestAlgorithm: { SHA_256: "sha256" },
            Charset: { UTF_8: "utf8" },
            computeDigest: (_, text) => [...createHash("sha256").update(text).digest()],
            getUuid: () => "synthetic-boundary",
            newBlob: (text) => ({ getBytes: () => Buffer.from(text) }),
        },
        UrlFetchApp: { fetch: (url, options) => {
            calls.push({ url, options });
            let status = 200;
            let text;
            const docId = /\/files\/([^/?]+)/.exec(url)?.[1];
            if (url.includes("/export?")) {
                assert.ok(Object.hasOwn(contents, docId));
                status = failExport ? 403 : 200;
                text = contents[docId];
            } else if (url.includes("/about?")) {
                text = JSON.stringify({ importFormats: { "text/markdown": supported ? ["application/vnd.google-apps.document"] : [] } });
            } else if (url.includes("/upload/")) {
                assert.equal(options.method, "patch");
                assert.ok(Object.hasOwn(contents, docId));
                const payload = options.payload.toString("utf8");
                assert.ok(payload.includes('"mimeType":"application/vnd.google-apps.document"'));
                assert.ok(payload.includes("Content-Type: text/markdown; charset=UTF-8"));
                assert.ok(payload.includes("\r\n\r\n# Updated\n\r\n--"));
                contents[docId] = "# Updated\n";
                text = JSON.stringify({ id: docId, mimeType: "application/vnd.google-apps.document" });
            } else {
                assert.fail(`Unexpected URL: ${url}`);
            }
            return { getResponseCode: () => status, getContentText: () => text };
        } },
    });
    vm.runInContext(await readFile(new URL("./sync-sheet-apps-script.gs", import.meta.url), "utf8"), context);
    return {
        calls,
        post: (input) => context.doPost({ postData: { contents: JSON.stringify(input) } }),
        authorize: () => context.authorizeSync(),
        isLocked: () => locked,
    };
}

test("Apps Script exports verified Markdown and releases its lock", async () => {
    const app = await appsScript();
    const result = app.post({ action: "pull", token: env.SYNC_SHEET_TOKEN });
    assert.equal(result.ok, true);
    assert.equal(result.markdown, "# Remote\n");
    assert.equal(result.hash, hash(result.markdown));
    assert.equal(app.isLocked(), false);
});

test("Apps Script refuses unauthorized requests, conflicts and unsupported import before mutation", async () => {
    for (const scenario of [
        { token: "wrong", baseHash: hash("# Remote\n"), supported: true },
        { token: env.SYNC_SHEET_TOKEN, baseHash: hash("# Stale\n"), supported: true },
        { token: env.SYNC_SHEET_TOKEN, baseHash: hash("# Remote\n"), supported: false },
    ]) {
        const app = await appsScript(scenario);
        const result = app.post({ action: "push", markdown: "# Updated\n", ...scenario });
        assert.equal(result.ok, false);
        assert.ok(!app.calls.some(({ options }) => options.method === "patch"));
        assert.equal(app.isLocked(), false);
    }
});

test("Apps Script imports Markdown into the same native Google Doc and returns the normalized export hash", async () => {
    const app = await appsScript();
    const result = app.post({
        action: "push", token: env.SYNC_SHEET_TOKEN, baseHash: hash("# Remote\n"),
        markdown: "# Updated\n",
    });
    assert.equal(result.ok, true);
    assert.equal(result.hash, hash("# Updated\n"));
    assert.equal(app.calls.filter(({ options }) => options.method === "patch").length, 1);
    assert.equal(app.isLocked(), false);
});

test("Apps Script reports Drive permission errors as failures, not Markdown", async () => {
    const app = await appsScript({ failExport: true });
    const result = app.post({ action: "pull", token: env.SYNC_SHEET_TOKEN });
    assert.equal(result.ok, false);
    assert.match(result.error, /HTTP 403/);
    assert.equal(app.isLocked(), false);
});

test("Apps Script routes roadmap reads and writes only to its configured Google Doc", async () => {
    const app = await appsScript();
    const pulled = app.post({ action: "pull-roadmap", document: "roadmap", token: env.SYNC_SHEET_TOKEN });
    assert.equal(pulled.ok, true);
    assert.equal(pulled.document, "roadmap");
    assert.equal(pulled.markdown, "# Roadmap\n");
    const pushed = app.post({
        action: "push-roadmap", document: "roadmap", token: env.SYNC_SHEET_TOKEN,
        baseHash: pulled.hash, markdown: "# Updated\n",
    });
    assert.equal(pushed.ok, true);
    assert.equal(pushed.document, "roadmap");
    assert.equal(pushed.hash, hash("# Updated\n"));
    assert.equal(app.calls.filter(({ options }) => options.method === "patch").length, 1);
    assert.ok(app.calls.every(({ url }) => !url.includes("/files/synthetic-doc-id")));
    assert.equal(app.isLocked(), false);
});

test("Apps Script rejects missing, duplicate and unknown roadmap targets without Drive calls", async () => {
    for (const scenario of [
        { properties: { PROJECT_ROADMAP_DOC_ID: null }, document: "roadmap" },
        { properties: { PROJECT_ROADMAP_DOC_ID: "synthetic-doc-id" }, document: "roadmap" },
        { properties: {}, document: "unknown" },
        { properties: {}, document: "__proto__" },
    ]) {
        const app = await appsScript(scenario);
        const result = app.post({ action: "pull-roadmap", document: scenario.document, token: env.SYNC_SHEET_TOKEN });
        assert.equal(result.ok, false);
        assert.equal(app.calls.length, 0);
    }
});

test("Apps Script keeps legacy sheet requests working without roadmap configuration", async () => {
    const app = await appsScript({ properties: { PROJECT_ROADMAP_DOC_ID: null } });
    const result = app.post({ action: "pull", token: env.SYNC_SHEET_TOKEN });
    assert.equal(result.ok, true);
    assert.equal(result.document, "sheet");
    assert.equal(result.markdown, "# Remote\n");
});

test("Apps Script refuses mismatched actions and roadmap conflicts before mutation", async () => {
    const app = await appsScript();
    for (const input of [
        { action: "push", document: "roadmap", baseHash: hash("# Roadmap\n") },
        { action: "push-roadmap", document: "sheet", baseHash: hash("# Remote\n") },
        { action: "push-roadmap", document: "roadmap", baseHash: hash("# Stale roadmap\n") },
    ]) {
        const result = app.post({ ...input, token: env.SYNC_SHEET_TOKEN, markdown: "# Updated\n" });
        assert.equal(result.ok, false);
    }
    assert.ok(app.calls.every(({ options }) => options.method !== "patch"));
    assert.equal(app.isLocked(), false);
});

test("authorization checks both configured document exports without modifying either", async () => {
    const app = await appsScript();
    app.authorize();
    const exports = app.calls.filter(({ url }) => url.includes("/export?"));
    assert.equal(exports.length, 2);
    assert.ok(exports.some(({ url }) => url.includes("/files/synthetic-doc-id/")));
    assert.ok(exports.some(({ url }) => url.includes("/files/synthetic-roadmap-id/")));
    assert.ok(app.calls.every(({ options }) => options.method === "get"));
});
