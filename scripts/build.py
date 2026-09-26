"""Build a reproducible .host containing only INFO and TurboBitOrg.php."""
import gzip
import hashlib
import io
import json
import tarfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
version = json.loads((root / "src/INFO").read_text())["version"]
if not version or any(c not in "0123456789." for c in version):
    raise ValueError("Unexpected version")
dist = root / "dist"
dist.mkdir(exist_ok=True)
archive = dist / ("TurboBitOrg(" + version + ").host")
with archive.open("wb") as raw:
    with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0) as gz:
        with tarfile.open(fileobj=gz, mode="w", format=tarfile.USTAR_FORMAT) as tar:
            for name in ("INFO", "TurboBitOrg.php"):
                data = (root / "src" / name).read_bytes()
                info = tarfile.TarInfo(name)
                info.size = len(data)
                info.mode = 0o644
                info.uid = info.gid = 0
                info.uname = info.gname = "root"
                info.mtime = 0
                tar.addfile(info, io.BytesIO(data))
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.parent / (archive.name + ".sha256")).write_text(digest + "  " + archive.name + "\n")
print(archive)
print("SHA256: " + digest)
