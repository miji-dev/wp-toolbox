#!/usr/bin/env bash
# Builds build/wp-toolbox.zip (top folder wp-toolbox/) from the current checkout: only what runs on a website.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/wp-toolbox.zip
[[ -f assets/build/settings.js ]] || { echo "run npm ci && npm run build first" >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

rsync -a --exclude-from=.distignore ./ "$stage/wp-toolbox/"

# translations: WordPress loads PHP files (since 6.5) and, for the settings page script, JSON files named after it
source bin/lib-wp-cli.sh
mkdir -p "$stage/wp-toolbox/languages"
wp i18n make-php languages "$stage/wp-toolbox/languages" --quiet
map=$(printf '"%s":"assets/build/settings.js",' assets/src/settings/*.js)
# older WP-CLI versions remove the strings from the PO files and write .mo files unless told not to
help=$(wp help i18n make-json)
purge=$([[ "$help" == *--purge* ]] && echo --no-purge || true)
wp i18n make-json languages "$stage/wp-toolbox/languages" --use-map="{${map%,}}" $purge --quiet

for f in wp-toolbox.php uninstall.php src/Plugin.php assets/build/settings.js assets/build/settings.asset.php languages/wptb-de_DE.l10n.php languages/wptb-de_DE_formal.l10n.php; do
	[[ -f "$stage/wp-toolbox/$f" ]] || { echo "missing $f in package" >&2; exit 1; }
done
# anything else at the top level is development material that slipped past .distignore
unexpected=$(cd "$stage/wp-toolbox" && ls -A | grep -vxE 'wp-toolbox\.php|uninstall\.php|src|assets|languages|LICENSE' || true)
unexpected+=$(cd "$stage/wp-toolbox" && ls -d assets/src languages/*.po languages/*.pot languages/*.mo 2>/dev/null || true)
if [[ -n "$unexpected" ]]; then
	echo "not part of the plugin: $unexpected" >&2
	exit 1
fi
if [[ $(ls "$stage/wp-toolbox/languages"/*.json | wc -l) -ne $(ls languages/*.po | wc -l) ]]; then
	echo "missing translations of the settings page script" >&2
	exit 1
fi

mkdir -p build
rm -f "$out"
(cd "$stage" && zip -qr -X - wp-toolbox) > "$out"
echo "$out ($(du -h "$out" | cut -f1))"
