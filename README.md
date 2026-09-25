# wp toolbox

A WordPress plugin that removes bloat from a default WordPress install and adds a few small, sensible enhancements.
Every feature is a switch with a plain-language explanation: what it does, how, why you'd want it, and what else changes.
Cleanups that suit almost every site are on by default.

Built for sites that run the latest WordPress and don't need the old-school blog machinery
(comments, pingbacks, author/date/tag archives, feeds, …), and made to work alongside Yoast SEO, Wordfence,
WP-Optimize and Elementor without duplicating what they do.

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

## Updating from 3.x (and sw-toolbox)

Version 4 is a rewrite. Sites on wp-toolbox 3.x update to it like to any other version (same plugin folder).
On the first request afterwards, the plugin, once:

- carries over the settings that were switched on (disable comments/posts/Gutenberg, "only Elementor", login logo
  and background colour); everything else gets the new defaults,
- deletes every option 3.x (and its predecessor sw-toolbox) stored, the old update checker's data and cron job, and
  the cookie banner texts registered in WPML,
- shows administrators a notice if maintenance mode or Google Analytics/the cookie banner were in use (both are gone
  in v4), with links to pages that still contain the old shortcodes or map widget (which now render nothing).

## Settings

In wp-admin under *Settings → Toolbox*, or with WP-CLI:

```sh
wp toolbox settings list                             # all settings, their value, default and source
wp toolbox settings set comments.disable true
wp toolbox settings set blog.remove_archives author,date
wp toolbox settings reset blog                       # a whole module, or single settings
wp toolbox settings export --file=toolbox.json       # same format as the export on the settings page
wp toolbox settings import toolbox.json              # all or nothing; - reads from STDIN
```

To give several sites the same setup: export once, then `wp toolbox settings import` on each site
(or `wp @all toolbox settings import …` with WP-CLI aliases).

## Development

```sh
composer install
composer test      # PHPUnit: unit + integration tests against real WordPress on SQLite (no Docker, no MySQL)
composer analyse   # PHPStan, level max
composer lint      # PHPCS, WordPress security sniffs
composer check     # all of the above

npm ci
npm run build      # settings page (assets/src → assets/build), needed before building the zip or browser tests
npm run start      # the same, rebuilding on changes
npm run lint:js
npm run test:js    # unit tests of the settings page helpers (Vitest)
npm run test:e2e   # browser tests (Playwright) against a throwaway WordPress on 127.0.0.1:8889, see bin/e2e-site.sh

composer build     # build/wp-toolbox.zip (needs WP-CLI for the translations: `wp`, or WP_CLI=/path/to/wp-cli.phar)
```

Translations (German, informal and formal) are in `languages/*.po`. After changing texts in the code, run
`bin/i18n.sh`: it updates the template and the PO files (with gettext's `msgmerge` if installed, which keeps changed
texts as "fuzzy" instead of dropping their translation) and lists what needs translating. CI fails while any string
is untranslated. The files WordPress loads are generated when building the zip.

Releasing: `bin/set-version.sh 4.1.0`, commit, then `git tag v4.1.0 && git push && git push origin v4.1.0`
(a version with a suffix like `4.1.0-beta.1` becomes a prerelease).
The release workflow runs all checks, builds the zip and publishes the GitHub release.

## History

4.0 is a complete rewrite. The 3.x code lives in an archived repository.

## License

GPL-2.0-or-later
