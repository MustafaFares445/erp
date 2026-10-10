#!/usr/bin/env bash
set -euo pipefail
# This Windows project runs e2e inside Ubuntu WSL with native Linux Node.js.
if [[ -x "$HOME/.local/node24/bin/node" ]]; then
  export PATH="$HOME/.local/node24/bin:$PATH"
fi
# User-local Playwright system libraries, installed without sudo in WSL.
if [[ -d "$HOME/.local/share/ierp-e2e-linux-libs/usr/lib/x86_64-linux-gnu" ]]; then
  export LD_LIBRARY_PATH="$HOME/.local/share/ierp-e2e-linux-libs/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
fi
cd "$(dirname "$0")/.."
exec npx e2e "$@"
