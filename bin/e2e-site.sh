#!/usr/bin/env bash
# Throwaway WordPress site for the browser tests (SQLite, PHP's built-in server): no Docker, no WP-CLI.
# Uses WordPress and the SQLite drop-in from vendor/ and this checkout as the plugin (symlinked, so run
# `npm run build` first). Fresh database on every start.
#
#   bin/e2e-site.sh [port]    set up and serve on 127.0.0.1:<port> (default 8889) until stopped
set -euo pipefail
cd "$(dirname "$0")/.."
root=$(pwd)
port=${1:-8889}
site=${WPTB_E2E_DIR:-${TMPDIR:-/tmp}/wptb-e2e}

[[ -f vendor/roots/wordpress-no-content/wp-load.php ]] || { echo "run composer install first" >&2; exit 1; }
[[ -f assets/build/settings.js ]] || { echo "run npm run build first" >&2; exit 1; }

rm -rf "$site"
mkdir -p "$site"
cp -R vendor/roots/wordpress-no-content/. "$site/"
mkdir -p "$site/wp-content/plugins" "$site/wp-content/themes" "$site/wp-content/database"
cp -R vendor/wp-plugins/sqlite-database-integration "$site/wp-content/plugins/"
sed -e "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$site/wp-content/plugins/sqlite-database-integration|" \
	-e "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|" \
	vendor/wp-plugins/sqlite-database-integration/db.copy > "$site/wp-content/db.php"
ln -s "$root" "$site/wp-content/plugins/wp-toolbox"

cat > "$site/wp-config.php" <<PHP
<?php
define('DB_NAME', 'wp');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_DIR', __DIR__ . '/wp-content/database/');
define('DB_FILE', 'e2e.sqlite');
define('WP_HOME', 'http://127.0.0.1:$port');
define('WP_SITEURL', 'http://127.0.0.1:$port');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', __DIR__ . '/debug.log');
define('AUTOMATIC_UPDATER_DISABLED', true);
// one locked and one invalid entry, to test how the settings page shows them
define('WPTB_SETTINGS', ['security' => ['disable_xmlrpc' => true], 'head' => ['nope' => true]]);
\$table_prefix = 'wp_';
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
PHP

php -d display_errors=stderr -r '
	define("WP_INSTALLING", true);
	$_SERVER["HTTP_HOST"] = "127.0.0.1";
	require $argv[1] . "/wp-load.php";
	require_once ABSPATH . "wp-admin/includes/upgrade.php";
	wp_install("wp-toolbox e2e", "admin", "admin@example.org", true, "", "admin");
	update_option("admin_email_lifespan", 2000000000); // no "confirm your email" screen after login
	update_option("permalink_structure", "/%postname%/");
	require_once ABSPATH . "wp-admin/includes/plugin.php";
	$result = activate_plugin("wp-toolbox/wp-toolbox.php");
	if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message() . "\n"); exit(1); }
' "$site" >/dev/null

echo "wp-toolbox e2e site: http://127.0.0.1:$port (admin/admin)"
cd "$site"
echo "server log: $site/server.log"
PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$port" 2>"$site/server.log"
