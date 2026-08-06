import { useEffect, useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const tabs = [
	{
		name: 'general',
		title: __( 'General', 'onesmtp' ),
		className: 'onesmtp-settings-tab',
	},
	{
		name: 'notifications',
		title: __( 'Notifications', 'onesmtp' ),
		className: 'onesmtp-settings-tab',
	},
];

export default function SettingsNavigation() {
	const initial = window.location.hash.replace( '#onesmtp-settings-', '' );
	const [ active, setActive ] = useState(
		tabs.some( ( tab ) => tab.name === initial ) ? initial : 'general'
	);

	useEffect( () => {
		document
			.querySelectorAll( '[data-onesmtp-settings-group]' )
			.forEach( ( group ) => {
				group.hidden = group.dataset.onesmtpSettingsGroup !== active;
			} );
	}, [ active ] );

	return (
		<TabPanel
			className="onesmtp-settings-tabs"
			activeClass="is-active"
			initialTabName={ active }
			tabs={ tabs }
			onSelect={ ( name ) => {
				setActive( name );
				window.history.replaceState(
					null,
					'',
					`#onesmtp-settings-${ name }`
				);
			} }
		>
			{ () => null }
		</TabPanel>
	);
}
