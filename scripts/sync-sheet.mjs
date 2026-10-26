import { createHash, randomUUID } from "node:crypto";
import { mkdir, readFile, rename, unlink, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

export const hash = (text) => createHash("sha256").update(text, "utf8").digest("hex");
const defaultRoot = fileURLToPath(new URL("../", import.meta.url));

async function optionalJson(path) {
    try {
        return JSON.parse(await readFile(path, "utf8"));
    } catch (error) {
        if (error.code === "ENOENT") return null;
        throw error;
    }
}

async function atomicWrite(path, contents) {
    await mkdir(dirname(path), { recursive: true });
    const temporary = `${path}.${randomUUID()}.tmp`;
    try {
        await writeFile(temporary, contents, { mode: 0o600, flag: "wx" });
        await rename(temporary, path);
    } finally {
        await unlink(temporary).catch((error) => {
            if (error.code !== "ENOENT") throw error;
        });
    }
}

export async function syncSheet({
    args,
    root = defaultRoot,
    env = process.env,
    request = fetch,
}) {
    const [action, ...options] = args;
    const force = options.at(-1) === "--force";
    if (force) options.pop();
    const [document = "sheet", ...extra] = options;
    if (
        !["pull", "push"].includes(action) ||
        !["sheet", "roadmap"].includes(document) ||
        (force && action !== "pull") ||
        extra.length
    ) {
        throw new Error("Usage: bash scripts/sync-sheet.sh pull [sheet|roadmap] [--force] | push [sheet|roadmap]");
    }

    const config = await optionalJson(join(root, ".vscode/sync-sheet.json"));
    const url = env.SYNC_SHEET_URL || config?.url;
    const token = env.SYNC_SHEET_TOKEN || config?.token;
    if (!url || !token || token === "REPLACE_WITH_A_NEW_RANDOM_TOKEN") {
        throw new Error(
            "Configure .vscode/sync-sheet.json from scripts/sync-sheet.config.example.json or set SYNC_SHEET_URL and SYNC_SHEET_TOKEN.",
        );
    }
    const endpoint = new URL(url);
    if (
        endpoint.protocol !== "https:" ||
        endpoint.hostname !== "script.google.com" ||
        endpoint.port || endpoint.username || endpoint.password ||
        !/^\/macros\/s\/[^/]+\/exec$/.test(endpoint.pathname) ||
        endpoint.search || endpoint.hash
    ) {
        throw new Error("Use the deployed https://script.google.com/macros/s/.../exec URL without a query string.");
    }

    const relativePath = document === "sheet" ? "docs/project_sheet.md" : "docs/PROJECT_ROADMAP.md";
    const localPath = join(root, relativePath);
    const syncDirectory = document === "sheet" ? ".vscode/sync-sheet" : ".vscode/sync-sheet/roadmap";
    const statePath = join(root, syncDirectory, "state.json");
    const state = await optionalJson(statePath);
    let local = null;
    try {
        local = await readFile(localPath, "utf8");
    } catch (error) {
        if (error.code !== "ENOENT") throw error;
    }
    if (action === "pull" && state && local !== null && hash(local) !== state.localHash && !force) {
        throw new Error(`Local edits detected in ${relativePath}. Push them first, or use pull ${document} --force to back them up and replace them.`);
    }
    if (action === "push" && (!state || !/^[a-f0-9]{64}$/.test(state.remoteHash))) {
        throw new Error("Pull once before pushing so remote changes can be detected.");
    }
    if (action === "push" && (!local?.trim() || /^\s*<(?:!doctype\s+html|html)\b/i.test(local))) {
        throw new Error("Refusing to push an empty file or an HTML error page.");
    }

    const response = await request(endpoint.href, {
        method: "POST",
        headers: { "Content-Type": "application/json; charset=utf-8" },
        body: JSON.stringify({
            action: document === "roadmap" ? `${action}-roadmap` : action,
            document,
            token,
            ...(action === "push" ? { markdown: local, baseHash: state.remoteHash } : {}),
        }),
        redirect: "follow",
        signal: AbortSignal.timeout(120_000),
    });
    if (!response.ok) throw new Error(`Google returned HTTP ${response.status}; no local file was replaced.`);
    const text = await response.text();
    if (/^\s*</.test(text)) {
        throw new Error("Google returned HTML, not sync data. Authorize the Apps Script in its editor and update the web-app deployment. No local file was replaced.");
    }
    let result;
    try {
        result = JSON.parse(text);
    } catch {
        throw new Error("Invalid sync response. Deploy the matching Apps Script; no local file was replaced.");
    }
    if (result?.ok !== true) {
        throw new Error(`Sync failed: ${typeof result?.error === "string" ? result.error : "invalid response"}`);
    }
    if (
        (document === "roadmap" && result.document !== document) ||
        (result.document !== undefined && result.document !== document)
    ) {
        throw new Error("The server did not confirm the requested document. Deploy the updated Apps Script; no local file was replaced.");
    }
    if (!/^[a-f0-9]{64}$/.test(result.hash)) throw new Error("Missing or invalid remote content hash.");

    if (action === "pull") {
        if (
            typeof result.markdown !== "string" ||
            !result.markdown.trim() ||
            /^\s*<(?:!doctype\s+html|html)\b/i.test(result.markdown) ||
            hash(result.markdown) !== result.hash
        ) {
            throw new Error("Invalid Markdown or content hash; no local file was replaced.");
        }
        let latest = null;
        try {
            latest = await readFile(localPath, "utf8");
        } catch (error) {
            if (error.code !== "ENOENT") throw error;
        }
        if (latest !== local) {
            throw new Error("The local file changed during the request. No local file was replaced; retry after reviewing your edits.");
        }
        if (local !== null && state?.remoteHash === result.hash && state.localHash === hash(local)) {
            return "Google Doc content is unchanged since the last sync. Local Markdown formatting was preserved.";
        }
        if (local !== null && local !== result.markdown) {
            const backup = join(root, syncDirectory, "backups", `${randomUUID()}.md`);
            await atomicWrite(backup, local);
        }
        await atomicWrite(localPath, result.markdown);
        local = result.markdown;
    }
    await atomicWrite(statePath, `${JSON.stringify({ remoteHash: result.hash, localHash: hash(local) }, null, 2)}\n`);
    return action === "pull"
        ? `Pulled ${relativePath}. Previous differing content was backed up under ${syncDirectory}/backups/.`
        : `Updated the existing native Google Doc for ${relativePath}. Local Markdown was preserved.`;
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
    syncSheet({ args: process.argv.slice(2) })
        .then((message) => console.log(message))
        .catch((error) => {
            console.error(`Sync error: ${error.message}`);
            console.error("If a push was attempted, check the Google Doc before retrying: a lost response does not prove the update failed.");
            process.exitCode = 1;
        });
}
