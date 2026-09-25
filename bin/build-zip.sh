#!/usr/bin/env bash
# Builds build/wp-toolbox.zip (top folder wp-toolbox/) from the current checkout: only what runs on a website.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/wp-toolbox.zip
[[ -f assets/build/settings.js ]] || { echo "run npm ci && npm run build first" >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

rsync -a --exclude-from=.distignore ./ "$stage/wp-toolbox/"

for f in wp-toolbox.php uninstall.php src/Plugin.php assets/build/settings.js assets/build/settings.asset.php; do
	[[ -f "$stage/wp-toolbox/$f" ]] || { echo "missing $f in package" >&2; exit 1; }
done
# anything else at the top level is development material that slipped past .distignore
unexpected=$(cd "$stage/wp-toolbox" && ls -A | grep -vxE 'wp-toolbox\.php|uninstall\.php|src|assets|languages|LICENSE' || true)
if [[ -n "$unexpected" || -d "$stage/wp-toolbox/assets/src" ]]; then
	echo "not part of the plugin: ${unexpected:-assets/src}" >&2
	exit 1
fi

mkdir -p build
rm -f "$out"
(cd "$stage" && zip -qr -X - wp-toolbox) > "$out"
echo "$out ($(du -h "$out" | cut -f1))"
