const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		command: 'npm run env:start',
		port: 8889,
		timeout: 180000,
		reuseExistingServer: true,
	},
} );
