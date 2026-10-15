"""One-time documentation/reference repair following the recorded native move."""
import os
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[4]
DATA = ROOT / 'ai_service/datasets/emergency_text'
MOVES = [(DATA / old, DATA / new) for old, new in (
    ('synthetic_9000words_20260920', 'experiments/synthetic_9000words_20260920'),
    ('by_severity', 'archive/initial_80/by_severity'),
    ('draft_reports.csv', 'archive/initial_80/draft_reports.csv'),
    ('reviewed_reports.csv', 'archive/initial_80/reviewed_reports.csv'))]

def relocated(path, pairs=MOVES):
    for old, new in pairs:
        if path == old or path.is_relative_to(old):
            return new / path.relative_to(old)
    return path

files = subprocess.check_output(['rg', '--files', 'ai_service', 'tool', '-g', '*.md', '-g', '*.py'], cwd=ROOT, text=True).splitlines()
files += ['DEPLOYMENT_RENDER_RAILWAY.md']
changed = []
for relative in files:
    path = ROOT / relative
    if path == Path(__file__).resolve():
        continue
    text = path.read_text(encoding='utf-8-sig')
    original = text
    if path.suffix == '.md':
        former = relocated(path, [(new, old) for old, new in MOVES])
        def fix_link(match):
            target = match[2]
            if re.match(r'^[a-z]+:', target, re.I) or target.startswith(('#', '/')):
                return match[0]
            url, separator, anchor = target.partition('#')
            resolved = relocated((former.parent / url).resolve())
            return '[' + match[1] + '](' + Path(os.path.relpath(resolved, path.parent)).as_posix() + (separator + anchor if separator else '') + ')'
        text = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', fix_link, text)
    for old, new in MOVES:
        old_rel = old.relative_to(ROOT / 'ai_service').as_posix()
        new_rel = new.relative_to(ROOT / 'ai_service').as_posix()
        # Explicit executable paths; skip already relocated paths.
        text = text.replace(old_rel, new_rel)
    if text != original:
        path.write_text(text, encoding='utf-8')
        changed.append(relative)
print('\n'.join(changed))
