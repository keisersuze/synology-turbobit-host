"""Inventory handoff cookies. Delete only after the operator stops all tasks.

This module has no Download Station completion callback: age alone cannot prove
that a cookie is unused. Run locally on the NAS; never run on active downloads.
"""
import argparse
from pathlib import Path
import stat


def candidates(root):
    for directory in root.glob('turbobit_session_*'):
        try:
            st = directory.lstat()
            if not stat.S_ISDIR(st.st_mode) or st.st_mode & 0o077:
                continue
            children = list(directory.iterdir())
            if len(children) != 1 or children[0].name != 'cookies.txt':
                continue
            cookie = children[0]
            cs = cookie.lstat()
            if not stat.S_ISREG(cs.st_mode) or cs.st_nlink != 1 or cs.st_uid != st.st_uid:
                continue
            yield directory, cookie, cs.st_mtime
        except (FileNotFoundError, PermissionError):
            continue


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--delete', action='store_true')
    parser.add_argument('--confirm-no-active-downloads', action='store_true', help='Confirm no running, paused or queued task needs these cookies')
    args = parser.parse_args()
    if args.delete and not args.confirm_no_active_downloads:
        parser.error('--delete requires --confirm-no-active-downloads')
    entries = list(candidates(Path('/tmp')))
    print('Candidate sessions:', len(entries))
    print('Run this on the NAS with permission to inspect the download user files.')
    if not args.delete:
        print('Inventory only. No cookies read or removed; active use cannot be inferred from age.')
        return
    removed = 0
    for directory, cookie, mtime in entries:
        # Revalidate to avoid blindly traversing unexpected files or links.
        if not any(d == directory for d, c, t in candidates(Path('/tmp'))):
            continue
        cookie.unlink()
        directory.rmdir()
        removed += 1
    print('Removed sessions:', removed)


if __name__ == '__main__':
    main()
