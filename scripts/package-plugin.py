#!/usr/bin/env -S uv run --system-certs
"""Build a deterministic WordPress upload ZIP without tests or local secrets."""
from hashlib import sha256
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'wordpress-plugin' / 'ai-site-manager'
OUTPUT = ROOT / 'releases' / 'ai-site-manager.zip'
FILES = [
    'LICENSE', 'README.md', 'readme.txt', 'ai-site-manager.php', 'uninstall.php',
    'docs/AI-GUIDE.md', 'examples/client.py',
    'includes/class-admin.php', 'includes/class-api.php', 'includes/class-migration.php',
    'includes/class-plugins.php', 'includes/class-themes.php', 'includes/class-store.php', 'includes/class-updater.php',
    'includes/class-users.php',
    'includes/class-database.php',
    'includes/class-diagnostics.php',
]


def main():
    # Every runtime class must ship; a missing include would be a fatal error after updating.
    missing = sorted(str(p.relative_to(SOURCE)) for p in (SOURCE / 'includes').glob('*.php') if str(p.relative_to(SOURCE)) not in FILES)
    assert not missing, f'Add these files to FILES: {missing}'
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(OUTPUT, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for relative in sorted(FILES):
            entry = ZipInfo('ai-site-manager/' + relative, (2026, 10, 23, 0, 0, 0))
            entry.compress_type = ZIP_DEFLATED
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, (SOURCE / relative).read_bytes(), compresslevel=9)
    with ZipFile(OUTPUT) as archive:
        assert archive.testzip() is None
        assert len(archive.namelist()) == len(FILES)
        for relative in FILES:
            assert archive.read('ai-site-manager/' + relative) == (SOURCE / relative).read_bytes()
    print(f'{OUTPUT.relative_to(ROOT)} ({OUTPUT.stat().st_size} bytes)')
    print('SHA256 ' + sha256(OUTPUT.read_bytes()).hexdigest())


if __name__ == '__main__':
    main()
