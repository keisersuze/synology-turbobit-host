import importlib.util
from pathlib import Path
import tempfile
import unittest

root = Path(__file__).resolve().parents[1]

def load(name):
    spec = importlib.util.spec_from_file_location(name, root / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class ToolsTests(unittest.TestCase):
    def test_shareable_export(self):
        export = load('export_diagnostics')
        prefix = '2026-09-26T12:00:00+00:00 [123456abcdef] '
        lines = [prefix+'INPUT URL: https://private.invalid/token\n',
                 prefix+'ERROR: account-private\n',
                 prefix+'RESULT: FAILED: RATE_LIMITED\n',
                 'old raw legacy cookie=private\n', prefix+'COOKIES: private\n']
        text = '\n'.join(export.sanitize(lines))
        self.assertNotIn('private',text)
        self.assertNotIn('legacy',text)
        self.assertIn('RATE_LIMITED',text)
        self.assertEqual(len(export.sanitize(lines)),4)

    def test_cleanup_inventory_does_not_follow_links(self):
        cleanup = load('cleanup_sessions')
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            valid = base/'turbobit_session_valid'; valid.mkdir(mode=0o700)
            (valid/'cookies.txt').write_text('synthetic')
            (base/'turbobit_session_link').symlink_to(valid, target_is_directory=True)
            other=base/'turbobit_session_other';other.mkdir(mode=0o700)
            (other/'cookies.txt').symlink_to(valid/'cookies.txt')
            self.assertEqual([d for d,c,t in cleanup.candidates(base)],[valid])
            self.assertTrue((valid/'cookies.txt').exists())

if __name__=='__main__':
    unittest.main()
