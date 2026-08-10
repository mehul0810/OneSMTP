const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { expect, test } = require( '@playwright/test' );

const fixturePath = path.join(
	__dirname,
	'fixtures',
	'render-network-admin-smoke.php'
);
const repoRoot = path.resolve( __dirname, '..', '..' );
const networkUrl =
	'https://example.org/wp-admin/network/settings.php?page=onesmtp-network';
const screenshotDir = path.join(
	repoRoot,
	'docs',
	'admin',
	'screenshots',
	'issue-41'
);

function renderNetworkFixture( mode, query = 'tab=settings' ) {
	return execFileSync( 'php', [ fixturePath ], {
		cwd: repoRoot,
		encoding: 'utf8',
		env: {
			...process.env,
			ONESMTP_PLAYWRIGHT_NETWORK_MODE: mode,
			ONESMTP_PLAYWRIGHT_NETWORK_QUERY: query,
		},
	} );
}

async function loadNetworkFixture( page, mode, query = 'tab=settings' ) {
	const html = renderNetworkFixture( mode, query );
	await page.route(
		'https://example.org/wp-admin/network/settings.php**',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'text/html; charset=utf-8',
				body: html,
			} );
		}
	);
	await page.goto( `${ networkUrl }&${ query }` );
}

async function assertNoViewportOverflow( page, label ) {
	const hasOverflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth >
			document.documentElement.clientWidth + 1
	);
	expect( hasOverflow, `${ label } should not overflow the viewport` ).toBe(
		false
	);
}

test.describe( 'Aculect Mail network-admin browser smoke', () => {
	test( 'default-deny state is explicit on desktop and mobile', async ( {
		page,
	} ) => {
		await loadNetworkFixture( page, 'deny' );
		await expect(
			page.getByRole( 'heading', { name: 'Aculect Mail Network' } )
		).toBeVisible();
		await expect( page.locator( '.notice-error' ) ).toContainText(
			'You do not have permission'
		);
		await expect( page.locator( '.onesmtp-network-admin' ) ).toHaveCount(
			0
		);
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-deny-desktop.png' ),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await assertNoViewportOverflow( page, 'default-deny mobile state' );
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-deny-mobile.png' ),
			fullPage: true,
		} );
	} );

	test( 'Pro settings expose safe inheritance and override controls', async ( {
		page,
	} ) => {
		await loadNetworkFixture( page, 'pro' );
		const network = page.locator( '.onesmtp-network-admin' );
		await expect( network ).toContainText( 'Safe network defaults' );
		await expect( network ).toContainText(
			'Site inheritance and overrides'
		);
		await expect( network ).toContainText( 'Network logs' );
		await expect( network ).toContainText( 'provider credentials' );
		await expect(
			network.locator( 'input[name="network_inherit_rate_limits"]' )
		).toBeVisible();
		await expect(
			network.locator( 'input[name="site_inherit_rate_limits"]' )
		).toBeVisible();
		await expect( page.locator( 'body' ) ).not.toContainText(
			'fixture-network-secret-never-rendered'
		);
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-settings-desktop.png' ),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await assertNoViewportOverflow( page, 'network settings mobile state' );
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-settings-mobile.png' ),
			fullPage: true,
		} );
	} );

	test( 'empty network logs have a clear desktop and mobile state', async ( {
		page,
	} ) => {
		await loadNetworkFixture( page, 'empty', 'tab=logs' );
		const network = page.locator( '.onesmtp-network-admin' );
		await expect( network ).toContainText( 'Network delivery logs' );
		await expect( network ).toContainText(
			'No network email activity yet.'
		);
		await expect( network.locator( 'table' ) ).toHaveCount( 0 );
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-logs-empty-desktop.png' ),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await assertNoViewportOverflow(
			page,
			'empty network logs mobile state'
		);
		await page.screenshot( {
			path: path.join( screenshotDir, 'network-logs-empty-mobile.png' ),
			fullPage: true,
		} );
	} );

	test( 'long filtered logs stay bounded and paginated on desktop and mobile', async ( {
		page,
	} ) => {
		const query =
			'tab=logs&site_id=2&status=failed&s=needle&network_logs_per_page=10&network_log_page=2';
		await loadNetworkFixture( page, 'long', query );
		const network = page.locator( '.onesmtp-network-admin' );
		await expect( network ).toContainText( 'Page 2 of 8' );
		await expect( network ).toContainText( 'Network delivery logs' );
		await expect( network.locator( 'input[name="site_id"]' ) ).toHaveValue(
			'2'
		);
		await expect( network.locator( 'input[name="s"]' ) ).toHaveValue(
			'needle'
		);
		await expect(
			network.locator( 'select[name="network_logs_per_page"]' )
		).toHaveValue( '10' );
		await expect( network.locator( 'table tbody tr' ) ).toHaveCount( 10 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'private-recipient@example.test'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'fixture-network-token-never-rendered'
		);
		await page.screenshot( {
			path: path.join(
				screenshotDir,
				'network-logs-filtered-page2-desktop.png'
			),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await assertNoViewportOverflow(
			page,
			'long network logs mobile state'
		);
		await page.screenshot( {
			path: path.join(
				screenshotDir,
				'network-logs-filtered-page2-mobile.png'
			),
			fullPage: true,
		} );
	} );
} );
