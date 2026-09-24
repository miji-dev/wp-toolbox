#!/usr/bin/env bash
# Usage: bin/set-version.sh 4.0.0-alpha.1 — sets the version in the plugin header and Plugin::VERSION.
set -euo pipefail
cd "$(dirname "$0")/.."
version=${1:?usage: bin/set-version.sh <version>}
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "invalid version: $version" >&2; exit 1; }
sed -i.bak -E "s/^( \* Version: +).*/\1$version/" wp-toolbox.php && rm wp-toolbox.php.bak
sed -i.bak -E "s/(public const VERSION = ')[^']*(';)/\1$version\2/" src/Plugin.php && rm src/Plugin.php.bak
grep -E "^ \* Version:|const VERSION" wp-toolbox.php src/Plugin.php
