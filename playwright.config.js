const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	timeout: 120000,
	expect: {
		timeout: 30000,
	},
	reporter: 'list',
	use: {
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
} );
