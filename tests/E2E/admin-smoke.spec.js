const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { expect, test } = require( '@playwright/test' );

const fixturePath = path.join(
	__dirname,
	'fixtures',
	'render-admin-smoke.php'
);
const repoRoot = path.resolve( __dirname, '..', '..' );
const adminUrl =
	'https://example.org/wp-admin/options-general.php?page=onesmtp';

function renderAdminFixture() {
	return execFileSync( 'php', [ fixturePath ], {
		cwd: repoRoot,
		encoding: 'utf8',
		env: {
			...process.env,
			ONESMTP_PLAYWRIGHT_SMOKE: '1',
		},
	} );
}

test.describe( 'Aculect Mail admin browser smoke', () => {
	test.beforeEach( async ( { page } ) => {
		const html = renderAdminFixture();

		await page.route(
			'https://example.org/wp-admin/options-general.php**',
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'text/html; charset=utf-8',
					body: html,
				} );
			}
		);

		await page.goto( adminUrl );
	} );

	test( 'renders the admin shell and primary section navigation', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Mail', exact: true } )
		).toBeVisible();
		await expect(
			page.locator(
				'.onesmtp-admin-brand-mark img[src$="aculect-icon-light.svg"]'
			)
		).toBeVisible();
		await expect(
			page.locator( '[data-onesmtp-workspaces]' )
		).toHaveAttribute( 'data-onesmtp-workspaces-ready', 'true' );
		const primaryNav = page.locator( '.nav-tab-wrapper' );

		for ( const section of [
			{ label: 'Overview', tab: 'onesmtp-overview' },
			{ label: 'Providers', tab: 'onesmtp-providers' },
			{ label: 'Routing', tab: 'onesmtp-routing' },
			{ label: 'Activity', tab: 'onesmtp-activity' },
			{ label: 'Analytics', tab: 'onesmtp-analytics' },
			{ label: 'Settings', tab: 'onesmtp-settings' },
			{ label: 'Advanced', tab: 'onesmtp-advanced' },
		] ) {
			await expect(
				primaryNav.getByRole( 'link', {
					name: section.label,
					exact: true,
				} )
			).toHaveAttribute(
				'href',
				new RegExp( `[?&]tab=${ section.tab }#${ section.tab }$` )
			);
		}

		await expect( page.locator( '#onesmtp-overview' ) ).toBeVisible();
		await expect( page.locator( '#onesmtp-providers' ) ).toBeHidden();
		await expect(
			primaryNav.getByRole( 'link', { name: 'Overview', exact: true } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '#onesmtp-overview' ) ).toContainText(
			'Test email'
		);
		await expect(
			page.locator( '#onesmtp-overview .onesmtp-context-rail' )
		).toHaveCount( 0 );

		await page.goto(
			`${ adminUrl }?tab=onesmtp-delivery#onesmtp-delivery`
		);
		await expect( page.locator( '#onesmtp-overview' ) ).toBeVisible();
		await expect( page.locator( '#onesmtp-delivery' ) ).toBeVisible();

		await openWorkspace( page, 'Providers', 'onesmtp-providers' );
		await expect( page.locator( '#onesmtp-overview' ) ).toBeHidden();
		await expect( page.locator( '#onesmtp-providers' ) ).toContainText(
			'Amazon SES'
		);
		await expect(
			primaryNav.getByRole( 'link', { name: 'Providers', exact: true } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expect(
			page.getByRole( 'heading', { name: 'Providers', exact: true } )
		).toBeFocused();
	} );

	test( 'preserves compatibility hashes and keyboard workspace navigation', async ( {
		page,
	} ) => {
		await page.goto(
			`${ adminUrl }?tab=onesmtp-settings#onesmtp-settings`
		);
		await expect( page.locator( '#onesmtp-settings' ) ).toBeVisible();
		await expect( page.locator( '#onesmtp-overview' ) ).toBeHidden();
		await expect(
			page.locator( '[data-onesmtp-workspace-link="onesmtp-settings"]' )
		).toHaveAttribute( 'aria-current', 'page' );

		await page.goto(
			`${ adminUrl }?tab=onesmtp-settings-advanced#onesmtp-settings-advanced`
		);
		await expect( page.locator( '#onesmtp-advanced' ) ).toBeVisible();
		await expect(
			page.locator( '[data-onesmtp-workspace-link="onesmtp-advanced"]' )
		).toHaveAttribute( 'aria-current', 'page' );

		await page.goto( adminUrl );
		const overviewLink = page.getByRole( 'link', {
			name: 'Overview',
			exact: true,
		} );
		await overviewLink.focus();
		await page.keyboard.press( 'Tab' );
		await expect(
			page.getByRole( 'link', { name: 'Providers', exact: true } )
		).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#onesmtp-providers' ) ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Providers', exact: true } )
		).toBeFocused();
	} );

	test( 'submits provider settings with safe local fixture values', async ( {
		page,
	} ) => {
		await captureFormSubmissions( page );
		await openWorkspace( page, 'Providers', 'onesmtp-providers' );
		await page.locator( '#onesmtp-provider-form summary' ).click();

		const providerForm = page.locator( 'form.onesmtp-provider-form' );
		await providerForm
			.locator( 'input[name="name"]' )
			.fill( 'Browser Smoke SMTP' );
		await providerForm
			.locator( 'select[name="adapter_type"]' )
			.selectOption( 'smtp' );
		await providerForm.locator( 'input[name="priority"]' ).fill( '10' );
		await providerForm.locator( 'input[name="weight"]' ).fill( '1' );
		await providerForm.locator( 'input[name="is_active"]' ).check();
		await providerForm
			.locator( 'input[name="config[host]"]' )
			.fill( 'smtp.local.test' );
		await providerForm
			.locator( 'input[name="config[port]"]' )
			.fill( '2525' );
		await providerForm
			.locator( 'input[name="config[username]"]' )
			.fill( 'browser-smoke' );
		await providerForm
			.getByRole( 'button', { name: 'Save provider' } )
			.click();

		await expect
			.poll( () => latestSubmission( page ) )
			.toMatchObject( {
				onesmtp_provider_action: 'save',
				name: 'Browser Smoke SMTP',
				adapter_type: 'smtp',
				priority: '10',
				weight: '1',
				is_active: '1',
				'config[host]': 'smtp.local.test',
				'config[port]': '2525',
				'config[username]': 'browser-smoke',
			} );
	} );

	test( 'exercises setup wizard and test email form state without external sends', async ( {
		page,
	} ) => {
		await captureFormSubmissions( page );

		await expect( page.locator( '#onesmtp-overview' ) ).toContainText(
			'Complete'
		);
		await expect( page.locator( '#onesmtp-overview' ) ).toContainText(
			'Send test email'
		);
		await expect(
			page.locator( '#onesmtp-overview .onesmtp-setup-shell' )
		).toBeVisible();
		await expect(
			page.locator( '#onesmtp-overview .onesmtp-setup-rail' )
		).toHaveCount( 0 );

		const setupTestForm = page.locator( '#onesmtp-overview form' ).filter( {
			has: page.locator(
				'input[name="onesmtp_setup_action"][value="send_test"]'
			),
		} );

		await setupTestForm
			.locator( 'select[name="provider_id"]' )
			.selectOption( '7' );
		await setupTestForm
			.locator( 'input[name="test_to"]' )
			.fill( 'recipient@example.test' );
		await setupTestForm
			.getByRole( 'button', { name: 'Send test email' } )
			.click();

		await expect
			.poll( () => latestSubmission( page ) )
			.toMatchObject( {
				onesmtp_setup_action: 'send_test',
				provider_id: '7',
				test_to: 'recipient@example.test',
			} );
	} );

	test( 'renders log redaction and privacy-safe diagnostic state', async ( {
		page,
	} ) => {
		await openWorkspace( page, 'Activity', 'onesmtp-activity' );
		await expect( page.locator( '#onesmtp-activity' ) ).toContainText(
			'Recent messages'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).toContainText(
			'Message detail'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).toContainText(
			'Manual resend'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).toContainText(
			'1 recipients across example.test'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).toContainText(
			'transient timeout'
		);

		await expect( page.locator( '#onesmtp-activity' ) ).not.toContainText(
			'recipient@example.test'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).not.toContainText(
			'Internal smoke body'
		);
		await expect( page.locator( '#onesmtp-activity' ) ).not.toContainText(
			'/var/www/private/invoice.pdf'
		);

		await openWorkspace( page, 'Advanced', 'onesmtp-advanced' );
		const advanced = page.locator( '#onesmtp-advanced' );
		const proCapabilities = advanced.locator( '.onesmtp-pro-capabilities' );
		await expect( proCapabilities ).toContainText( 'Pro capabilities' );
		await expect( proCapabilities ).toContainText( 'Available with Pro' );
		await expect(
			proCapabilities.getByRole( 'button', { name: 'Requires Pro' } )
		).toHaveCount( 5 );
		await expect(
			proCapabilities
				.getByRole( 'button', { name: 'Requires Pro' } )
				.first()
		).toBeDisabled();
		await advanced.locator( 'details#onesmtp-diagnostics summary' ).click();
		await expect( advanced ).toContainText( 'Scheduler availability' );
		await expect( advanced ).toContainText( 'Unavailable' );
		await expect( advanced ).toContainText( 'Overdue retries' );
		await expect( advanced ).toContainText( 'Action Scheduler' );
		await expect(
			page.locator( '#onesmtp-diagnostic-preview' )
		).not.toContainText( 'customer body' );
		await expect(
			page.locator( '#onesmtp-diagnostic-preview' )
		).not.toContainText( 'secret-token' );
		await expect(
			page.locator( '#onesmtp-diagnostic-preview' )
		).not.toContainText( 'smtp.local.test' );
	} );

	test( 'renders alert event history with acknowledgement state and redacted context', async ( {
		page,
	} ) => {
		await captureFormSubmissions( page );
		await openWorkspace( page, 'Advanced', 'onesmtp-advanced' );

		const alerts = page.locator( '#onesmtp-advanced' );
		await alerts.locator( 'details#onesmtp-alerts summary' ).click();
		await expect( alerts ).toContainText(
			'Review privacy-safe alert events'
		);
		await expect( alerts ).toContainText(
			'Terminal failure for message #21 after retry boundary.'
		);
		await expect( alerts ).toContainText(
			'Terminal failure already acknowledged for message #20.'
		);
		await expect( alerts ).toContainText( 'Open' );
		await expect( alerts ).toContainText( 'Acknowledged' );
		await expect( alerts ).toContainText( 'Actor #42' );
		await expect( alerts ).toContainText( '"recipient_count": 1' );
		await expect( alerts ).toContainText( '"recipient_domains"' );
		await expect( alerts ).toContainText( 'example.test' );
		await expect( alerts ).toContainText( '[REDACTED]' );

		await expect( alerts ).not.toContainText(
			'fixture-alert-token-never-rendered'
		);
		await expect( alerts ).not.toContainText(
			'fixture-provider-secret-never-rendered'
		);
		await expect( alerts ).not.toContainText(
			'fixture-api-key-never-rendered'
		);
		await expect( alerts ).not.toContainText(
			'fixture-authorization-never-rendered'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'recipient@example.test'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Internal smoke body'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'/var/www/private/invoice.pdf'
		);

		const acknowledgeForm = alerts.locator( 'form' ).filter( {
			has: page.locator(
				'input[name="onesmtp_alert_history_action"][value="acknowledge"]'
			),
		} );
		await expect( acknowledgeForm ).toHaveCount( 1 );
		await expect(
			acknowledgeForm.locator( 'input[name="onesmtp_alert_event_id"]' )
		).toHaveValue( '45' );

		await acknowledgeForm
			.getByRole( 'button', { name: 'Acknowledge' } )
			.click();
		await expect
			.poll( () => latestSubmission( page ) )
			.toMatchObject( {
				onesmtp_alert_history_action: 'acknowledge',
				onesmtp_alert_event_id: '45',
				onesmtp_alert_history_nonce: 'test-nonce',
			} );
	} );

	test( 'keeps the active workspace within a mobile admin viewport', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( page.locator( '.onesmtp-admin-header' ) ).toBeVisible();

		for ( const workspace of [
			[ 'Overview', 'onesmtp-overview' ],
			[ 'Providers', 'onesmtp-providers' ],
			[ 'Routing', 'onesmtp-routing' ],
			[ 'Activity', 'onesmtp-activity' ],
			[ 'Analytics', 'onesmtp-analytics' ],
			[ 'Settings', 'onesmtp-settings' ],
			[ 'Advanced', 'onesmtp-advanced' ],
		] ) {
			await openWorkspace( page, workspace[ 0 ], workspace[ 1 ] );
			if ( workspace[ 1 ] === 'onesmtp-overview' ) {
				await expect(
					page.locator( '#onesmtp-overview .onesmtp-setup-shell' )
				).toBeVisible();
			}

			const hasPageOverflow = await page.evaluate(
				() =>
					document.documentElement.scrollWidth >
					document.documentElement.clientWidth + 1
			);
			expect(
				hasPageOverflow,
				`workspace ${ workspace[ 1 ] } should not overflow the viewport`
			).toBe( false );
		}
	} );

	test( 'renders Pro provider reliability with bounded operational claims', async ( {
		page,
	} ) => {
		await openWorkspace( page, 'Analytics', 'onesmtp-analytics' );

		const reliability = page.locator( '.onesmtp-reliability-panel' );
		await expect( reliability ).toBeVisible();
		await expect( reliability ).toContainText( 'Provider reliability' );
		await expect( reliability ).toContainText( 'Browser Smoke SMTP' );
		await expect( reliability ).toContainText( '96' );
		await expect( reliability ).toContainText( 'Established sample' );
		await expect( reliability ).toContainText(
			'do not guarantee inbox placement or a provider SLA'
		);

		const reports = page.locator( '.onesmtp-advanced-reports' );
		await expect( reports ).toBeVisible();
		await expect( reports ).toContainText( 'Advanced reports' );
		await expect( reports ).toContainText( 'Provider report' );
		await expect( reports ).toContainText( 'Status distribution' );
		await expect( reports ).toContainText( 'Subject groups' );
		await expect( reports ).toContainText( 'Delivery trend' );
		await expect( reports ).toContainText( 'Failure categories' );
		await expect( reports ).toContainText(
			'Smoke report token=[REDACTED]'
		);
		await expect( reports ).toContainText( 'No subject' );
		await expect( reports ).not.toContainText(
			'fixture-secret-never-rendered'
		);
		await page.screenshot( {
			path: path.join(
				repoRoot,
				'output/playwright/advanced-reports-desktop.png'
			),
			fullPage: true,
		} );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.screenshot( {
			path: path.join(
				repoRoot,
				'output/playwright/advanced-reports-mobile.png'
			),
			fullPage: true,
		} );
	} );
} );

async function openWorkspace( page, label, id ) {
	await page
		.locator( '.nav-tab-wrapper' )
		.getByRole( 'link', { name: label, exact: true } )
		.click();
	await expect( page ).toHaveURL( new RegExp( `[?&]tab=${ id }#${ id }$` ) );
	await expect( page.locator( `#${ id }` ) ).toBeVisible();
}

async function captureFormSubmissions( page ) {
	await page.addInitScript( () => {
		window.__onesmtpSubmittedForms = [];
		document.addEventListener(
			'submit',
			( event ) => {
				event.preventDefault();
				const formData = new FormData( event.target );
				window.__onesmtpSubmittedForms.push(
					Object.fromEntries( formData.entries() )
				);
			},
			true
		);
	} );

	await page.evaluate( () => {
		window.__onesmtpSubmittedForms = [];
		document.addEventListener(
			'submit',
			( event ) => {
				event.preventDefault();
				const formData = new FormData( event.target );
				window.__onesmtpSubmittedForms.push(
					Object.fromEntries( formData.entries() )
				);
			},
			true
		);
	} );
}

async function latestSubmission( page ) {
	return page.evaluate( () => {
		const submissions = window.__onesmtpSubmittedForms || [];

		return submissions[ submissions.length - 1 ] || {};
	} );
}
