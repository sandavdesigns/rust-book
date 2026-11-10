#!/bin/sh
# Fetch only public, pinned source. Run in a new empty build directory.
set -eu
patch_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
test ! -e upstream || { echo 'upstream already exists; refusing to overwrite' >&2; exit 1; }
mkdir upstream
curl -fsSL https://codeload.github.com/rustdesk/rustdesk-server/tar.gz/73523b31cfd25d77dee862e6fc9f5e1fb5e485ef -o upstream/server.tar.gz
curl -fsSL https://codeload.github.com/rustdesk/hbb_common/tar.gz/83419b6549636ee39dacef7776c473f5802e08d6 -o upstream/hbb.tar.gz
mkdir upstream/source
tar -xzf upstream/server.tar.gz -C upstream/source --strip-components=1
tar -xzf upstream/hbb.tar.gz -C upstream/source/libs/hbb_common --strip-components=1
(cd upstream/source && patch -p1 < "$patch_dir/server-v1.patch")
(cd upstream/source/libs/hbb_common && patch -p1 < "$patch_dir/hbb-proto-v1.patch")
echo 'Prepared upstream/source; build and test with cargo --locked. No server was started.'
