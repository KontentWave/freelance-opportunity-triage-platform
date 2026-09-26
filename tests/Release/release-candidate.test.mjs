import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
    rmSync,
    symlinkSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
    prepareCandidate,
    validateSbom,
} from "../../scripts/prepare-release.mjs";
import {
    validateEvidence,
    validateFiles,
    publishCandidate,
    validateAndDownload,
} from "../../scripts/validate-release-candidate.mjs";

const repository = "KontentWave/freelance-opportunity-triage-platform";
const installed = (name, version, cataloger) => ({
    name,
    version,
    properties: [{ name: "syft:package:foundBy", value: cataloger }],
});
const sbom = () => ({
    bomFormat: "CycloneDX",
    components: [
        installed(
            "laravel/framework",
            "13.0.1",
            "php-composer-installed-cataloger",
        ),
        installed("vue", "3.5.43", "javascript-package-cataloger"),
        installed(
            "phpunit/phpunit",
            "13.3.4",
            "php-composer-installed-cataloger",
        ),
        installed("@playwright/test", "1.63.0", "javascript-package-cataloger"),
    ],
    metadata: { tools: { components: [{ name: "syft", version: "1.32.0" }] } },
});
const git = (root, ...args) =>
    execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();

function fixture() {
    const root = mkdtempSync(join(tmpdir(), "candidate-test-"));
    git(root, "init", "-q");
    git(root, "config", "user.email", "synthetic@example.test");
    git(root, "config", "user.name", "Synthetic");
    mkdirSync(join(root, "public/build"), { recursive: true });
    writeFileSync(join(root, "app.php"), "<?php // synthetic");
    writeFileSync(join(root, ".env.example"), "APP_KEY=");
    writeFileSync(
        join(root, "composer.lock"),
        JSON.stringify({
            packages: [{ name: "laravel/framework", version: "13.0.1" }],
        }),
    );
    writeFileSync(
        join(root, "package-lock.json"),
        JSON.stringify({
            packages: { "node_modules/vue": { version: "3.5.43" } },
        }),
    );
    git(
        root,
        "add",
        "app.php",
        ".env.example",
        "composer.lock",
        "package-lock.json",
    );
    git(root, "commit", "-qm", "synthetic");
    writeFileSync(join(root, "public/build/manifest.json"), "{}");
    mkdirSync(join(root, "public/build/assets"));
    writeFileSync(join(root, "public/build/assets/app.js"), "compiled");
    mkdirSync(join(root, "build"), { recursive: true });
    const sbomPath = join(root, "build/sbom.json");
    writeFileSync(sbomPath, JSON.stringify(sbom()));
    const output = join(root, "build/release");
    return {
        root,
        output,
        sbomPath,
        repository,
        sha: git(root, "rev-parse", "HEAD"),
        runId: 42,
        attempt: 2,
    };
}

test("packages only approved source and compiled assets", (context) => {
    const input = fixture();
    context.after(() => rmSync(input.root, { recursive: true, force: true }));
    writeFileSync(join(input.root, "app.php"), "dirty");
    writeFileSync(join(input.root, ".env"), "secret");
    const result = prepareCandidate(input);
    const paths = execFileSync(
        "tar",
        ["-tzf", join(input.output, result.archive)],
        { encoding: "utf8" },
    );
    assert.match(paths, /\.\/app.php/);
    assert.match(paths, /\.\/public\/build\/assets\/app.js/);
    assert.doesNotMatch(paths, /\.\/\.env\n|\.\/build\//);
    assert.equal(
        execFileSync(
            "tar",
            ["-xOzf", join(input.output, result.archive), "./app.php"],
            { encoding: "utf8" },
        ),
        "<?php // synthetic",
    );
    assert.throws(
        () => prepareCandidate({ ...input, sha: "a".repeat(40) }),
        /Checkout differs/,
    );
    writeFileSync(join(input.root, ".env.production"), "secret");
    git(input.root, "add", "-f", ".env.production");
    git(input.root, "commit", "-qm", "unsafe");
    assert.throws(
        () =>
            prepareCandidate({
                ...input,
                sha: git(input.root, "rev-parse", "HEAD"),
            }),
        /Unsafe tracked/,
    );
});

test("validates PHP and JavaScript SBOM evidence", (context) => {
    const input = fixture();
    context.after(() => rmSync(input.root, { recursive: true, force: true }));
    const result = prepareCandidate(input);
    validateSbom(
        JSON.parse(readFileSync(join(input.output, "sbom.cdx.json"), "utf8")),
    );
    for (const name of [
        "laravel/framework",
        "vue",
        "phpunit/phpunit",
        "@playwright/test",
    ]) {
        const invalid = sbom();
        invalid.components = invalid.components.filter(
            (item) => item.name !== name,
        );
        writeFileSync(input.sbomPath, JSON.stringify(invalid));
        assert.throws(() => prepareCandidate(input), /Missing installed/);
    }
    const stale = sbom();
    stale.components.find((item) => item.name === "vue").version = "3.5.42";
    writeFileSync(input.sbomPath, JSON.stringify(stale));
    assert.throws(() => prepareCandidate(input), /Missing installed vue/);
    writeFileSync(input.sbomPath, JSON.stringify(sbom()));
    writeFileSync(join(input.root, "public/build/.env"), "secret");
    assert.throws(() => prepareCandidate(input), /Unexpected compiled asset/);
    rmSync(join(input.root, "public/build/.env"));
    symlinkSync(
        join(input.root, ".env.example"),
        join(input.root, "public/build/assets/linked.js"),
    );
    assert.throws(() => prepareCandidate(input), /Unsafe compiled asset/);
    rmSync(join(input.root, "public/build/assets/linked.js"));
    symlinkSync(
        join(input.root, ".env.example"),
        join(input.root, "linked.txt"),
    );
    git(input.root, "add", "linked.txt");
    git(input.root, "commit", "-qm", "unsafe link");
    assert.throws(
        () =>
            prepareCandidate({
                ...input,
                sha: git(input.root, "rev-parse", "HEAD"),
            }),
        /Unsafe tracked/,
    );
    assert.ok(result.archiveSha256.length === 64);
});

function evidence(sha, hash) {
    const run = {
        id: 42,
        workflow_id: 12,
        path: ".github/workflows/ci.yml@refs/heads/main",
        repository: { full_name: repository },
        event: "push",
        head_branch: "main",
        status: "completed",
        conclusion: "success",
        head_sha: sha,
        run_attempt: 2,
    };
    return {
        repository,
        ciRunId: 42,
        version: "v1.0.0",
        smokePassed: true,
        smokeHash: hash,
        smokeAt: "2026-09-25T13:00:00Z",
        run,
        attempt: {
            id: 42,
            run_attempt: 2,
            head_sha: sha,
            status: "completed",
            conclusion: "success",
            updated_at: "2026-09-25T12:00:00Z",
        },
        workflow: { id: 12, path: ".github/workflows/ci.yml" },
        jobs: ["Quality", "Tests / MariaDB 11.4", "Secret scan"].map(
            (name) => ({
                name,
                head_sha: sha,
                status: "completed",
                conclusion: "success",
            }),
        ),
        artifacts: [
            {
                id: 7,
                name: `release-candidate-${sha}-2`,
                expired: false,
                expires_at: "2026-10-25T00:00:00Z",
                workflow_run: { id: 42, head_sha: sha },
            },
        ],
        tagExists: false,
        releaseExists: false,
        reachable: true,
        now: new Date("2026-09-26T00:00:00Z"),
    };
}

test("rejects ineligible runs and mismatched candidate evidence before publication", (context) => {
    const input = fixture();
    context.after(() => rmSync(input.root, { recursive: true, force: true }));
    const { archiveSha256 } = prepareCandidate(input);
    const valid = evidence(input.sha, archiveSha256);
    const identity = validateEvidence(valid);
    validateFiles(input.output, { ...identity, repository }, archiveSha256);
    const failures = [
        { ciRunId: 41 },
        { run: { ...valid.run, event: "pull_request" } },
        { run: { ...valid.run, head_branch: "feature" } },
        {
            run: {
                ...valid.run,
                repository: { full_name: "other/repository" },
            },
        },
        { run: { ...valid.run, conclusion: "failure" } },
        {
            workflow: {
                ...valid.workflow,
                path: ".github/workflows/other.yml",
            },
        },
        { attempt: { ...valid.attempt, run_attempt: 1 } },
        { attempt: { ...valid.attempt, head_sha: "0".repeat(40) } },
        { jobs: valid.jobs.slice(1) },
        {
            jobs: valid.jobs.map((job) =>
                job.name === "Quality"
                    ? { ...job, conclusion: "failure" }
                    : job,
            ),
        },
        {
            jobs: valid.jobs.map((job) => ({
                ...job,
                head_sha: "0".repeat(40),
            })),
        },
        {
            artifacts: [
                {
                    ...valid.artifacts[0],
                    name: `release-candidate-${input.sha}-1`,
                },
            ],
        },
        { artifacts: [{ ...valid.artifacts[0], expired: true }] },
        {
            artifacts: [
                {
                    ...valid.artifacts[0],
                    workflow_run: { id: 41, head_sha: input.sha },
                },
            ],
        },
        { smokePassed: false },
        { smokeAt: "2026-09-25T11:00:00Z" },
        { smokeAt: "2026-09-27T00:00:00Z" },
        { tagExists: true },
        { releaseExists: true },
        { reachable: false },
    ];
    for (const mutation of failures)
        assert.throws(
            () => validateEvidence({ ...valid, ...mutation }),
            JSON.stringify(mutation),
        );
    assert.throws(
        () =>
            validateFiles(
                input.output,
                { ...identity, repository },
                "a".repeat(64),
            ),
        /checksum mismatch/,
    );
    writeFileSync(join(input.output, "candidate.json"), "{}");
    assert.throws(
        () =>
            validateFiles(
                input.output,
                { ...identity, repository },
                archiveSha256,
            ),
        /manifest mismatch/,
    );
    prepareCandidate(input);
    writeFileSync(join(input.output, identity.archive), "altered");
    assert.throws(
        () =>
            validateFiles(
                input.output,
                { ...identity, repository },
                archiveSha256,
            ),
        /checksum mismatch/,
    );
});

test("promotes the verified candidate without rebuilding or overwriting a tag", (context) => {
    const input = fixture();
    context.after(() => rmSync(input.root, { recursive: true, force: true }));
    const { archiveSha256 } = prepareCandidate(input);
    const valid = evidence(input.sha, archiveSha256);
    const responses = {
        "actions/runs/42": valid.run,
        "actions/runs/42/attempts/2": valid.attempt,
        "actions/workflows/12": valid.workflow,
        "actions/runs/42/attempts/2/jobs?per_page=100": { jobs: valid.jobs },
        "actions/runs/42/artifacts?per_page=100": {
            artifacts: valid.artifacts,
        },
    };
    const commands = [];
    const gateway = {
        api: (_, path) => responses[path],
        exists: () => false,
        cli: (binary, args) => {
            commands.push([binary, args]);
            if (binary === "gh") {
                const destination = args.at(-1);
                for (const name of [
                    "candidate.json",
                    "SHA256SUMS",
                    "sbom.cdx.json",
                    `freelance-opportunity-triage-platform-${input.sha}.tar.gz`,
                ])
                    writeFileSync(
                        join(destination, name),
                        readFileSync(join(input.output, name)),
                    );
            }
            return "";
        },
    };
    const downloaded = mkdtempSync(join(tmpdir(), "downloaded-"));
    context.after(() => rmSync(downloaded, { recursive: true, force: true }));
    const identity = validateAndDownload({
        ...valid,
        directory: downloaded,
        gateway,
    });
    publishCandidate({
        directory: downloaded,
        identity,
        smokeAt: valid.smokeAt,
        smokeHash: archiveSha256,
        command: (binary, args) => {
            commands.push([binary, args]);
            if (args[1] === "view")
                return JSON.stringify({
                    isDraft: true,
                    tagName: "v1.0.0",
                    targetCommitish: identity.sha,
                });
            if (args[0] === "api")
                return JSON.stringify({
                    object: { type: "commit", sha: identity.sha },
                });
        },
    });
    assert.deepEqual(
        commands
            .filter(([binary]) => binary === "gh")
            .map(([, args]) => args.slice(0, 2)),
        [
            ["run", "download"],
            ["release", "create"],
            ["release", "view"],
            ["release", "edit"],
            ["api", `repos/${repository}/git/ref/tags/v1.0.0`],
        ],
    );
    assert.ok(commands.every(([, args]) => !args.includes("build")));
    assert.ok(commands.some(([, args]) => args.includes(input.sha)));
    const partial = [];
    assert.throws(
        () =>
            publishCandidate({
                directory: downloaded,
                identity,
                smokeAt: valid.smokeAt,
                smokeHash: archiveSha256,
                command: (_, args) => {
                    partial.push(args.slice(0, 2));
                    if (args[1] === "view")
                        return JSON.stringify({
                            isDraft: true,
                            tagName: "v1.0.0",
                            targetCommitish: "0".repeat(40),
                        });
                },
            }),
        /inspect any draft\/tag/,
    );
    assert.deepEqual(partial, [
        ["release", "create"],
        ["release", "view"],
    ]);
    assert.throws(
        () =>
            validateAndDownload({
                ...valid,
                directory: downloaded,
                gateway: { ...gateway, exists: () => true },
            }),
        /already exists/,
    );
});
