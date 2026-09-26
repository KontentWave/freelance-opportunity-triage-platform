import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import {
    cpSync,
    existsSync,
    lstatSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { basename, join, resolve, sep } from "node:path";
import { pathToFileURL } from "node:url";

export const prefix = "freelance-opportunity-triage-platform-";
export const digest = (path) =>
    createHash("sha256").update(readFileSync(path)).digest("hex");
export const archiveName = (sha) => `${prefix}${sha}.tar.gz`;
export const expectedFiles = (sha) => [
    archiveName(sha),
    "sbom.cdx.json",
    "candidate.json",
    "SHA256SUMS",
];
export const allowedAsset = (path) =>
    /^public\/build\/(?:manifest\.json|assets\/[A-Za-z0-9_.-]+\.(?:js|css|png|jpe?g|webp|gif|svg|woff2?|ico))$/.test(
        path,
    );

export function assertIdentity({ repository, sha, runId, attempt }) {
    if (
        !/^[\w.-]+\/[\w.-]+$/.test(repository) ||
        !/^[0-9a-f]{40}$/.test(sha) ||
        !/^[1-9]\d*$/.test(String(runId)) ||
        !/^[1-9]\d*$/.test(String(attempt))
    ) {
        throw new Error("Invalid candidate identity");
    }
}

export function validateSbom(sbom, versions = {}) {
    if (
        sbom.bomFormat !== "CycloneDX" ||
        !Array.isArray(sbom.components) ||
        !sbom.components.length
    ) {
        throw new Error("Missing CycloneDX components");
    }
    for (const [name, cataloger] of [
        ["laravel/framework", "php-composer-installed-cataloger"],
        ["vue", "javascript-package-cataloger"],
        ["phpunit/phpunit", "php-composer-installed-cataloger"],
        ["@playwright/test", "javascript-package-cataloger"],
    ]) {
        if (
            !sbom.components.some(
                (component) =>
                    component.name === name &&
                    typeof component.version === "string" &&
                    component.version.length > 0 &&
                    (!versions[name] || component.version === versions[name]) &&
                    component.properties?.some(
                        (property) =>
                            property.name === "syft:package:foundBy" &&
                            property.value === cataloger,
                    ),
            )
        ) {
            throw new Error(`Missing installed ${name} component`);
        }
    }
    const tools = sbom.metadata?.tools;
    const toolVersions = Array.isArray(tools) ? tools : tools?.components;
    if (
        !Array.isArray(toolVersions) ||
        !toolVersions.some(
            (tool) =>
                /syft/i.test(tool.name) && /^\d+\.\d+\.\d+/.test(tool.version),
        )
    ) {
        throw new Error("Missing Syft version");
    }
    if (
        !sbom.metadata?.properties?.some(
            (property) =>
                property.name === "inventory:scope" &&
                property.value === "build/dependency",
        )
    ) {
        throw new Error("Missing build/dependency inventory scope");
    }
}

export function lockedVersions(composer, npm) {
    const php = [
        ...(composer.packages ?? []),
        ...(composer["packages-dev"] ?? []),
    ].find((entry) => entry.name === "laravel/framework")?.version;
    const javascript = npm.packages?.["node_modules/vue"]?.version;
    if (!php || !javascript)
        throw new Error("Missing locked ecosystem versions");
    return { "laravel/framework": php, vue: javascript };
}

export function allowedTracked(path) {
    const parts = path.split("/");
    if (
        parts.some(
            (part) =>
                part === "." ||
                part === ".." ||
                part === "" ||
                part === ".git" ||
                part === "vendor" ||
                part === "node_modules",
        )
    )
        return false;
    if (
        parts.some(
            (part) =>
                /^\.env(\.|$)/.test(part) &&
                ![
                    ".env.example",
                    ".env.testing.example",
                    ".env.demo.example",
                ].includes(part),
        )
    )
        return false;
    if (
        parts.some((part) =>
            /(^auth\.json$|\.(key|pem|p12|sqlite|db|sql|log|trace)$)/i.test(
                part,
            ),
        )
    )
        return false;
    if (
        parts[0] === "build" ||
        parts[0] === "test-results" ||
        parts[0] === "vendor" ||
        parts[0] === "node_modules"
    )
        return false;
    if (path.startsWith("public/build/") || path.startsWith("public/storage/"))
        return false;
    if (
        path.startsWith("resources/triage/profiles/") &&
        !path.startsWith("resources/triage/profiles/demo-")
    )
        return false;
    if (
        parts[0] === "storage" ||
        (parts[0] === "bootstrap" && parts[1] === "cache")
    )
        return basename(path) === ".gitignore";
    if (
        parts.some((part) =>
            /^(credentials?|secrets?|private|profiles?|logs?|traces?|cache)$/i.test(
                part,
            ),
        ) &&
        !path.startsWith("resources/triage/profiles/demo-")
    )
        return false;
    return true;
}

function copyAssets(source, target) {
    if (
        !existsSync(source) ||
        !lstatSync(source).isDirectory() ||
        !existsSync(join(source, "manifest.json"))
    ) {
        throw new Error("Compiled Vite assets are missing");
    }
    function walk(from, to) {
        mkdirSync(to, { recursive: true });
        for (const entry of readdirSync(from, { withFileTypes: true })) {
            const input = join(from, entry.name);
            const output = join(to, entry.name);
            if (
                !allowedAsset(
                    `public/build/${input
                        .slice(source.length + 1)
                        .split(sep)
                        .join("/")}`,
                ) &&
                !entry.isDirectory()
            )
                throw new Error("Unexpected compiled asset");
            if (entry.isDirectory()) walk(input, output);
            else if (entry.isFile() && lstatSync(input).nlink === 1)
                cpSync(input, output);
            else throw new Error("Unsafe compiled asset");
        }
    }
    walk(source, target);
}

export function prepareCandidate({
    root,
    output,
    repository,
    sha,
    runId,
    attempt,
    sbomPath,
}) {
    assertIdentity({ repository, sha, runId, attempt });
    const actualSha = execFileSync("git", ["rev-parse", "HEAD"], {
        cwd: root,
        encoding: "utf8",
    }).trim();
    if (actualSha !== sha)
        throw new Error("Checkout differs from candidate SHA");
    const sbom = JSON.parse(readFileSync(sbomPath, "utf8"));
    sbom.metadata ??= {};
    sbom.metadata.properties = [
        ...(sbom.metadata.properties ?? []),
        { name: "inventory:scope", value: "build/dependency" },
    ];
    const versions = lockedVersions(
        JSON.parse(
            execFileSync("git", ["show", "HEAD:composer.lock"], {
                cwd: root,
                encoding: "utf8",
            }),
        ),
        JSON.parse(
            execFileSync("git", ["show", "HEAD:package-lock.json"], {
                cwd: root,
                encoding: "utf8",
            }),
        ),
    );
    validateSbom(sbom, versions);
    const tree = execFileSync("git", ["ls-tree", "-r", "-z", "HEAD"], {
        cwd: root,
    })
        .toString("utf8")
        .split("\0")
        .filter(Boolean);
    const stage = mkdtempSync(join(tmpdir(), "release-stage-"));
    try {
        for (const entry of tree) {
            const match = /^(\d{6}) blob [0-9a-f]{40}\t(.+)$/.exec(entry);
            if (
                !match ||
                !["100644", "100755"].includes(match[1]) ||
                !allowedTracked(match[2])
            ) {
                throw new Error(`Unsafe tracked path or file type: ${entry}`);
            }
            const path = match[2];
            const target = resolve(stage, path);
            if (!target.startsWith(`${stage}${sep}`))
                throw new Error("Path escapes staging");
            mkdirSync(resolve(target, ".."), { recursive: true });
            writeFileSync(
                target,
                execFileSync("git", ["show", `HEAD:${path}`], {
                    cwd: root,
                    maxBuffer: 32 * 1024 * 1024,
                }),
                { mode: match[1] === "100755" ? 0o755 : 0o644 },
            );
        }
        copyAssets(join(root, "public/build"), join(stage, "public/build"));
        mkdirSync(output, { recursive: true });
        const archive = archiveName(sha);
        execFileSync("tar", [
            "--sort=name",
            "--mtime=@0",
            "--owner=0",
            "--group=0",
            "--numeric-owner",
            "-czf",
            join(output, archive),
            "-C",
            stage,
            ".",
        ]);
        writeFileSync(
            join(output, "sbom.cdx.json"),
            `${JSON.stringify(sbom)}\n`,
        );
        writeFileSync(
            join(output, "candidate.json"),
            `${JSON.stringify({ schema_version: 1, repository, sha, run_id: Number(runId), run_attempt: Number(attempt), archive }, null, 2)}\n`,
        );
        writeFileSync(
            join(output, "SHA256SUMS"),
            [archive, "sbom.cdx.json", "candidate.json"]
                .map((name) => `${digest(join(output, name))}  ${name}\n`)
                .join(""),
        );
        if (
            readdirSync(output).sort().join("|") !==
            expectedFiles(sha).sort().join("|")
        )
            throw new Error("Release directory contains extra files");
        return { archive, archiveSha256: digest(join(output, archive)) };
    } finally {
        rmSync(stage, { recursive: true, force: true });
    }
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
    try {
        const root = process.cwd();
        const result = prepareCandidate({
            root,
            output: join(root, "build/release"),
            repository: process.env.GITHUB_REPOSITORY,
            sha: process.env.GITHUB_SHA,
            runId: process.env.GITHUB_RUN_ID,
            attempt: process.env.GITHUB_RUN_ATTEMPT,
            sbomPath: join(root, "build/sbom.cdx.json"),
        });
        console.log(`Prepared ${result.archive}`);
    } catch (error) {
        console.error(`Candidate preparation failed: ${error.message}`);
        process.exitCode = 1;
    }
}
