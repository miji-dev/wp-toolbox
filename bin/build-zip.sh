#!/usr/bin/env bash
# Builds build/wp-toolbox.zip (top folder wp-toolbox/) from the current checkout:
# production autoloader only, no tests, tooling or dev dependencies.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/wp-toolbox.zip
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

rsync -a --exclude-from=.distignore ./ "$stage/wp-toolbox/"
composer install --working-dir="$stage/wp-toolbox" --no-dev --no-interaction --no-progress --classmap-authoritative --quiet
rm -f "$stage/wp-toolbox/composer.json" "$stage/wp-toolbox/composer.lock"

for f in wp-toolbox.php uninstall.php vendor/autoload.php src/Plugin.php; do
	[[ -f "$stage/wp-toolbox/$f" ]] || { echo "missing $f in package" >&2; exit 1; }
done
if [[ -d "$stage/wp-toolbox/tests" || -d "$stage/wp-toolbox/vendor/phpunit" ]]; then
	echo "dev files in package" >&2
	exit 1
fi

mkdir -p build
rm -f "$out"
(cd "$stage" && zip -qr -X - wp-toolbox) > "$out"
echo "$out ($(du -h "$out" | cut -f1))"
