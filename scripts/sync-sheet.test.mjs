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
        assert.deepEqual(JSON.parse(options.body), { action: "pull", token: env.SYNC_SHEET_TOKEN });
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
            action: "push", token: env.SYNC_SHEET_TOKEN, markdown: local, baseHash: hash(remote),
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
    assert.deepEqual(tasks.tasks.map((task) => task.args[1]), ["pull", "push"]);
    const manifest = JSON.parse(await readFile(new URL("./sync-sheet-appsscript.json", import.meta.url), "utf8"));
    assert.ok(manifest.oauthScopes.includes("https://www.googleapis.com/auth/script.external_request"));
    assert.ok(manifest.oauthScopes.includes("https://www.googleapis.com/auth/drive"));
    assert.ok(manifest.dependencies.enabledAdvancedServices.some((service) => service.serviceId === "drive" && service.version === "v3"));
});

test("invalid actions and non-deployment URLs fail before networking", async (context) => {
    const { root } = await fixture(context);
    for (const args of [[], ["delete"], ["push", "--force"], ["pull", "--force", "extra"]]) {
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

async function appsScript({ current = "# Remote\n", supported = true, failExport = false } = {}) {
    const calls = [];
    let content = current;
    let locked = false;
    const context = vm.createContext({
        console,
        PropertiesService: { getScriptProperties: () => ({
            getProperty: (key) => ({ PROJECT_SHEET_DOC_ID: "synthetic-doc-id", SECRET_TOKEN: env.SYNC_SHEET_TOKEN })[key],
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
            if (url.includes("/export?")) {
                status = failExport ? 403 : 200;
                text = content;
            } else if (url.includes("/about?")) {
                text = JSON.stringify({ importFormats: { "text/markdown": supported ? ["application/vnd.google-apps.document"] : [] } });
            } else if (url.includes("/upload/")) {
                assert.equal(options.method, "patch");
                assert.ok(url.includes("/files/synthetic-doc-id?"));
                const payload = options.payload.toString("utf8");
                assert.ok(payload.includes('"mimeType":"application/vnd.google-apps.document"'));
                assert.ok(payload.includes("Content-Type: text/markdown; charset=UTF-8"));
                assert.ok(payload.includes("\r\n\r\n# Updated\n\r\n--"));
                content = "# Updated\n";
                text = JSON.stringify({ id: "synthetic-doc-id", mimeType: "application/vnd.google-apps.document" });
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
