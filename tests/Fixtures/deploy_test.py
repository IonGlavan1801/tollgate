"""Exercise deployment failure handling without Docker or a VPS."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class DeployFailureTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "scripts").mkdir()
        shutil.copyfile(Path(__file__).resolve().parents[2] / "scripts/deploy.sh", self.root / "scripts/deploy.sh")
        (self.root / ".env").write_text("fixture=true\n")
        source = self.root / "source"
        source.mkdir()
        for name in ("compose.yaml", "compose.vps.yaml"):
            (source / name).touch()
        binary = self.root / "bin"
        binary.mkdir()
        for name, code in {
            "flock": "exit 0",
            "git": '''case "$1" in
  status|fetch) exit 0;;
  rev-parse) printf '%040d\\n' 1;;
  archive) tar -cf - -C "$DEPLOY_FIXTURE/source" compose.yaml compose.vps.yaml;;
esac''',
            "docker": '''printf '%s %s\\n' "$RELEASE_TAG" "$*" >> "$DEPLOY_FIXTURE/calls"
case "$*" in *' build '*) exit 17;; esac''',
        }.items():
            path = binary / name
            path.write_text("#!/bin/sh\n" + code + "\n")
            path.chmod(0o700)
        self.env = {**os.environ, "PATH": str(binary) + os.pathsep + os.environ["PATH"], "DEPLOY_FIXTURE": str(self.root)}

    def run_deploy(self):
        return subprocess.run(["bash", str(self.root / "scripts/deploy.sh"), f"{1:040d}"], capture_output=True, text=True, env=self.env)

    def test_failure_inside_compose_function_restores_previous_images(self):
        previous = f"{2:040d}"
        (self.root / f".local/releases/{previous}").mkdir(parents=True)
        (self.root / ".local/current-release").write_text(previous)
        result = self.run_deploy()
        self.assertEqual(result.returncode, 17, result.stderr)
        calls = (self.root / "calls").read_text()
        self.assertIn(previous + " compose", calls)
        self.assertIn("up -d --no-build --wait app worker scheduler web", calls)
        self.assertEqual((self.root / ".local/current-release").read_text(), previous)

    def test_first_release_failure_stops_public_services(self):
        result = self.run_deploy()
        self.assertEqual(result.returncode, 17, result.stderr)
        self.assertIn("stop web app worker scheduler", (self.root / "calls").read_text())
        self.assertFalse((self.root / ".local/current-release").exists())


if __name__ == "__main__":
    unittest.main()
