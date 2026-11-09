#!/usr/bin/env python3
"""Verify and prepare an isolated RustDesk 1.5.1 test-client source tree.

This intentionally modifies only the checkout passed on the command line.  It
never touches the repository that contains this helper.
"""
from __future__ import print_function

import argparse
import hashlib
import pathlib
import subprocess
import sys


UPSTREAM_COMMIT = '23e5bb471a040d62f0a6ad52591fb30784265b73'
HBB_COMMON_COMMIT = 'fae47e024dd83cef6aaecde0aa7d11731df11649'
EXPECTED_SHA256 = {
    'src/client.rs': 'ef1db4d1443a25d7b05b90b0b92216bb6bf9e007f77d3e0fbb57f0e992e91356',
    'libs/hbb_common/src/config.rs': '09d30bfcc603bb3a97595638b995a0d71c88bb09b31945b5cdeec7ff6af9c96b',
    'build.py': '0328ffd4f654dee01ce8baf18c3b10f21b5bda7e8043a3f9e3b25ea53b1005c3',
    'flutter/macos/Runner/Configs/AppInfo.xcconfig': '1f07efcabfe3b72ff17919b19c10b20c1fabf37ec3379f308c16d583ce945de1',
    'flutter/macos/Runner.xcodeproj/project.pbxproj': '4c567bd8075200bb316575e5359136cac2b59e6a74ac102eec9344763754bfd1',
}
EXPECTED_STRICT_PATCHED_CLIENT_SHA256 = 'e883ffc4a5f3b2cfbb5bc761a10b6e96479f342062dead1702adae3c12a8edae'


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


def require_sha(root, relative, expected):
    actual = sha256(root / relative)
    if actual != expected:
        fail('{} has unexpected SHA-256: {} != {}'.format(relative, actual, expected))


def replace_once(path, old, new):
    content = path.read_text(encoding='utf-8')
    occurrences = content.count(old)
    if occurrences != 1:
        fail('{} expected one occurrence of {!r}, found {}'.format(path, old, occurrences))
    path.write_text(content.replace(old, new, 1), encoding='utf-8')


def replace_exactly(path, old, new, count):
    content = path.read_text(encoding='utf-8')
    occurrences = content.count(old)
    if occurrences != count:
        fail('{} expected {} occurrences of {!r}, found {}'.format(path, count, old, occurrences))
    path.write_text(content.replace(old, new), encoding='utf-8')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True, help='Disposable RustDesk checkout to modify')
    parser.add_argument('--strict-patch', required=True, help='Path to client-1.5.1-strict.patch')
    args = parser.parse_args()

    root = pathlib.Path(args.source).resolve()
    strict_patch = pathlib.Path(args.strict_patch).resolve()
    if not (root / '.git').exists():
        fail('source is not a Git checkout: {}'.format(root))
    if not strict_patch.is_file():
        fail('strict patch not found: {}'.format(strict_patch))

    if command(root, 'git', 'rev-parse', 'HEAD') != UPSTREAM_COMMIT:
        fail('unexpected RustDesk commit')
    submodule_status = command(root, 'git', 'submodule', 'status', '--', 'libs/hbb_common')
    if not submodule_status.startswith(HBB_COMMON_COMMIT + ' '):
        fail('unexpected libs/hbb_common commit: {}'.format(submodule_status))
    for relative, expected in EXPECTED_SHA256.items():
        require_sha(root, relative, expected)

    subprocess.check_call(['git', 'apply', '--check', str(strict_patch)], cwd=str(root))
    subprocess.check_call(['git', 'apply', str(strict_patch)], cwd=str(root))
    require_sha(root, 'src/client.rs', EXPECTED_STRICT_PATCHED_CLIENT_SHA256)

    # APP_NAME controls the config, preferences and IPC namespace at runtime.
    replace_once(
        root / 'libs/hbb_common/src/config.rs',
        'pub static ref APP_NAME: RwLock<String> = RwLock::new("RustDesk".to_owned());',
        'pub static ref APP_NAME: RwLock<String> = RwLock::new("RustDeskLab".to_owned());'
    )
    app_info = root / 'flutter/macos/Runner/Configs/AppInfo.xcconfig'
    replace_once(app_info, 'PRODUCT_NAME = RustDesk', 'PRODUCT_NAME = RustDeskLab')
    replace_once(
        app_info,
        'PRODUCT_BUNDLE_IDENTIFIER = com.carriez.flutterHbb',
        'PRODUCT_BUNDLE_IDENTIFIER = de.sandav.rustdesklab'
    )
    replace_exactly(
        root / 'flutter/macos/Runner.xcodeproj/project.pbxproj',
        'PRODUCT_BUNDLE_IDENTIFIER = com.carriez.rustdesk;',
        'PRODUCT_BUNDLE_IDENTIFIER = de.sandav.rustdesklab;',
        3
    )
    replace_once(
        root / 'build.py',
        './build/macos/Build/Products/Release/RustDesk.app/Contents/MacOS/',
        './build/macos/Build/Products/Release/RustDeskLab.app/Contents/MacOS/'
    )
    subprocess.check_call(['git', 'diff', '--check'], cwd=str(root))
    print(command(root, 'git', 'diff', '--stat'))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print('prepare-test-client: {}'.format(error), file=sys.stderr)
        sys.exit(1)
