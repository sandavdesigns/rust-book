#!/usr/bin/env python3
"""Apply the Windows product-identity part of the RustDeskLab test build."""

from __future__ import print_function

import argparse
import hashlib
import pathlib
import subprocess
import sys


UPSTREAM_COMMIT = '23e5bb471a040d62f0a6ad52591fb30784265b73'
EXPECTED_SHA256 = {
    'flutter/windows/CMakeLists.txt': '601b3670032926d1b1a627d882e872574825896e5a9f2feb899b9f30f8cfcae7',
    'flutter/windows/runner/Runner.rc': 'ed09220ae023cbadb84df303a22deb48f9c7bf33501676551f5c1bdb66da754d',
    'src/platform/windows.rs': 'c2d4e6f28971d7b53351db9f85650e843242a23b2f9374d46448ee6a8e290cf7',
    'src/privacy_mode/win_topmost_window.rs': '495325a038995bc58ca1042e1f16ba67ebff92affbd0eea61b31bc1a72a15fdf',
}


def fail(message):
    raise RuntimeError(message)


def command(root, *args):
    return subprocess.check_output(args, cwd=str(root)).decode('utf-8').strip()


def sha256(path):
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def replace_once(path, old, new):
    content = path.read_text(encoding='utf-8')
    occurrences = content.count(old)
    if occurrences != 1:
        fail('{} expected one occurrence of {!r}, found {}'.format(path, old, occurrences))
    path.write_text(content.replace(old, new, 1), encoding='utf-8')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True, help='Disposable prepared RustDesk checkout')
    args = parser.parse_args()

    root = pathlib.Path(args.source).resolve()
    if not (root / '.git').exists():
        fail('source is not a Git checkout: {}'.format(root))
    if command(root, 'git', 'rev-parse', 'HEAD') != UPSTREAM_COMMIT:
        fail('unexpected RustDesk commit')
    for relative, expected in EXPECTED_SHA256.items():
        actual = sha256(root / relative)
        if actual != expected:
            fail('{} has unexpected SHA-256: {} != {}'.format(relative, actual, expected))

    config = (root / 'libs/hbb_common/src/config.rs').read_text(encoding='utf-8')
    marker = 'pub static ref APP_NAME: RwLock<String> = RwLock::new("RustDeskLab".to_owned());'
    if config.count(marker) != 1:
        fail('common preparation did not set the RustDeskLab runtime namespace')

    replace_once(
        root / 'flutter/windows/CMakeLists.txt',
        'set(BINARY_NAME "rustdesk")',
        'set(BINARY_NAME "RustDeskLab")',
    )
    runner = root / 'flutter/windows/runner/Runner.rc'
    replace_once(runner, 'VALUE "FileDescription", "RustDesk Remote Desktop"',
                 'VALUE "FileDescription", "RustDeskLab isolated test client"')
    replace_once(runner, 'VALUE "InternalName", "rustdesk"',
                 'VALUE "InternalName", "RustDeskLab"')
    replace_once(runner, 'VALUE "OriginalFilename", "rustdesk.exe"',
                 'VALUE "OriginalFilename", "RustDeskLab.exe"')
    replace_once(runner, 'VALUE "ProductName", "RustDesk"',
                 'VALUE "ProductName", "RustDeskLab"')
    replace_once(
        root / 'src/platform/windows.rs',
        '        .join("RustDesk")\n        .join("RustDeskCustomClientStaging")',
        '        .join(crate::get_app_name())\n        .join("RustDeskCustomClientStaging")',
    )
    replace_once(
        root / 'src/privacy_mode/win_topmost_window.rs',
        'pub const WIN_TOPMOST_INJECTED_PROCESS_EXE: &\'static str = "RuntimeBroker_rustdesk.exe";',
        'pub const WIN_TOPMOST_INJECTED_PROCESS_EXE: &\'static str = "RuntimeBroker_rustdesklab.exe";',
    )

    subprocess.check_call(['git', 'diff', '--check'], cwd=str(root))
    print(command(root, 'git', 'diff', '--stat', '--',
                  'flutter/windows/CMakeLists.txt', 'flutter/windows/runner/Runner.rc',
                  'src/platform/windows.rs', 'src/privacy_mode/win_topmost_window.rs'))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print('prepare-windows-test-client: {}'.format(error), file=sys.stderr)
        sys.exit(1)
