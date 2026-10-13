# Prepare the isolated repair runner

The runner executes a target application's source and generated tests inside fresh Docker containers. The Laravel orchestrator downloads an exact GitHub commit, saves a bounded patch payload, and launches fixed commands. Target PHP is never loaded or run on the host.

The first supported target is an owned Laravel application with a committed `composer.lock`, PHPUnit feature tests, SQLite-compatible migrations, and a deterministic `DatabaseSeeder`. Existing tests must pass on the base source. Configure this for a disposable demonstration app before testing a real repair.

## Build a target-specific image

Review the target's dependency manifest and lockfile. Create a private preparation directory containing only:

```text
target/composer.json
target/composer.lock
target/source.sha
target/public/build/
sandbox/runner.php
sandbox/preview-router.php
sandbox/http-client.php
sandbox/Dockerfile.example
```

Copy the target manifests and this repository's trusted sandbox files into that directory. Put the exact deployed commit's lowercase 40-character SHA in `target/source.sha`. Prepare the target's frontend assets from that same commit in a reviewed, isolated build environment, then copy its complete `public/build` output, including the Vite manifest, into `target/public/build/`. A plain Blade application without a frontend build still needs an empty directory there. The orchestrator never executes npm scripts or frontend builds.

From the preparation directory, an administrator can build the image:

```sh
docker build --file sandbox/Dockerfile.example --tag autoqa-target:demo .
docker image inspect --format '{{.Id}}' autoqa-target:demo
```

This preparation downloads dependencies inside the image build. Composer scripts and plugins are disabled. The image includes development dependencies, including PHPUnit; the verification job never installs packages or contacts a registry. Pin approved PHP and Composer image digests for repeatable deployment. The example contains the extensions needed for a small Laravel/SQLite target, including SimpleXML for test reports; add reviewed target-specific extensions during image preparation.

The recipe creates empty standard Laravel autoload directories before generating the dependency autoloader. It disables authoritative classmaps and root autoload optimization in the image manifest so that newly copied application classes resolve from `/workspace` at runtime; the reviewed lockfile is unchanged. For a custom root classmap directory, add its empty directory to the build recipe. Applications that require a plugin, installation script, or an authoritative root classmap need a separately reviewed preparation recipe.

Set the server configuration:

```dotenv
AUTOQA_RUNNER_IMAGE=autoqa-target:demo
AUTOQA_RUNNER_TIMEOUT=240
AUTOQA_PREVIEW_ORIGIN=http://127.0.0.1:8081
# Include this origin alongside other explicitly permitted local targets.
AUTOQA_LOCAL_TARGETS=http://127.0.0.1:8081
```

The Docker daemon must be on the same host as Laravel, and its operator must permit the orchestrator to launch the configured containers. The daemon/socket is never mounted into target containers. The image is inspected once and its immutable local image ID is used for that verification. A configured image makes the runner eligible to try; it does not prove Docker availability or successful execution.

## Execution boundaries

Containers run with a read-only root filesystem, no external network, a 2 GB memory limit, one CPU, 128 processes, no capabilities, no privilege escalation, and UID/GID 1000. Source and patch inputs are read-only file mounts; workspace and temporary data live in bounded tmpfs mounts. Containers receive no GitHub key, installation token, production environment file, orchestrator mount, or Docker socket. [Docker runtime controls](https://docs.docker.com/engine/containers/run/).

Before extraction, the runner checks that the image's commit marker exactly matches the deployed source SHA. Rebuild the prepared image whenever that commit changes. Archive extraction happens only inside the container. It rejects traversal, mixed roots, duplicate paths, links, special files, and oversized archives; it excludes environment files, secret paths, stored data, cached Laravel configuration, archived dependencies, and archived `public/build`. Dependency files come from the trusted image and must match the snapshot's `composer.lock`. A maximum of 4,000 archive entries, 128 MB of source, 256 MB of dependencies, and five patch files/64 KB of patch content is supported.

The image's frontend bundle is authoritative and copied into `public/build` after source extraction. Archived `public/hot` markers are ignored so Vite uses the prepared production bundle. It permits at most 1,000 regular files, 2,000 total entries, and 32 MB. Links, special files, environment/secret paths, PHP files, and unsupported extensions are rejected. Supported assets are CSS, JavaScript, JSON/manifests, source maps, common images, fonts, WebAssembly, and text. This recipe verifies PHP and Blade repairs using the existing built frontend; JavaScript/CSS repairs requiring a fresh frontend build need a separately designed preparation flow.

The runner uses a fixed PHPUnit configuration, disposable SQLite, and temporary Laravel storage. It permits one new feature-test file and changes to existing application files in the supported repair paths. A malformed test, bootstrap failure, runtime exception, or skipped-only suite cannot establish the expected baseline failure.

## Required gates

1. Existing tests pass on original source.
2. The new regression test fails on original source with a PHPUnit assertion failure and no test errors.
3. The regression test passes with the patch.
4. The full relevant suite passes with the patch.
5. The original browser scenario passes against patched source.
6. An independent normal control scenario passes against fresh patched fixtures.

Every test phase gets a fresh container. Original replay and control replay each get a separately migrated and seeded database. Both scenarios must end in an explicit text or URL assertion; the normal control must interact with the journey. Saved element IDs are discarded. Controls are rebound uniquely using their recorded tag, role, label, text, and type.

The verification report binds results to the deployed source SHA and exact patch-content hash. Laravel checks all six explicit gates before publishing a branch or draft PR. The sandbox API itself has no commit or PR method.

## Browser preview without container network access

The target's preview server listens only on loopback inside its container. A trusted host proxy binds only `127.0.0.1` at the configured preview port and uses `docker exec` with fixed arguments to forward HTTP into that container. The browser worker visits the allowed local preview origin. The proxy never loads target source and never forwards Docker or GitHub credentials.

Requests are limited to 64 KB, headers to 16 KB, responses to 2 MB, and transport requests to 15 seconds. Each browser replay has a 180-second budget, checked between browser operations. This prototype supports ordinary HTTP pages and form requests; streaming responses, WebSockets, large uploads, external APIs, and production databases are outside this recipe. Applications requiring remote services need a separately designed fixture strategy before their repairs can be verified.

Only one sandbox can use the preview at a time. Queue timeout must cover four bounded test stages and two browser scenarios; use a dedicated, serialized worker with a generous job timeout. Cancellation is checked between stages. Finally blocks stop the proxy, remove this run's containers, delete its downloaded source/payload, and release its lock. An administrator should also clean up abandoned `autoqa-*` containers after a killed worker or host failure.

Docker access is powerful: restrict the orchestrator deployment and use an administrator-owned image. These containers reduce the exposure of the host; they are not a proof of safety against hostile code exploiting a container or kernel vulnerability. This first recipe assumes an owned demonstration repository.

## Validation status

Automated tests fake process execution and GitHub/browser responses to verify gate handling, isolation arguments, cleanup, and failure behavior. A real Docker daemon, target image, repository access, model, and browser worker are required for the complete repair demonstration. Passing mocked tests does not establish that a real container or browser repair has succeeded.
