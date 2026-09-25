#!/usr/bin/env bash
# Copies runtime dependencies into vendor-prefixed/ under our own namespace (Miji\Toolbox\Vendor\...),
# so they can't collide with other plugins that bundle a different version of the same library.
# Uses a pinned, checksum-verified Strauss phar. Runs automatically after composer install/update.
set -euo pipefail
cd "$(dirname "$0")/.."

version=0.30.0
sha256=08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96
phar=build/.strauss/strauss-$version.phar

if [[ ! -f $phar ]]; then
	mkdir -p "$(dirname "$phar")"
	curl -fsSL -o "$phar.tmp" "https://github.com/BrianHenryIE/strauss/releases/download/$version/strauss.phar"
	mv "$phar.tmp" "$phar"
fi
if ! echo "$sha256  $phar" | shasum -a 256 -c --status; then
	rm -f "$phar"
	echo "strauss.phar checksum mismatch" >&2
	exit 1
fi

php "$phar" --info >/dev/null 2>&1 || true
php "$phar"
