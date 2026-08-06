( function () {
	'use strict';

	const root = document.querySelector( '[data-onesmtp-workspaces]' );
	if ( ! root ) {
		return;
	}

	const sections = Array.prototype.slice.call(
		root.querySelectorAll( '[data-onesmtp-workspace]' )
	);
	const aliases = {
		'onesmtp-general': 'onesmtp-overview',
		'onesmtp-dashboard': 'onesmtp-analytics',
		'onesmtp-setup': 'onesmtp-overview',
		'onesmtp-delivery': 'onesmtp-overview',
		'onesmtp-settings': 'onesmtp-settings',
		'onesmtp-logs': 'onesmtp-activity',
		'onesmtp-diagnostics': 'onesmtp-advanced',
		'onesmtp-alerts': 'onesmtp-advanced',
		'onesmtp-tools': 'onesmtp-advanced',
		'onesmtp-provider-tools': 'onesmtp-advanced',
		'onesmtp-settings-advanced': 'onesmtp-advanced',
	};

	function resolveWorkspace() {
		const queryTab = new URLSearchParams( window.location.search ).get(
			'tab'
		);
		const target = window.location.hash.replace( /^#/, '' );
		const resolved =
			aliases[ queryTab ] || queryTab || aliases[ target ] || target;
		const exists = sections.some( function ( section ) {
			return section.dataset.onesmtpWorkspace === resolved;
		} );

		return exists ? resolved : 'onesmtp-overview';
	}

	// Fragment-only links from pre-0.3.0 URLs need a server-rendered screen.
	const legacyTarget = aliases[ window.location.hash.replace( /^#/, '' ) ];
	const hasTab = new URLSearchParams( window.location.search ).has( 'tab' );
	if ( legacyTarget && ! hasTab && legacyTarget !== 'onesmtp-overview' ) {
		const legacyUrl = new URL( window.location.href );
		legacyUrl.searchParams.set( 'tab', legacyTarget );
		if (
			window.location.hash.replace( /^#/, '' ) ===
			'onesmtp-settings-advanced'
		) {
			legacyUrl.hash = 'onesmtp-advanced';
		}
		window.location.replace( legacyUrl.toString() );
		return;
	}

	const links = Array.prototype.slice.call(
		root.querySelectorAll( '[data-onesmtp-workspace-link]' )
	);

	function activateWorkspace( workspaceId, moveFocus ) {
		let activeSection = null;
		let activeLink = null;

		sections.forEach( function ( section ) {
			const isActive = section.dataset.onesmtpWorkspace === workspaceId;
			section.hidden = ! isActive;
			section.setAttribute( 'aria-hidden', isActive ? 'false' : 'true' );

			if ( isActive ) {
				activeSection = section;
			}
		} );

		links.forEach( function ( link ) {
			const isActive = link.dataset.onesmtpWorkspaceLink === workspaceId;
			link.classList.toggle( 'nav-tab-active', isActive );

			if ( isActive ) {
				link.setAttribute( 'aria-current', 'page' );
				activeLink = link;
			} else {
				link.removeAttribute( 'aria-current' );
			}
		} );

		root.setAttribute( 'data-onesmtp-workspaces-ready', 'true' );
		document.dispatchEvent(
			new CustomEvent( 'onesmtp:workspace-activated', {
				detail: { workspaceId },
			} )
		);

		const navigation = root.querySelector( '.onesmtp-admin-nav' );
		if (
			navigation &&
			activeLink &&
			navigation.scrollWidth > navigation.clientWidth
		) {
			navigation.scrollLeft = Math.max(
				0,
				activeLink.offsetLeft -
					( navigation.clientWidth - activeLink.offsetWidth ) / 2
			);
		}

		if ( ! moveFocus || ! activeSection ) {
			return;
		}

		const targetId = window.location.hash.replace( /^#/, '' );
		if ( targetId && targetId !== workspaceId ) {
			const target = document.getElementById( targetId );
			if ( target ) {
				target.scrollIntoView( { block: 'start' } );
				return;
			}
		}

		const heading = activeSection.querySelector( 'h2[tabindex="-1"]' );
		if ( heading ) {
			heading.focus();
		}
	}

	function openHashTarget() {
		const targetId = window.location.hash.replace( /^#/, '' );
		if ( ! targetId ) {
			return;
		}
		const target = document.getElementById( targetId );
		if ( target && target.tagName.toLowerCase() === 'details' ) {
			target.open = true;
		}
	}

	links.forEach( function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			const workspaceId = link.dataset.onesmtpWorkspaceLink;
			if (
				! workspaceId ||
				! sections.some( function ( section ) {
					return section.dataset.onesmtpWorkspace === workspaceId;
				} )
			) {
				return;
			}

			event.preventDefault();
			const url = new URL( link.href, window.location.href );
			url.hash = workspaceId;
			url.searchParams.set( 'tab', workspaceId );
			window.history.pushState(
				{ onesmtpWorkspace: workspaceId },
				'',
				url.toString()
			);
			activateWorkspace( workspaceId, true );
		} );
	} );

	root.querySelectorAll( '[data-onesmtp-provider-type]' ).forEach(
		function ( link ) {
			link.addEventListener( 'click', function () {
				window.setTimeout( function () {
					const select = document.getElementById(
						'onesmtp-provider-adapter_type'
					);
					if ( select ) {
						select.value = link.dataset.onesmtpProviderType || '';
					}
				}, 0 );
			} );
		}
	);

	activateWorkspace( resolveWorkspace(), false );
	openHashTarget();

	window.addEventListener( 'hashchange', function () {
		activateWorkspace( resolveWorkspace(), true );
		openHashTarget();
	} );

	window.addEventListener( 'popstate', function () {
		activateWorkspace( resolveWorkspace(), true );
	} );
} )();
