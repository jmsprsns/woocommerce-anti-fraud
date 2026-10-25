#!/usr/bin/env bash
#
# Rebuild the minified checkout script after editing assets/collector.js.
# Needs Node (npx fetches esbuild on first run).
#
# Usage: bin/build.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../assets"

npx --yes esbuild@0.24.0 collector.js --minify --target=es2017 --outfile=collector.min.js

echo
gzip -c collector.min.js | wc -c | awk '{ printf "collector.min.js  %.1f KB gzipped\n", $1 / 1024 }'
