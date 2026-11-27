import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


class SyncEnvTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.directory = Path(directory.name)
        self.template = self.directory / ".env.production.example"
        self.destination = self.directory / ".env"

    def command(self, template=None):
        script = Path(__file__).with_name("sync-env.py")
        return [sys.executable, script, template or self.template, self.destination]

    def run_sync(self):
        return subprocess.run(self.command(), capture_output=True, timeout=5)

    def test_appends_only_missing_declarations_and_preserves_existing_file(self):
        original = (
            b"# Example private settings\r\n"
            b'export APP_KEY = "synthetic-unchanged" # preserved\r\n'
            b"SMTP_PASSWORD=\r\n"
            b"UNSET_SETTING\r\n"
            b"CERT_DATA: 'first\r\n"
            b"ADDED_SETTING=inside certificate\r\n"
            b"last' # fixture quote\r\n"
            b"OLD_SETTING=keep"
        )
        self.destination.write_bytes(original)
        self.destination.chmod(0o640)
        owner = self.destination.stat()
        self.template.write_bytes(
            b"APP_KEY=supplied-outside-git\nSMTP_PASSWORD=\nUNSET_SETTING=default\n"
            b"# New example settings\nADDED_SETTING='test value' # keep formatting\n"
            b"MULTILINE='first\nSAME_AS_KEY=literal\nlast'\n"
            b"SAME_AS_KEY=\n"
            b'JSON_SETTING="{\\"name\\":\\"sample\\"}"\n'
        )

        result = self.run_sync()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b"Added 4 missing dotenv setting(s).", result.stdout)
        self.assertNotIn(b"synthetic-unchanged", result.stdout + result.stderr)
        self.assertEqual(self.destination.read_bytes(), original + b"\r\n"
                         b"ADDED_SETTING='test value' # keep formatting\n"
                         b"MULTILINE='first\nSAME_AS_KEY=literal\nlast'\n"
                         b"SAME_AS_KEY=\n"
                         b'JSON_SETTING="{\\"name\\":\\"sample\\"}"\n')
        current = self.destination.stat()
        self.assertEqual((current.st_uid, current.st_gid), (owner.st_uid, owner.st_gid))
        self.assertEqual(stat.S_IMODE(current.st_mode), 0o640)

    def test_a_second_sync_leaves_the_existing_inode_and_duplicate_declarations_in_place(self):
        original = b"SETTING=first\nSETTING=second\n"
        self.destination.write_bytes(original)
        self.template.write_bytes(b"SETTING=replacement\n")
        before = self.destination.stat()

        result = self.run_sync()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b"Added 0 missing dotenv setting(s).", result.stdout)
        self.assertIn(b"Preserved duplicate declaration at line 2", result.stderr)
        after = self.destination.stat()
        self.assertEqual((after.st_ino, after.st_mtime_ns), (before.st_ino, before.st_mtime_ns))
        self.assertEqual(self.destination.read_bytes(), original)

    def test_duplicate_template_keys_fail_without_touching_or_exposing_existing_data(self):
        original = b"KEY=synthetic-existing-secret\n"
        self.destination.write_bytes(original)
        self.template.write_bytes(b"NEW_KEY=first\nNEW_KEY=synthetic-template-secret\n")

        result = self.run_sync()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b"Duplicate declaration at line 2", result.stderr)
        self.assertNotIn(b"synthetic-", result.stdout + result.stderr)
        self.assertEqual(self.destination.read_bytes(), original)

    def test_unclosed_production_quotes_fail_before_a_partial_append(self):
        original = b'KEY="synthetic-secret\nAPP_DEBUG=false\n'
        self.destination.write_bytes(original)
        self.template.write_bytes(b"NEW_KEY=default\n")

        result = self.run_sync()

        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b"Unclosed quoted value at line 1", result.stderr)
        self.assertNotIn(b"synthetic-secret", result.stdout + result.stderr)
        self.assertEqual(self.destination.read_bytes(), original)

    def test_missing_or_symlinked_production_file_does_not_get_replaced(self):
        self.template.write_bytes(b"NEW_KEY=default\n")

        self.assertNotEqual(self.run_sync().returncode, 0)
        self.assertFalse(self.destination.exists())

        real_env = self.directory / "outside-dotenv"
        real_env.write_bytes(b"OLD_KEY=existing\n")
        self.destination.symlink_to(real_env)

        self.assertNotEqual(self.run_sync().returncode, 0)
        self.assertTrue(self.destination.is_symlink())
        self.assertEqual(real_env.read_bytes(), b"OLD_KEY=existing\n")

    def test_concurrent_invocations_both_keep_their_appended_values(self):
        self.destination.write_bytes(b"FIRST=keep\n")
        self.template.write_bytes(b"SECOND=add\n")
        another_template = self.directory / "other.env.example"
        another_template.write_bytes(b"THIRD=add\n")

        with subprocess.Popen(self.command(), stdout=subprocess.PIPE, stderr=subprocess.PIPE) as first:
            with subprocess.Popen(self.command(another_template), stdout=subprocess.PIPE, stderr=subprocess.PIPE) as second:
                processes = [(process, process.communicate(timeout=5)) for process in (first, second)]

        for process, (stdout, stderr) in processes:
            self.assertEqual(process.returncode, 0, stderr)
            self.assertIn(b"Added 1 missing dotenv setting(s).", stdout)
        first, *added = self.destination.read_bytes().splitlines()
        self.assertEqual(first, b"FIRST=keep")
        self.assertCountEqual(added, [b"SECOND=add", b"THIRD=add"])
