import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SenderIdentityDrawer( { config } ) {
	const initialIdentity = config.identity || {};
	const [ isOpen, setIsOpen ] = useState( false );
	const [ fromEmail, setFromEmail ] = useState(
		initialIdentity.from_email || ''
	);
	const [ fromName, setFromName ] = useState(
		initialIdentity.from_name || ''
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		const triggers = document.querySelectorAll(
			'[data-onesmtp-drawer-trigger="sender-identity"]'
		);
		const fallback = document.getElementById(
			'onesmtp-sender-identity-fallback'
		);
		const openDrawer = ( event ) => {
			event.preventDefault();
			setNotice( null );
			setIsOpen( true );
		};

		if ( fallback ) {
			fallback.hidden = true;
		}
		triggers.forEach( ( trigger ) =>
			trigger.addEventListener( 'click', openDrawer )
		);

		return () => {
			triggers.forEach( ( trigger ) =>
				trigger.removeEventListener( 'click', openDrawer )
			);
			if ( fallback ) {
				fallback.hidden = false;
			}
		};
	}, [] );

	const save = async () => {
		setIsSaving( true );
		setNotice( null );

		try {
			await apiFetch( {
				url: config.endpoint,
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce },
				data: { from_email: fromEmail, from_name: fromName },
			} );
			setNotice( {
				status: 'success',
				message: __(
					'Sender identity saved. Refreshing setup status…',
					'onesmtp'
				),
			} );
			window.setTimeout( () => window.location.reload(), 600 );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__(
						'Unable to save sender identity. Check the fields and try again.',
						'onesmtp'
					),
			} );
			setIsSaving( false );
		}
	};

	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			className="onesmtp-drawer"
			title={ __( 'Sender identity', 'onesmtp' ) }
			onRequestClose={ () => ! isSaving && setIsOpen( false ) }
		>
			<p className="onesmtp-drawer-description">
				{ __(
					'Choose the name and email address Aculect Mail should use when sending WordPress email.',
					'onesmtp'
				) }
			</p>
			{ notice && (
				<Notice status={ notice.status } isDismissible={ false }>
					{ notice.message }
				</Notice>
			) }
			<TextControl
				__next40pxDefaultSize
				label={ __( 'From email', 'onesmtp' ) }
				type="email"
				value={ fromEmail }
				onChange={ setFromEmail }
				required
				help={ __(
					'Use an address authorised by your provider and sender domain.',
					'onesmtp'
				) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'From name', 'onesmtp' ) }
				value={ fromName }
				onChange={ setFromName }
				required
			/>
			<div className="onesmtp-drawer-actions">
				<Button
					variant="secondary"
					onClick={ () => setIsOpen( false ) }
					disabled={ isSaving }
				>
					{ __( 'Cancel', 'onesmtp' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ save }
					disabled={ isSaving || ! fromEmail || ! fromName }
				>
					{ isSaving ? (
						<>
							<Spinner /> { __( 'Saving…', 'onesmtp' ) }
						</>
					) : (
						__( 'Save sender identity', 'onesmtp' )
					) }
				</Button>
			</div>
		</Modal>
	);
}
