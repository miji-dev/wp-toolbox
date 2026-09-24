# wp toolbox

A WordPress plugin that removes bloat from a default WordPress install and adds a few small, sensible enhancements.
Every feature is a switch with a plain-language explanation: what it does, why you'd want it, and what else changes.

Built for sites that run the latest WordPress and don't need the old-school blog machinery
(comments, pingbacks, author/date/tag archives, feeds, …).

## Requirements

- WordPress 7.1+
- PHP 8.3+
- Single site (no multisite)

## Install & updates

Download `wp-toolbox.zip` from the [latest release](https://github.com/miji-dev/wp-toolbox/releases/latest) and upload it
under *Plugins → Add New → Upload*. Updates then show up in WordPress like any other plugin update
(the plugin asks GitHub releases, see `src/Updater`).

Optional constants in `wp-config.php`:

```php
// Lock settings: they can't be changed in wp-admin and show as locked there.
define('WPTB_SETTINGS', [
    'comments' => ['disable' => true],
]);

// Also offer prereleases (alpha/beta) as updates.
define('WPTB_UPDATE_CHANNEL', 'beta');
```

## Development

```sh
composer install
composer test      # PHPUnit: unit + integration tests against real WordPress on SQLite (no Docker, no MySQL)
composer analyse   # PHPStan, level max
composer lint      # PHPCS, WordPress security sniffs
composer check     # all of the above
composer build     # build/wp-toolbox.zip
```

Releasing: `bin/set-version.sh 4.1.0`, commit, then `git tag v4.1.0 && git push && git push origin v4.1.0`
(a version with a suffix like `4.1.0-beta.1` becomes a prerelease).
The release workflow runs all checks, builds the zip and publishes the GitHub release.

## History

4.0 is a complete rewrite. The 3.x code lives in an archived repository.

## License

GPL-2.0-or-later
