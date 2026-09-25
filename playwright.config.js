// Browser tests of the settings page against a throwaway WordPress site (bin/e2e-site.sh).
const { defineConfig } = require( '@playwright/test' );

const port = 8889;

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: `http://127.0.0.1:${ port }`,
		trace: 'retain-on-failure',
	},
	webServer: {
		command: `bin/e2e-site.sh ${ port }`,
		url: `http://127.0.0.1:${ port }/wp-login.php`,
		timeout: 60_000,
		reuseExistingServer: false,
	},
} );
