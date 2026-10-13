# Autonomous Web QA & Repair Agent — implementation roadmap

Draft for discussion, 13 October 2026. Read alongside [the product plan](PRODUCT_PLAN.md).

This roadmap is ordered by completion gates. Dates should be assigned after the deadline and team size are known. The model and hardware choices remain hypotheses until measured on the actual CPU-only host under the 8 GB limit.

## Architecture proposal

Use Laravel for authentication, authorization, durable run state, queued stages, scheduling, and GitHub integration. Use Livewire for project setup, run progress, findings, and repair review. Begin with a database-backed queue and one heavy job at a time.

A private Node/Playwright worker controls server-side Chromium. A single model service supplies browser action proposals and repair proposals. Separate roles share this model; each role receives only the context and tool schemas relevant to its current stage.

Model output passes through schema validation, project policy, and stage validation before any tool executes. Laravel owns completion status. Browser results, test results, and GitHub state supply the evidence.

For the initial model experiment, try quantized Qwen3-1.7B with llama.cpp. The model's official card documents local runtime support and tool integration, and llama.cpp documents CPU inference and quantization. Neither establishes repair reliability or latency on this deployment; benchmark both before committing. [Qwen model card](https://huggingface.co/Qwen/Qwen3-1.7B), [llama.cpp](https://github.com/ggml-org/llama.cpp).

Persistent records:

- `Project`: owner, target origins, repository, policy, credential references.
- `Run`: goal, acceptance criteria, status, source commit, limits, timestamps.
- `Step`: proposed action, validated arguments, result, short decision summary.
- `Scenario`: reproducible actions, fixture requirements, assertions.
- `Finding`: expected/actual result, reproduction status, severity rationale, evidence references.
- `RepairAttempt`: base commit, patch, branch, attempt count, validation status.
- `Verification`: test command identifiers, results, preview commit, replay result.
- `Artifact`: screenshot, trace, sanitized log, retention metadata.

Credentials are stored separately from reports and model context.

## Milestone 0 — prove feasibility

Build a small experiment before the full dashboard:

1. Run a CPU-only quantized candidate model under the actual 8 GB ceiling.
2. Give it compact page snapshots and a small action schema. Measure valid action selection, not just valid JSON.
3. Test a narrow diagnosis/patch task using only relevant Laravel files.
4. Run one Chromium session, then a representative test/build stage. Measure whole-host peak memory, CPU contention, and elapsed time.
5. Fix context limits, output limits, deadlines, and concurrency from these observations.

Proposed initial acceptance: at least 9 of 10 small browser-decision cases select a valid, appropriate action within the configured retry limit; a narrow patch task produces a valid candidate that passes the reproduction check. Tune the evaluation set and timing budget to the course's needs.

One failed attempt is not a reason to expand hardware requirements. First reduce context, tighten tool choices, adjust output budgets, or evaluate another CPU model within the same ceiling. If unrestricted patch generation remains unreliable, discuss narrowing supported repair classes or using explicitly disclosed repair templates. Do not silently substitute hosted inference.

**Done when:** one small end-to-end experiment works within measured resource limits, with the failure rate and timing recorded.

## Milestone 1 — controlled project and durable runs

Create project setup, test credentials, run limits, statuses, and a Stop action. Capture user-defined expectations rather than asking the model to invent business requirements.

Use queued stage transitions, persisted checkpoints, execution locks, and idempotent handlers. A retried job must not create duplicate test accounts, branches, or PRs. Preserve short observations and action summaries; the audit trail need not contain private model reasoning.

Validate allowed origins and redirect destinations. Reject arbitrary user targets resolving to loopback, private networks, or metadata endpoints, and enforce restrictions at network egress as well as in application logic. Any internal preview service uses an administrator-configured route rather than a user-supplied exception.

**Done when:** a run can be started, stopped, resumed after worker failure, and inspected without duplicate side effects.

## Milestone 2 — browser QA and reproducible evidence

Expose a small tool set: navigate, inspect, click, fill, select, keyboard action, assert, capture evidence, and finish scenario. The worker validates arguments and ties element references to a snapshot version.

Start with roles, labels, and visible text. Playwright recommends user-facing locators; its traces can provide DOM snapshots and network context. [Locator guidance](https://playwright.dev/docs/locators), [trace viewer](https://playwright.dev/docs/trace-viewer).

Then add limited fallback extraction: visible controls, nearby text, attributes, focus state, and bounding boxes. Refresh observations after a re-render. Verify the effect of each action before proceeding. DOM-derived pointer and keyboard actions stay inside server Chromium. Canvas-only controls remain blocked in the first version.

The model chooses meaningful next steps within the goal. Deterministic checks detect HTTP failures, console exceptions, missing expected navigation, and explicit assertion failures. Failed network events can be incidental; correlate them with the scenario before calling them product bugs.

Store enough actions and assertions to replay the original case without asking the model to rediscover it. Reproduce in a fresh session with reset fixtures. Distinguish a product defect from a transient service failure or an automation blocker. Remove secrets and sensitive test data from artifacts before exposing them in reports.

**Done when:** the agent finds the seeded registration failure, produces a replayable scenario, and reports the clean control correctly.

## Milestone 3 — source-aware diagnosis

Connect one GitHub repository and bind the staging deployment to an exact commit. Use a trusted deployment manifest or setup record; source mismatch blocks verified repair.

Narrow candidate files using observed routes, sanitized logs available through a protected channel, symbols, and related tests. Start with route/controller/request/service/test relationships rather than building a complete dependency graph.

Give the model the finding, explicit expected behavior, and a small collection of relevant files. Ask for a diagnosis, supporting references, proposed patch, and regression-test intent. Missing business requirements produce a request for clarification or a diagnosis-only result.

**Done when:** it identifies the cause of the seeded bug from evidence and matching source, without receiving the bug's file location in the testing goal.

## Milestone 4 — restricted repair and tests

Create an isolated workspace at the recorded base commit. The patch validator limits permitted paths, file count, and diff size. Initial scope excludes dependency changes, database migrations, authentication redesign, and CI/workflow modifications.

Use a fixed, project-configured build/test recipe. The model cannot choose arbitrary shell commands. Repository scripts and generated tests still execute code, so the runner must be disposable, unprivileged, resource-limited, and separated from the orchestrator's credentials and host mounts. Scope network access and use disposable database/services.

Verification sequence:

1. Confirm the baseline application's existing relevant tests.
2. Apply only the new regression test to the original source and show the expected failure.
3. Apply the patch and rerun the new test.
4. Run existing relevant tests, static checks, and build requirements.
5. Stop after a proposed maximum of two patch attempts; preserve the failed attempts and report the unresolved issue.

Baseline failures must be recorded, not attributed to the generated patch. Tests cannot be removed or assertions weakened to obtain a passing result.

**Done when:** the same behavior fails before the patch and passes after it, and required existing checks also pass.

## Milestone 5 — preview, replay, and draft PR

For the single-server baseline, commit the validated patch and regression test on a local fix branch in the isolated workspace. Start a temporary preview of that exact commit on the same server, using a disposable database and an administrator-configured internal browser route. Stop or unload other heavy processes as measurements require before starting the preview and Chromium. Capture the verification artifacts, then remove the preview and its fixtures after the run.

An optional remote execution path pushes the candidate branch, runs CI, and deploys a branch preview before opening the verified draft PR. GitHub Actions supports branch-filtered push triggers, allowing validation without requiring an existing PR. [Workflow events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows).

For either path, accept a preview only when its repository, branch, and commit match the current repair attempt. Authenticate any remote callbacks and validate preview origins. Replay the frozen failing scenario with the same acceptance criteria, reset fixtures, and a fresh browser session. Run a normal registration/login control using separately reset fixtures and another fresh session, so previously created accounts or reused emails cannot invalidate the result.

Publish the verified draft PR only after these required gates pass:

- Reproduced original failure.
- Matching base source and patched preview commits.
- Regression test fails on base and passes on patch.
- Required existing checks pass.
- Original browser scenario and control scenario pass on preview.

Use explicit gate results instead of a model-generated confidence percentage. An unavailable preview or inconclusive replay produces an unverified repair result, not a verified PR.

A GitHub App is the proposed integration boundary. Choose the minimum permissions for the implemented endpoints: repository contents and PR writes, check/action reads as needed, and deployment access only if the chosen integration uses it. Issue writes and workflow editing are unnecessary for the initial loop. Confirm endpoint requirements during implementation. [GitHub App permission guidance](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/choosing-permissions-for-a-github-app).

After local verification, push the same verified commit to the remote fix branch and confirm its SHA. If configured repository rules require remote CI results, wait for those before opening the draft PR. The remote-preview path has already pushed the branch and must likewise confirm its current SHA. The PR describes the problem, root cause, changes, test results, replay evidence, exact verified commit, and practical limits. Revalidate if branch content changes before publication. Retried callbacks must not create duplicate PRs.

**Done when:** a human can inspect one real draft PR and follow its evidence from original failure through patched browser result.

## Resource strategy under 8 GB

The baseline assumes the same CPU-only server coordinates the model, browser, and repair stages. Serialize heavy stages; close browser sessions before large test/build work and unload the model if measured memory use requires it. An idle process still occupies RAM.

Use one model instance, one browser session, bounded context/output, bounded log storage, and explicit container memory/time limits. Reserve whole-host headroom rather than assigning all 8 GB to model weights. Select exact limits after measurement; no latency, VM sizing, or all-services-fit guarantee is established yet.

Separate GitHub Actions or another cloud test runner may reduce contention, but this is an optional deployment decision requiring confirmation that those resources are allowed. It is not assumed as a way around the RAM constraint. CI and previews introduce their own availability and budget requirements.

## Extensions after the complete loop

1. Difficult-DOM fixtures and evidence-based accessibility checks. An inaccessible control requires specific evidence; automation failure alone is insufficient.
2. Lightweight observed header/cookie findings, followed by optional ZAP passive analysis. ZAP's passive scanner analyzes messages without changing them; ordinary browser actions may still change application state. Integrate the proxy during traffic collection if using this workflow. [ZAP passive scanner](https://www.zaproxy.org/docs/desktop/addons/passive-scanner/).
3. Two-account authorization tests using owned staging fixtures and an explicit access policy; an HTTP 200 alone does not establish unauthorized data exposure. Include both permitted-owner and denied-other-user controls before a security repair.
4. Performance checks using repeated navigation/API measurements and project-defined budgets. Keep server/model contention separate from the target application's measured response behavior.
5. Scheduled QA, duplicate finding suppression, retesting, and incident resolution.
6. More repair classes and optional MCP adapters over the existing tool interface.

Keep target page text, repository comments, and logs as untrusted data. They cannot change run permissions, direct secret disclosure, or override the user's goal. Test this boundary alongside timeout handling, stale elements, source mismatch, fixture cleanup, duplicate webhooks, and PR publication gates.

## Decisions needed before scheduling implementation

- Deadline and team size.
- Demo application and first supported bug class.
- Exact CPU and whether 8 GB applies to each worker or the entire deployed system.
- Availability of test credentials, reset fixtures, GitHub repository, CI, and preview hosting.
- Whether test/build work must remain on the same server.
- Whether security testing is required by the course or can follow the main repair demonstration.


## Implementation update — 13 October 2026

The first application is now implemented in this workspace: Laravel/Livewire operator UI, project setup/editing, private browser worker, bounded CPU-model orchestration, exact deployed-source binding, patch policy, isolated verification runner and GitHub App publication. The operator supplies repository + website URLs and authorizes the Verified draft PR policy. Localhost is supported through exact server-approved origins; a cloud worker cannot reach laptop localhost without a tunnel. GitHub App installation supplies a built-in bot identity, so no additional committing account is required.

Before a real demonstration, configure the GitHub App installation/private key, start a local model, and prepare a trusted per-repository Docker runner with disposable fixtures. The app preserves evidence and proposals while those requirements are missing. Publication needs explicit passing test and browser gates tied to the same source SHA and patch digest. The README and docs/GITHUB_SETUP.md, docs/BROWSER_WORKER.md, docs/REPAIR_RUNNER.md provide the setup.
