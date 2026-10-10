#!/usr/bin/env bash
set -euo pipefail

# Run after Windows npm install when using WSL with a repository on C:.
# Keep platform-specific binaries and system libraries without replacing Windows packages.
if [[ ! -x "$HOME/.local/node24/bin/node" ]]; then
  work="$HOME/.cache/ierp-e2e-node"
  mkdir -p "$work" "$HOME/.local/node24"
  cd "$work"
  curl -fsSLo SHASUMS256.txt https://nodejs.org/dist/latest-v24.x/SHASUMS256.txt
  archive="$(awk '/linux-x64.tar.xz$/ {print $2}' SHASUMS256.txt | head -1)"
  curl -fsSLo "$archive" "https://nodejs.org/dist/latest-v24.x/$archive"
  grep " $archive$" SHASUMS256.txt | sha256sum -c -
  tar -xJf "$archive" --strip-components=1 -C "$HOME/.local/node24"
fi
export PATH="$HOME/.local/node24/bin:$PATH"
cd "$(dirname "$0")/.."

transform_version="$(node -p "require('./node_modules/oxc-transform/package.json').version")"
parser_version="$(node -p "require('./node_modules/oxc-parser/package.json').version")"
linux_bindings="$HOME/.cache/ierp-e2e-linux-bindings"
mkdir -p "$linux_bindings"
npm install --prefix "$linux_bindings" --no-save --ignore-scripts \
  "@oxc-transform/binding-linux-x64-gnu@$transform_version" \
  "@oxc-parser/binding-linux-x64-gnu@$parser_version"
for name in oxc-transform oxc-parser; do
  mkdir -p "node_modules/@$name"
  if [[ ! -d "node_modules/@$name/binding-linux-x64-gnu" ]]; then
    cp -r "$linux_bindings/node_modules/@$name/binding-linux-x64-gnu" "node_modules/@$name/"
  fi
done

libroot="$HOME/.local/share/ierp-e2e-linux-libs"
mkdir -p "$libroot"
chrome="$HOME/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell"
if [[ -x "$chrome" ]]; then
  export LD_LIBRARY_PATH="$libroot/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
  if ldd "$chrome" | grep -q 'not found'; then
    work="$HOME/.cache/ierp-e2e-library-downloads"
    mkdir -p "$work"
    pushd "$work" >/dev/null
    apt download libnspr4 libnss3 libasound2t64
    for deb in ./*.deb; do dpkg-deb -x "$deb" "$libroot"; done
    popd >/dev/null
  fi
  if ldd "$chrome" | grep 'not found'; then
    echo 'Some Chromium libraries are still missing.' >&2
    exit 1
  fi
fi
echo 'WSL native e2e dependencies ready.'
