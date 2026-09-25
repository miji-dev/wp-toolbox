# shellcheck shell=bash
# wp: the installed WP-CLI, or the phar in $WP_CLI (deprecation notices of WP-CLI itself on new PHP versions hidden)
wp() {
	if [[ -n "${WP_CLI:-}" ]]; then
		php -d error_reporting=8191 "$WP_CLI" "$@"
	else
		command wp "$@"
	fi
}
