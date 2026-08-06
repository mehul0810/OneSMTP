import { createPortal, createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const initialized = new WeakSet();

const mountComponent = async ( section, selector, loader, configAttribute ) => {
	const mount = section.querySelector( selector );
	if ( ! mount || initialized.has( mount ) ) {
		return;
	}

	initialized.add( mount );
	try {
		const { default: Component } = await loader();
		const config = configAttribute
			? JSON.parse( mount.dataset[ configAttribute ] || '{}' )
			: undefined;
		createRoot( mount ).render( <Component config={ config } /> );
	} catch ( error ) {
		mount.textContent = __(
			'This interface could not be loaded. The server-rendered fallback remains available.',
			'onesmtp'
		);
	}
};

const mountProviders = async ( section ) => {
	const mounts = Array.from(
		section.querySelectorAll(
			'[data-onesmtp-component="provider-inline-settings"]'
		)
	).filter( ( mount ) => ! initialized.has( mount ) );
	if ( ! mounts.length ) {
		return;
	}

	mounts.forEach( ( mount ) => initialized.add( mount ) );
	try {
		const { default: ProviderInlineSettings } = await import(
			'./Admin/modules/ProviderDrawer'
		);
		const host = document.createElement( 'div' );
		host.hidden = true;
		section.appendChild( host );
		createRoot( host ).render(
			mounts.map( ( mount, index ) =>
				createPortal(
					<ProviderInlineSettings
						config={ JSON.parse(
							mount.dataset.onesmtpProviderConfig || '{}'
						) }
					/>,
					mount,
					mount.dataset.onesmtpProviderConfig || String( index )
				)
			)
		);
		const providerFallback = document.getElementById(
			'onesmtp-provider-form'
		);
		if ( providerFallback ) {
			providerFallback.hidden = true;
		}
	} catch ( error ) {
		mounts.forEach( ( mount ) => {
			mount.textContent = __(
				'Provider settings could not be loaded. Use the fallback form below.',
				'onesmtp'
			);
		} );
	}
};

const mountDataViews = async ( section ) => {
	const mounts = Array.from(
		section.querySelectorAll( '[data-onesmtp-dataviews]' )
	).filter( ( mount ) => ! initialized.has( mount ) );
	if ( ! mounts.length ) {
		return;
	}

	mounts.forEach( ( mount ) => initialized.add( mount ) );
	try {
		const { default: DataViewListing } = await import(
			'./Admin/components/DataViewListing'
		);
		const labels = {
			'delivery-messages': __(
				'Aculect Mail delivery messages',
				'onesmtp'
			),
			providers: __( 'Aculect Mail providers', 'onesmtp' ),
			'analytics-providers': __( 'Provider performance', 'onesmtp' ),
		};
		mounts.forEach( ( mount ) => {
			const key = mount.dataset.onesmtpDataviews;
			const configNode = document.querySelector(
				`[data-onesmtp-dataviews-config="${ key }"]`
			);
			if ( ! configNode ) {
				return;
			}
			createRoot( mount ).render(
				<DataViewListing
					config={ JSON.parse( configNode.textContent ) }
					label={
						labels[ key ] || __( 'Aculect Mail data', 'onesmtp' )
					}
				/>
			);
		} );
	} catch ( error ) {
		mounts.forEach( ( mount ) => {
			mount.textContent = __(
				'The listing could not be loaded.',
				'onesmtp'
			);
		} );
	}
};

const initializeWorkspace = ( workspaceId ) => {
	const section = document.querySelector(
		`[data-onesmtp-workspace="${ workspaceId }"]`
	);
	if ( ! section ) {
		return;
	}

	mountComponent(
		section,
		'[data-onesmtp-component="sender-identity-drawer"]',
		() => import( './Admin/modules/SenderIdentityDrawer' ),
		'onesmtpSenderIdentityConfig'
	);
	mountComponent(
		section,
		'[data-onesmtp-component="settings-navigation"]',
		() => import( './Admin/modules/SettingsNavigation' )
	);
	mountProviders( section );
	mountDataViews( section );
};

document.addEventListener( 'onesmtp:workspace-activated', ( event ) =>
	initializeWorkspace( event.detail?.workspaceId || 'onesmtp-overview' )
);
const visibleWorkspace = document.querySelector(
	'[data-onesmtp-workspace]:not([hidden])'
);
initializeWorkspace(
	visibleWorkspace?.dataset.onesmtpWorkspace || 'onesmtp-overview'
);
