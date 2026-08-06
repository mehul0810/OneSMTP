const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/E2E',
	outputDir: './output/playwright/test-results',
	reporter: [ [ 'list' ] ],
	timeout: 30 * 1000,
	expect: {
		timeout: 5000,
	},
	use: {
		baseURL:
			'https://example.org/wp-admin/options-general.php?page=onesmtp',
		browserName: 'chromium',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'chromium-admin-smoke',
			use: {
				...devices[ 'Desktop Chrome' ],
			},
		},
	],
} );
