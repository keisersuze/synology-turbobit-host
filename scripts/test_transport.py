"""Run transport tests against a loopback-only fixture server."""
import subprocess
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
server = subprocess.Popen([sys.executable, str(root / "tests/http-fixture.py")], stdout=subprocess.PIPE, text=True)
try:
    port = server.stdout.readline().strip()
    if not port.isdigit():
        raise RuntimeError("Fixture server failed to start")
    result = subprocess.run(["php", str(root / "tests/transport-test.php"), port], timeout=60)
    sys.exit(result.returncode)
finally:
    server.terminate()
    try:
        server.wait(timeout=5)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait()
