# Autonomous Web QA & Repair Agent — product plan

Draft for discussion, 13 October 2026.

Based on the [shared project conversation](https://chatgpt.com/share/6acd7d7f-b8f8-83ec-9188-238ef5d5ec77) and the user's follow-up: prioritize finding a bug, repairing it, retesting it, and opening a draft pull request; maximum RAM is 8 GB.

## Purpose and constraints

Build a web application for a Framework Based Programming university course. A user supplies a staging website, its connected repository, and a testing goal. The application operates the website, records reproducible failures, attempts a limited source-code repair, validates the result, and opens a draft PR for human review.

Confirmed requirements:

- Laravel and Livewire.
- CPU-only model inference.
- An 8 GB RAM ceiling.
- Testing and repair execute on the server. The user's browser displays results and controls runs; the application has no access to the user's desktop or filesystem.
- The principal demonstration completes the entire bug-to-draft-PR loop.

Working assumptions to discuss:

- Approximately 2 vCPU, as proposed in the original conversation; exact CPU is unconfirmed.
- Begin with an owned Laravel staging application and one matching GitHub repository.
- Dedicated test accounts and disposable data are available.
- Deadline, team size, deployment budget, and whether separate CI resources are permitted remain unknown.

## First version

The first supported journey is registration followed by login. The user states the expected behavior, for example: "Phone is optional; a visitor can register without it and then log in."

The agent discovers the controls from the current page, chooses test cases, executes them, and adapts when the page changes. Test templates can supply empty, invalid, and valid input categories; the actual route and element choices come from observation.

The repair demonstration uses an optional phone field that causes a server error. This is a proposed first case, not a final decision. Its expected behavior is explicit, it has a clear before/after result, and it exercises both browser interaction and backend repair.

| Capability | First version | Later extension |
|---|---|---|
| Functional QA | Guided registration/login goal, bounded exploration | More journeys and a broader page graph |
| Evidence | Steps, assertions, screenshots, relevant HTTP/console events | Richer reports and trace comparisons |
| Source diagnosis | Exact deployed commit, route/file narrowing | More framework and repository support |
| Repair | One narrow Laravel bug class, patch and regression test | Additional validation and markup fixes |
| Validation | Failing baseline, passing patched test, browser replay | Larger compatibility and performance checks |
| GitHub | Connected repository, fix branch, verified draft PR | Issue synchronization and more integrations |
| Difficult DOM | Semantic interaction plus limited DOM/keyboard fallback | More custom widget adapters |
| Performance | Basic timing evidence attached to runs | Repeated measurements against defined budgets |
| Security | Reserved extension point | Passive findings, then explicit two-account authorization tests |
| Scheduling | Manual run and retest | Scheduled QA and incident tracking |

General workflow builders, vision navigation, broad active security scans, arbitrary-codebase repair, and automatic merging are deferred.

## User experience

1. **Project setup:** staging URL, allowed origins, repository, deployed commit, test accounts, expected behavior, and repair policy.
2. **Run setup:** testing goal and limits. Start a QA run with either Suggest Only or Verified Draft PR selected.
3. **Run view:** current step, completed actions, screenshot preview, findings, and Stop. Show short action summaries and observations.
4. **Finding view:** expected versus observed behavior, reproduction steps, evidence, affected journey, and reproducibility status.
5. **Repair view:** candidate source files, proposed diff, tests, preview replay, remaining uncertainty, and draft PR link.

The core loop is:

The agent inspects the website, reproduces failures, diagnoses the source, proposes a patch, runs tests, replays the browser scenario, and opens a draft PR.

Verified Draft PR authorizes the app's bounded repair workflow when selected. Suggest Only ends at a proposed diff. A human reviews and merges any PR.

## What counts as success

For the first demo, use a student registration portal with a known seeded bug and a clean control version. Maintain known bug locations separately from the agent's input.

- Discover and reproduce the seeded failure using the testing goal, without supplying its source location.
- Save evidence sufficient for another person to reproduce it.
- Identify the matching source version and relevant code.
- Generate a patch and a regression test.
- Show that the new test fails on the original version and passes with the patch.
- Run the existing relevant tests and replay the original browser scenario against the patched commit.
- Verify a normal registration/login scenario still works.
- Open one draft PR containing the diagnosis, diff, verification results, and evidence references.

Proposed evaluation: repeat the full demonstration five times with reset fixtures, publish the actual completion count and runtime, and run the clean control to check false positives. This is an evaluation target, not a performance claim.

Record tested, failed, blocked, and not-tested cases separately. "The agent could not locate this control" does not itself prove an accessibility defect. Accessibility and security findings require their own evidence. Passing recorded checks establishes only the tested behavior.

## Brainstorming decisions

| Decision | Proposed default | Alternative worth discussing |
|---|---|---|
| Demo application | Student registration portal | A course submission portal or a small store |
| First repaired bug | Optional phone causes a server error | Missing validation or a broken route |
| First supported source stack | Laravel/Blade/Livewire | Browser QA for any stack, repair only for Laravel |
| Repair policy | Verified Draft PR for a configured test repository | Suggest Only during development |
| After the first working loop | Difficult DOM plus accessibility evidence | Two-account access control demonstration |
| Workflow engine scope | Fixed QA/repair stages with agent decisions inside them | A general workflow builder later |

The immediate discussion should choose the demonstration app and first bug, then establish the deadline and team size. Those choices determine how much of the extension list belongs in the course submission.


## Implementation update — 13 October 2026

The first application is now implemented in this workspace: Laravel/Livewire operator UI, project setup/editing, private browser worker, bounded CPU-model orchestration, exact deployed-source binding, patch policy, isolated verification runner and GitHub App publication. The operator supplies repository + website URLs and authorizes the Verified draft PR policy. Localhost is supported through exact server-approved origins; a cloud worker cannot reach laptop localhost without a tunnel. GitHub App installation supplies a built-in bot identity, so no additional committing account is required.

Before a real demonstration, configure the GitHub App installation/private key, start a local model, and prepare a trusted per-repository Docker runner with disposable fixtures. The app preserves evidence and proposals while those requirements are missing. Publication needs explicit passing test and browser gates tied to the same source SHA and patch digest. The README and docs/GITHUB_SETUP.md, docs/BROWSER_WORKER.md, docs/REPAIR_RUNNER.md provide the setup.
