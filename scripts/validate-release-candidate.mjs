import { execFileSync } from "node:child_process";
import {
    appendFileSync,
    lstatSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { pathToFileURL } from "node:url";
import {
    archiveName,
    assertIdentity,
    digest,
    expectedFiles,
    lockedVersions,
    validateSbom,
} from "./prepare-release.mjs";

const requiredJobs = ["Quality", "Tests / MariaDB 11.4", "Secret scan"];
const validTime = (value) =>
    typeof value === "string" &&
    /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/.test(value) &&
    !Number.isNaN(Date.parse(value)) &&
    new Date(value).toISOString().slice(0, 19) === value.slice(0, 19);

export function validateEvidence({
    repository,
    ciRunId,
    version,
    smokePassed,
    smokeHash,
    smokeAt,
    run,
    attempt,
    workflow,
    jobs,
    artifacts,
    tagExists,
    releaseExists,
    reachable,
    now = new Date(),
}) {
    if (
        !/^[\w.-]+\/[\w.-]+$/.test(repository) ||
        !/^[1-9]\d*$/.test(String(ciRunId)) ||
        version !== "v1.0.0" ||
        smokePassed !== true ||
        !/^[0-9a-f]{64}$/.test(smokeHash) ||
        !validTime(smokeAt)
    )
        throw new Error("Invalid publication inputs");
    if (
        run?.id !== Number(ciRunId) ||
        run.event !== "push" ||
        run.head_branch !== "main" ||
        run.status !== "completed" ||
        run.conclusion !== "success" ||
        run.repository?.full_name !== repository ||
        !/^[0-9a-f]{40}$/.test(run.head_sha) ||
        !/^[1-9]\d*$/.test(String(run.run_attempt))
    )
        throw new Error("Ineligible CI run");
    if (
        workflow?.id !== run.workflow_id ||
        workflow.path !== ".github/workflows/ci.yml" ||
        run.path?.split("@")[0] !== workflow.path
    )
        throw new Error("Wrong CI workflow");
    if (
        attempt?.id !== run.id ||
        attempt.run_attempt !== run.run_attempt ||
        attempt.head_sha !== run.head_sha ||
        attempt.status !== "completed" ||
        attempt.conclusion !== "success" ||
        !validTime(attempt.updated_at)
    )
        throw new Error("Latest run attempt is incomplete");
    if (!reachable) throw new Error("Candidate not reachable from main");
    for (const name of requiredJobs) {
        const matches = jobs.filter((job) => job.name === name);
        if (
            matches.length !== 1 ||
            matches[0].status !== "completed" ||
            matches[0].conclusion !== "success" ||
            matches[0].head_sha !== run.head_sha
        )
            throw new Error(`Failed or missing check: ${name}`);
    }
    const artifactName = `release-candidate-${run.head_sha}-${run.run_attempt}`;
    const matches = artifacts.filter(
        (artifact) => artifact.name === artifactName,
    );
    if (
        matches.length !== 1 ||
        matches[0].expired ||
        !validTime(matches[0].expires_at) ||
        Date.parse(matches[0].expires_at) <= now.getTime() ||
        matches[0].workflow_run?.id !== run.id ||
        matches[0].workflow_run?.head_sha !== run.head_sha
    )
        throw new Error("Matching unexpired artifact unavailable");
    if (
        Date.parse(smokeAt) < Date.parse(attempt.updated_at) ||
        Date.parse(smokeAt) > now.getTime()
    )
        throw new Error("Smoke time is outside completed candidate interval");
    if (tagExists || releaseExists)
        throw new Error(
            "Tag or release already exists; inspect before retrying",
        );
    return {
        sha: run.head_sha,
        runId: run.id,
        runAttempt: run.run_attempt,
        artifactName,
        artifactId: matches[0].id,
        archive: archiveName(run.head_sha),
    };
}

export function validateFiles(directory, identity, smokeHash) {
    assertIdentity({
        repository: identity.repository,
        sha: identity.sha,
        runId: identity.runId,
        attempt: identity.runAttempt,
    });
    const names = expectedFiles(identity.sha);
    if (
        readdirSync(directory).sort().join("|") !==
            names.toSorted().join("|") ||
        names.some((name) => !lstatSync(join(directory, name)).isFile())
    )
        throw new Error("Artifact must contain exactly four regular files");
    const manifest = JSON.parse(
        readFileSync(join(directory, "candidate.json"), "utf8"),
    );
    if (
        Object.keys(manifest).sort().join("|") !==
            [
                "schema_version",
                "repository",
                "sha",
                "run_id",
                "run_attempt",
                "archive",
            ]
                .sort()
                .join("|") ||
        manifest.schema_version !== 1 ||
        manifest.repository !== identity.repository ||
        manifest.sha !== identity.sha ||
        manifest.run_id !== identity.runId ||
        manifest.run_attempt !== identity.runAttempt ||
        manifest.archive !== identity.archive
    )
        throw new Error("Candidate manifest mismatch");
    const checksumText = names
        .slice(0, 3)
        .map((name) => `${digest(join(directory, name))}  ${name}\n`)
        .join("");
    if (
        readFileSync(join(directory, "SHA256SUMS"), "utf8") !== checksumText ||
        digest(join(directory, identity.archive)) !== smokeHash
    )
        throw new Error("Archive or artifact checksum mismatch");
    const locked = (name) =>
        JSON.parse(
            execFileSync(
                "tar",
                ["-xOzf", join(directory, identity.archive), `./${name}`],
                { encoding: "utf8" },
            ),
        );
    validateSbom(
        JSON.parse(readFileSync(join(directory, "sbom.cdx.json"), "utf8")),
        lockedVersions(locked("composer.lock"), locked("package-lock.json")),
    );
    const members = execFileSync(
        "tar",
        ["-tzf", join(directory, identity.archive)],
        { encoding: "utf8" },
    )
        .trim()
        .split("\n");
    const verbose = execFileSync(
        "tar",
        ["-tvzf", join(directory, identity.archive)],
        { encoding: "utf8" },
    )
        .trim()
        .split("\n");
    if (
        members.length !== verbose.length ||
        !members.includes("./public/build/manifest.json") ||
        !members.includes("./composer.lock") ||
        !members.includes("./package-lock.json") ||
        new Set(members).size !== members.length
    )
        throw new Error("Incomplete or ambiguous archive");
    for (let index = 0; index < members.length; index++) {
        const member = members[index];
        const path = member.replace(/^\.\//, "").replace(/\/$/, "");
        if (
            (!verbose[index].startsWith("-") &&
                !verbose[index].startsWith("d")) ||
            !member.startsWith("./") ||
            path.split("/").some((part) => part === ".." || part === ".git") ||
            path.startsWith("/") ||
            (/(^|\/)\.env(\.|$)/.test(path) &&
                ![
                    ".env.example",
                    ".env.testing.example",
                    ".env.demo.example",
                ].includes(path)) ||
            /^(build|vendor|node_modules|test-results)(\/|$)/.test(path) ||
            (path.startsWith("storage/app/private") &&
                ![
                    "storage/app/private",
                    "storage/app/private/.gitignore",
                ].includes(path))
        )
            throw new Error("Unsafe archive member");
    }
    return { archiveSha256: smokeHash };
}

function cli(command, args) {
    return execFileSync(command, args, {
        encoding: "utf8",
        maxBuffer: 32 * 1024 * 1024,
    }).trim();
}

function api(repository, path, paginate = false) {
    return JSON.parse(
        cli("gh", [
            "api",
            ...(paginate ? ["--paginate", "--slurp"] : []),
            `repos/${repository}/${path}`,
        ]),
    );
}

function exists(repository, path) {
    try {
        api(repository, path);
        return true;
    } catch (error) {
        if (/HTTP 404/.test(String(error.stderr))) return false;
        throw error;
    }
}

export function publicationNotes(identity, smokeAt, smokeHash) {
    return `Candidate SHA: ${identity.sha}\nCI run: ${identity.runId}, attempt: ${identity.runAttempt}\nOperator-attested synthetic host smoke: ${smokeAt}\nArchive SHA-256: ${smokeHash}\n\nInstall the attached archive with locked production Composer dependencies and its compiled public/build assets. See the [release and rollback runbook](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/${identity.sha}/.github/docs/runbooks/phase5-release.md) and [portfolio screenshots and limits](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/${identity.sha}/.github/docs/portfolio.md). This is a synthetic portfolio demo, not a product-feasibility GO or comprehensive marketplace coverage.\n`;
}

export function publishCandidate({
    directory,
    identity,
    smokeAt,
    smokeHash,
    command = cli,
}) {
    const notes = join(directory, "release-notes.txt");
    writeFileSync(notes, publicationNotes(identity, smokeAt, smokeHash));
    try {
        command("gh", [
            "release",
            "create",
            "v1.0.0",
            ...expectedFiles(identity.sha).map((name) => join(directory, name)),
            "--repo",
            identity.repository,
            "--target",
            identity.sha,
            "--draft",
            "--title",
            "v1.0.0",
            "--notes-file",
            notes,
        ]);
        const draft = JSON.parse(
            command("gh", [
                "release",
                "view",
                "v1.0.0",
                "--repo",
                identity.repository,
                "--json",
                "isDraft,tagName,targetCommitish",
            ]),
        );
        if (
            !draft.isDraft ||
            draft.tagName !== "v1.0.0" ||
            draft.targetCommitish !== identity.sha
        )
            throw new Error("Draft release does not target the candidate SHA");
        command("gh", [
            "release",
            "edit",
            "v1.0.0",
            "--repo",
            identity.repository,
            "--draft=false",
        ]);
        let tag = JSON.parse(
            command("gh", [
                "api",
                `repos/${identity.repository}/git/ref/tags/v1.0.0`,
            ]),
        );
        for (let depth = 0; tag.object?.type === "tag" && depth < 3; depth++) {
            tag = JSON.parse(
                command("gh", [
                    "api",
                    `repos/${identity.repository}/git/tags/${tag.object.sha}`,
                ]),
            );
        }
        if (tag.object?.type !== "commit" || tag.object.sha !== identity.sha)
            throw new Error("Published tag does not target the candidate SHA");
    } catch (error) {
        throw new Error(
            `Publication stopped; inspect any draft/tag and assets before deliberate recovery: ${error.message}`,
        );
    } finally {
        rmSync(notes, { force: true });
    }
}

export function validateAndDownload({
    repository,
    ciRunId,
    version,
    smokePassed,
    smokeHash,
    smokeAt,
    directory,
    now = new Date(),
    gateway = { api, cli, exists },
}) {
    if (
        !/^[\w.-]+\/[\w.-]+$/.test(repository) ||
        !/^[1-9]\d*$/.test(String(ciRunId))
    )
        throw new Error("Invalid run selector");
    const run = gateway.api(repository, `actions/runs/${ciRunId}`);
    const attempt = gateway.api(
        repository,
        `actions/runs/${ciRunId}/attempts/${run.run_attempt}`,
    );
    const workflow = gateway.api(
        repository,
        `actions/workflows/${run.workflow_id}`,
    );
    const jobsResponse = gateway.api(
        repository,
        `actions/runs/${ciRunId}/attempts/${run.run_attempt}/jobs?per_page=100`,
        true,
    );
    const artifactsResponse = gateway.api(
        repository,
        `actions/runs/${ciRunId}/artifacts?per_page=100`,
        true,
    );
    const jobs = (
        Array.isArray(jobsResponse) ? jobsResponse : [jobsResponse]
    ).flatMap((page) => page.jobs);
    const artifacts = (
        Array.isArray(artifactsResponse)
            ? artifactsResponse
            : [artifactsResponse]
    ).flatMap((page) => page.artifacts);
    const reachable =
        gateway.cli("git", [
            "merge-base",
            "--is-ancestor",
            run.head_sha,
            "origin/main",
        ]) === "";
    const identity = validateEvidence({
        repository,
        ciRunId,
        version,
        smokePassed,
        smokeHash,
        smokeAt,
        run,
        attempt,
        workflow,
        jobs,
        artifacts,
        reachable,
        now,
        tagExists: gateway.exists(repository, "git/ref/tags/v1.0.0"),
        releaseExists: gateway.exists(repository, "releases/tags/v1.0.0"),
    });
    gateway.cli("gh", [
        "run",
        "download",
        String(ciRunId),
        "--repo",
        repository,
        "--name",
        identity.artifactName,
        "--dir",
        directory,
    ]);
    validateFiles(directory, { ...identity, repository }, smokeHash);
    return { ...identity, repository };
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
    const directory = mkdtempSync(join(tmpdir(), "verified-candidate-"));
    try {
        if (process.env.GITHUB_REF !== "refs/heads/main")
            throw new Error("Release must run from main");
        const smokeHash = process.env.SMOKE_ARCHIVE_SHA256;
        const smokeAt = process.env.SMOKE_VERIFIED_AT;
        const identity = validateAndDownload({
            repository: process.env.GITHUB_REPOSITORY,
            ciRunId: process.env.CI_RUN_ID,
            version: process.env.VERSION,
            smokePassed: process.env.TARGET_HOST_SMOKE_PASSED === "true",
            smokeHash,
            smokeAt,
            directory,
        });
        if (process.env.MODE === "publish") {
            if (
                process.env.VALIDATED_ARCHIVE_SHA256 !== smokeHash ||
                process.env.VALIDATED_CANDIDATE_SHA !== identity.sha
            )
                throw new Error("Validated candidate identity changed");
            publishCandidate({ directory, identity, smokeAt, smokeHash });
        } else if (process.env.MODE === "validate") {
            if (process.env.GITHUB_OUTPUT)
                appendFileSync(
                    process.env.GITHUB_OUTPUT,
                    `archive_sha256=${smokeHash}\ncandidate_sha=${identity.sha}\n`,
                );
        } else throw new Error("Invalid release mode");
    } catch (error) {
        console.error(`Release stopped: ${error.message}`);
        process.exitCode = 1;
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}
