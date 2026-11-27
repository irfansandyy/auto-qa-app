import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


class DeployTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.checkout = Path(temporary.name)
        self.commands = self.checkout / "commands"
        self.commands.mkdir()
        self.calls = self.checkout / "calls"
        self.env_path = self.checkout / ".env"
        self.original = b"APP_KEY=existing-local-value\nCACHE_STORE=redis\n"
        self.env_path.write_bytes(self.original)
        self.env_path.chmod(0o600)
        (self.checkout / ".gitignore").write_text(".env\n")
        (self.checkout / ".env.production.example").write_text(
            "APP_KEY=\nCACHE_STORE=database\nNEW_SETTING=default\n"
        )
        (self.checkout / "deploy").mkdir()
        for script in ("deploy.sh", "sync-env.py"):
            shutil.copy2(Path(__file__).with_name(script), self.checkout / "deploy")

        self.git("init", "--quiet", "--initial-branch=main")
        self.git("add", ".gitignore", ".env.production.example", "deploy")
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test",
                 "-c", "commit.gpgsign=false", "commit", "--quiet", "-m", "fixture")
        self.sha = self.git("rev-parse", "HEAD").stdout.strip()
        self.install_fake("docker", """
printf 'docker %s\\n' "$*" >> "$DEPLOY_TEST_CALLS"
if [[ "${4:-}" == build && "${FAIL_BUILD:-0}" == 1 ]]; then exit 9; fi
""")
        self.install_fake("curl", """
printf 'curl %s\\n' "$*" >> "$DEPLOY_TEST_CALLS"
printf '%s' "${HEALTH_STATUS:-200}"
""")

    def prepare_runner(self):
        remote = self.checkout / "upstream.git"
        self.git("init", "--quiet", "--bare", "--initial-branch=main", str(remote))
        self.git("remote", "add", "origin", str(remote))
        self.git("push", "--quiet", "origin", "main")
        self.install_fake("git", """
if [[ "$*" == 'remote get-url origin' ]]; then
    printf '%s\\n' 'https://github.com/example/auto-qa.git'
else
    exec "$DEPLOY_TEST_GIT" "$@"
fi
""")
        launcher = Path(__file__).with_name("azure-run-command.sh").read_text()
        return launcher.split("<<'CHECKOUT'\n", 1)[1].rsplit("\nCHECKOUT", 1)[0]

    def run_checkout(self, script, **overrides):
        return subprocess.run(
            ["/bin/bash", "-s", "--", str(self.checkout), "example/auto-qa", self.sha,
             "https://example.test/up", "127.0.0.1"],
            input=script, cwd=self.checkout, text=True, capture_output=True, timeout=10,
            env={**os.environ, "PATH": f"{self.commands}:{os.environ['PATH']}",
                 "DEPLOY_TEST_GIT": shutil.which("git"), "DEPLOY_TEST_CALLS": str(self.calls), **overrides},
        )

    def install_fake(self, name, body):
        command = self.commands / name
        command.write_text("#!/bin/bash\nset -euo pipefail\n" + body)
        command.chmod(0o700)

    def git(self, *args):
        return subprocess.run(
            ["git", *args], cwd=self.checkout, check=True, capture_output=True, text=True
        )

    def deploy(self, sha=None, **overrides):
        return subprocess.run(
            ["/bin/bash", "deploy/deploy.sh", sha or self.sha, "https://example.test/up", "127.0.0.1"],
            cwd=self.checkout, text=True, capture_output=True, timeout=10,
            env={**os.environ, "PATH": f"{self.commands}:{os.environ['PATH']}",
                 "DEPLOY_TEST_CALLS": str(self.calls), **overrides},
        )

    def test_uses_the_existing_private_env_and_waits_for_compose_and_https(self):
        result = self.deploy()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.env_path.read_bytes(), self.original + b"NEW_SETTING=default\n")
        self.assertEqual(self.env_path.stat().st_mode & 0o777, 0o600)
        calls = self.calls.read_text().splitlines()
        docker = f"docker compose --env-file {self.env_path}"
        self.assertEqual(calls[:3], [
            f"{docker} config --quiet",
            f"{docker} build app browser nginx",
            f"{docker} up -d --no-build --wait --wait-timeout 600",
        ])
        self.assertIn("--resolve example.test:443:127.0.0.1 https://example.test/up", calls[3])
        self.assertNotIn("existing-local-value", result.stdout + result.stderr)

    def test_failed_image_build_does_not_restart_the_stack(self):
        result = self.deploy(FAIL_BUILD="1")

        self.assertNotEqual(result.returncode, 0)
        calls = self.calls.read_text()
        self.assertIn(" build app browser nginx", calls)
        self.assertNotIn(" up ", calls)
        self.assertNotIn("curl ", calls)

    def test_a_nonhealthy_https_response_fails_the_release(self):
        result = self.deploy(HEALTH_STATUS="302")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("did not return HTTP 200", result.stderr)

    def test_a_mismatched_checkout_fails_before_env_sync_or_docker(self):
        result = self.deploy(sha="0" * 40)

        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.calls.exists())
        self.assertEqual(self.env_path.read_bytes(), self.original)

    def test_runner_keeps_a_failed_build_private_and_records_only_a_healthy_release(self):
        script = self.prepare_runner()
        failed = self.run_checkout(script, FAIL_BUILD="1")
        marker = self.checkout / ".git" / "autoqa-deployed"
        self.assertNotEqual(failed.returncode, 0)
        self.assertEqual(failed.stdout, f"AUTOQA_DEPLOY_FAILED {self.sha} compose\n")
        self.assertFalse(marker.exists())

        healthy = self.run_checkout(script)

        self.assertEqual(healthy.returncode, 0, healthy.stderr)
        self.assertEqual(healthy.stdout, f"AUTOQA_DEPLOY_OK {self.sha}\n")
        self.assertEqual(marker.read_text().strip(), self.sha)

    def test_runner_skips_an_approved_revision_superseded_on_main(self):
        script = self.prepare_runner()
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test",
                 "-c", "commit.gpgsign=false", "commit", "--allow-empty", "--quiet", "-m", "later commit")
        self.git("push", "--quiet", "origin", "main")

        result = self.run_checkout(script)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, f"AUTOQA_DEPLOY_SKIPPED {self.sha}\n")
        self.assertFalse(self.calls.exists())
        self.assertEqual(self.env_path.read_bytes(), self.original)


if __name__ == "__main__":
    unittest.main()
