# wp toolbox — conventions

WordPress plugin, PHP 8.3+, WordPress 7.1+ (latest only), single site only.

## Architecture
- `wp-toolbox.php` only checks PHP/WP versions (in old-PHP-compatible syntax) and calls `Plugin::boot()` on `init` priority 0: translations work, other plugins and the theme are loaded, and post types registered on init 10 come after (hook later priorities for those).
- Every feature area is a `Module` (`src/Module.php`) in `src/Modules/<Name>/`. It declares its settings as `Field`s and adds hooks in `register()`. No side effects in constructors, no singletons, no global state.
- All settings live in one option, `wptb_settings` (`src/Settings/Settings.php`). The JSON schema is generated from the fields and validates REST, CLI and direct writes. `WPTB_SETTINGS` in wp-config.php locks values.
- Every `Field` needs `description` (what it does). Add `why` and `sideEffects` whenever they aren't obvious. These texts are the documentation users see.
- WP-CLI: `wp toolbox settings …` (`src/Cli`). The logic is in `SettingsTool` (tested without WP-CLI), `SettingsCommand` only adapts it.
- Updates: `src/Updater` (GitHub releases via the `Update URI` header).
- Settings page: `src/Admin` (page, REST route `wptb/v1/settings`) plus a React app in `assets/src/settings` built with `@wordpress/scripts` into `assets/build` (not committed; built in CI and for the zip). It renders every module and field generically from `SettingsPage::data()`, so new fields need no JS changes. Keep logic that can be tested without a browser in `utils.js`.
- No runtime dependencies: the plugin ships its own autoloader (`wp-toolbox.php`) and no `vendor/`. Composer is for development tools only.
- Module IDs and field keys are stored in the database: never rename them once released. If you must, add a migration.
- `src/Migration`: the one-time update from 3.x, tested against `tests/fixtures/legacy-3.2.5.json` (the options of a real 3.2.5 install). Later schema changes bump `Migration::VERSION` and add a step.

## Testing (TDD)
- Write the failing test first. `composer check` must pass before every commit.
- `tests/Unit`: pure logic. `tests/Integration`: `WP_UnitTestCase` against real WordPress on SQLite (see `tests/bootstrap.php`).
- Test both states of every setting: off = core behaviour unchanged, on = intended behaviour.
- Tests that build their own `Settings`/`Plugin` use `IsolatesSettingsRegistration` (the real plugin is loaded by the bootstrap).
- Tests run in random order. The WP test case restores hooks and the database, but not other globals (post types, taxonomies, widgets, settings registry, REST server): restore whatever you change in `tear_down()`.
- PHPUnit is 9.6 because the WordPress test library doesn't support 10+ yet.
- Settings page: Vitest for `assets/src/**/test`, Playwright in `tests/e2e` (real WordPress from `bin/e2e-site.sh`). E2E tests fail on any browser console error.

## Security
- Escape all output late (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` for JS). Sanitize and validate all input. Check capabilities and nonces.
- PHPStan level max and the WordPress security sniffs must stay clean. Don't suppress findings; fix the cause.

## Style
- Tabs, `declare(strict_types=1)`, `final` classes, typed properties and signatures.
- Comments explain why, not what.
