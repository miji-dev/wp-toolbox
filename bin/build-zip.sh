#!/usr/bin/env bash
# Builds build/wp-toolbox.zip (top folder wp-toolbox/) from the current checkout:
# production autoloader only, no tests, tooling or dev dependencies.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/wp-toolbox.zip
[[ -f vendor/composer/installed.json ]] || { echo "run composer install first" >&2; exit 1; }
[[ -f assets/build/settings.js ]] || { echo "run npm ci && npm run build first" >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

bin/prefix-vendor.sh >/dev/null
rsync -a --exclude-from=.distignore ./ "$stage/wp-toolbox/"
# runtime libraries ship only as the prefixed copy in vendor-prefixed/ (they are dev requirements for Composer)
composer install --working-dir="$stage/wp-toolbox" --no-dev --no-scripts --no-interaction --no-progress --classmap-authoritative --quiet
rm -f "$stage/wp-toolbox/composer.json" "$stage/wp-toolbox/composer.lock"

for f in wp-toolbox.php uninstall.php vendor/autoload.php vendor-prefixed/autoload.php src/Plugin.php assets/build/settings.js assets/build/settings.asset.php; do
	[[ -f "$stage/wp-toolbox/$f" ]] || { echo "missing $f in package" >&2; exit 1; }
done
if [[ -d "$stage/wp-toolbox/tests" || -d "$stage/wp-toolbox/vendor/phpunit" || -d "$stage/wp-toolbox/vendor/enshrined" || -d "$stage/wp-toolbox/node_modules" || -d "$stage/wp-toolbox/assets/src" ]]; then
	echo "dev files in package" >&2
	exit 1
fi

mkdir -p build
rm -f "$out"
(cd "$stage" && zip -qr -X - wp-toolbox) > "$out"
echo "$out ($(du -h "$out" | cut -f1))"
