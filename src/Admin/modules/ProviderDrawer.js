import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const fieldsByType = {
	smtp: [
		[ 'host', __( 'SMTP host', 'onesmtp' ) ],
		[ 'port', __( 'SMTP port', 'onesmtp' ), 'number' ],
		[ 'username', __( 'Username', 'onesmtp' ) ],
		[ 'password', __( 'Password', 'onesmtp' ), 'password' ],
	],
	amazon_ses: [
		[ 'region', __( 'AWS Region', 'onesmtp' ) ],
		[ 'username', __( 'SES SMTP username', 'onesmtp' ) ],
		[ 'password', __( 'SES SMTP password', 'onesmtp' ), 'password' ],
	],
	gmail: [
		[ 'client_id', __( 'OAuth client ID', 'onesmtp' ) ],
		[ 'client_secret', __( 'OAuth client secret', 'onesmtp' ), 'password' ],
		[ 'refresh_token', __( 'OAuth refresh token', 'onesmtp' ), 'password' ],
	],
	sendgrid: [ [ 'api_key', __( 'API key', 'onesmtp' ), 'password' ] ],
	postmark: [ [ 'api_key', __( 'Server token', 'onesmtp' ), 'password' ] ],
	brevo: [ [ 'api_key', __( 'API key', 'onesmtp' ), 'password' ] ],
	mailgun: [
		[ 'api_key', __( 'Private API key', 'onesmtp' ), 'password' ],
		[ 'domain', __( 'Sending domain', 'onesmtp' ) ],
		[ 'region', __( 'API region (us or eu)', 'onesmtp' ) ],
		[
			'webhook_signing_key',
			__( 'Mailgun webhook signing key', 'onesmtp' ),
			'password',
		],
	],
	resend: [ [ 'api_key', __( 'API key', 'onesmtp' ), 'password' ] ],
	mailjet: [
		[ 'api_key', __( 'API key', 'onesmtp' ) ],
		[ 'secret_key', __( 'Secret key', 'onesmtp' ), 'password' ],
	],
	sparkpost: [
		[ 'api_key', __( 'API key', 'onesmtp' ), 'password' ],
		[ 'region', __( 'API region (us or eu)', 'onesmtp' ) ],
	],
	mailersend: [ [ 'api_key', __( 'API token', 'onesmtp' ), 'password' ] ],
	smtp2go: [
		[ 'api_key', __( 'API key', 'onesmtp' ), 'password' ],
		[ 'region', __( 'API region (global, us, eu, or au)', 'onesmtp' ) ],
	],
	elastic_email: [ [ 'api_key', __( 'API key', 'onesmtp' ), 'password' ] ],
	zeptomail: [
		[ 'api_key', __( 'Send mail token', 'onesmtp' ), 'password' ],
	],
	mailchimp_transactional: [
		[ 'api_key', __( 'API key', 'onesmtp' ), 'password' ],
	],
	zoho_mail: [
		[
			'region',
			__(
				'Zoho region (com, in, eu, com.au, jp, ca, or com.cn)',
				'onesmtp'
			),
		],
		[ 'account_id', __( 'Zoho Mail account ID', 'onesmtp' ) ],
		[ 'client_id', __( 'OAuth client ID', 'onesmtp' ) ],
		[ 'client_secret', __( 'OAuth client secret', 'onesmtp' ), 'password' ],
		[ 'refresh_token', __( 'OAuth refresh token', 'onesmtp' ), 'password' ],
	],
	emailit: [ [ 'api_key', __( 'API key', 'onesmtp' ), 'password' ] ],
	netcore: [
		[ 'api_key', __( 'API key', 'onesmtp' ), 'password' ],
		[ 'region', __( 'API region (us or eu)', 'onesmtp' ) ],
	],
	php_mail: [],
};

const requiredConfigFields = {
	smtp: [ 'host' ],
	amazon_ses: [ 'region', 'username', 'password' ],
	gmail: [ 'client_id', 'client_secret', 'refresh_token' ],
	sendgrid: [ 'api_key' ],
	postmark: [ 'api_key' ],
	brevo: [ 'api_key' ],
	mailgun: [ 'api_key', 'domain' ],
	resend: [ 'api_key' ],
	mailjet: [ 'api_key', 'secret_key' ],
	sparkpost: [ 'api_key' ],
	mailersend: [ 'api_key' ],
	smtp2go: [ 'api_key' ],
	elastic_email: [ 'api_key' ],
	zeptomail: [ 'api_key' ],
	mailchimp_transactional: [ 'api_key' ],
	zoho_mail: [
		'region',
		'account_id',
		'client_id',
		'client_secret',
		'refresh_token',
	],
	emailit: [ 'api_key' ],
	netcore: [ 'api_key', 'region' ],
	php_mail: [],
};

const initialProviderConfig = ( type ) => {
	if ( type === 'amazon_ses' ) {
		return { region: 'us-east-1', port: 587 };
	}
	if ( type === 'smtp' ) {
		return { port: 587 };
	}
	if ( type === 'mailgun' || type === 'sparkpost' ) {
		return { region: 'us' };
	}
	if ( type === 'smtp2go' ) {
		return { region: 'global' };
	}
	if ( type === 'zoho_mail' ) {
		return { region: 'com' };
	}
	if ( type === 'netcore' ) {
		return { region: 'us' };
	}
	return {};
};

const providerQuotaFields = [
	[ 'quota_per_minute', __( 'Per-minute attempts', 'onesmtp' ) ],
	[ 'quota_per_hour', __( 'Per-hour attempts', 'onesmtp' ) ],
	[ 'quota_per_day', __( 'Per-day attempts', 'onesmtp' ) ],
];

const initialProviderQuota = () =>
	providerQuotaFields.reduce( ( quota, [ field ] ) => {
		quota[ field ] = '0';
		return quota;
	}, {} );

const normalizeProviderQuota = ( storedConfig = {} ) =>
	providerQuotaFields.reduce( ( quota, [ field ] ) => {
		const value = storedConfig?.[ field ];
		if ( value === '' || value === null || typeof value === 'undefined' ) {
			quota[ field ] = '0';
			return quota;
		}

		const number = Number( value );
		quota[ field ] = Number.isFinite( number )
			? String( Math.max( 0, Math.min( 1000000, Math.floor( number ) ) ) )
			: '0';
		return quota;
	}, {} );

const isSensitiveField = ( field ) =>
	/pass|secret|token|api(?:_|-)?key|signing|client_id/i.test( field );

const editableConfig = ( type, storedConfig = {} ) =>
	Object.entries( storedConfig ).reduce(
		( config, [ field, value ] ) => {
			if (
				value === '[REDACTED]' ||
				value === null ||
				typeof value === 'object'
			) {
				return config;
			}

			config[ field ] = value;
			return config;
		},
		{ ...initialProviderConfig( type ) }
	);

const requestConfig = ( providerConfig, isEditing ) =>
	Object.entries( providerConfig ).reduce( ( config, [ field, value ] ) => {
		if (
			isEditing &&
			isSensitiveField( field ) &&
			String( value || '' ).trim() === ''
		) {
			return config;
		}

		config[ field ] = value;
		return config;
	}, {} );

export default function ProviderInlineSettings( { config } ) {
	const type = config.type || 'smtp';
	const connections = Array.isArray( config.connections )
		? config.connections
		: [];
	const [ isOpen, setIsOpen ] = useState( false );
	const [ editingId, setEditingId ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ priority, setPriority ] = useState( 100 );
	const [ weight, setWeight ] = useState( 1 );
	const [ isActive, setIsActive ] = useState( true );
	const [ providerConfig, setProviderConfig ] = useState(
		initialProviderConfig( type )
	);
	const [ providerQuota, setProviderQuota ] = useState(
		initialProviderQuota()
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ savedId, setSavedId ] = useState( null );
	const [ testRecipient, setTestRecipient ] = useState(
		config.adminEmail || ''
	);
	const [ isTesting, setIsTesting ] = useState( false );
	const [ isConfirmingDelete, setIsConfirmingDelete ] = useState( false );
	const panelId = `onesmtp-provider-settings-${ type }`;
	const headingRef = useRef( null );
	const isEditing = editingId !== null;
	const editingConnection = connections.find(
		( connection ) => Number( connection.id ) === editingId
	);
	const hasRequiredConfig = ( requiredConfigFields[ type ] || [] ).every(
		( field ) => {
			if ( isEditing && isSensitiveField( field ) ) {
				return true;
			}

			return String( providerConfig[ field ] || '' ).trim() !== '';
		}
	);

	useEffect( () => {
		if ( isOpen && headingRef.current ) {
			headingRef.current.focus();
		}
	}, [ isOpen ] );

	const updateConfig = ( field, value ) =>
		setProviderConfig( ( current ) => ( {
			...current,
			[ field ]: value,
		} ) );
	const close = () => {
		setIsOpen( false );
		setNotice( null );
		setIsConfirmingDelete( false );
	};
	const openNewConnection = () => {
		setEditingId( null );
		setName(
			`${ config.label || __( 'Provider', 'onesmtp' ) } ${ __(
				'connection',
				'onesmtp'
			) }`
		);
		setPriority( 100 );
		setWeight( 1 );
		setIsActive( false );
		setProviderConfig( initialProviderConfig( type ) );
		setProviderQuota( initialProviderQuota() );
		setNotice( null );
		setIsConfirmingDelete( false );
		setIsOpen( true );
	};
	const openExistingConnection = ( connection ) => {
		const id = Number( connection?.id || 0 );
		if ( id <= 0 ) {
			return;
		}

		setEditingId( id );
		setName(
			connection.name ||
				`${ config.label || __( 'Provider', 'onesmtp' ) } ${ __(
					'connection',
					'onesmtp'
				) }`
		);
		setPriority( Number( connection.priority ) || 100 );
		setWeight( Number( connection.weight ) || 1 );
		setIsActive( Boolean( connection.isActive ) );
		setProviderConfig( editableConfig( type, connection.config ) );
		setProviderQuota( normalizeProviderQuota( connection.config ) );
		setNotice( null );
		setIsConfirmingDelete( false );
		setIsOpen( true );
	};
	const save = async () => {
		setIsSaving( true );
		setNotice( null );

		try {
			const configToSave = requestConfig( providerConfig, isEditing );
			if ( config.quotaEnabled ) {
				Object.assign( configToSave, providerQuota );
			}

			const response = await apiFetch( {
				url: isEditing
					? `${ config.endpoint }/${ editingId }`
					: config.endpoint,
				method: isEditing ? 'PUT' : 'POST',
				headers: { 'X-WP-Nonce': config.nonce },
				data: {
					name,
					adapter_type: type,
					priority: Number( priority ) || 100,
					weight: Number( weight ) || 1,
					is_active: isActive,
					config: configToSave,
				},
			} );
			const providerId = Number(
				response?.provider?.id || editingId || 0
			);
			setSavedId( providerId > 0 ? providerId : null );
			if ( providerId > 0 ) {
				setEditingId( providerId );
			}
			setNotice( {
				status: 'success',
				message: isActive
					? __(
							'Connection saved and active. Send a test email to verify delivery.',
							'onesmtp'
					  )
					: __(
							'Connection saved as inactive. Send a test email, then activate it for live delivery.',
							'onesmtp'
					  ),
			} );
			setIsSaving( false );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__( 'Unable to save this provider.', 'onesmtp' ),
			} );
			setIsSaving( false );
		}
	};
	const sendTest = async () => {
		if ( ! savedId || ! testRecipient.trim() ) {
			return;
		}
		setIsTesting( true );
		setNotice( null );
		try {
			const response = await apiFetch( {
				url: `${ config.endpoint }/${ savedId }/test`,
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce },
				data: { to: testRecipient.trim() },
			} );
			setNotice( {
				status: response?.ok ? 'success' : 'error',
				message:
					response?.message ||
					( response?.ok
						? __(
								'Test accepted by the provider. Check the recipient inbox before relying on live delivery.',
								'onesmtp'
						  )
						: __(
								'The provider rejected the test email.',
								'onesmtp'
						  ) ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__( 'Unable to send the test email.', 'onesmtp' ),
			} );
		} finally {
			setIsTesting( false );
		}
	};
	const deleteConnection = async () => {
		if ( ! isEditing ) {
			return;
		}

		setIsSaving( true );
		setNotice( null );

		try {
			await apiFetch( {
				url: `${ config.endpoint }/${ editingId }`,
				method: 'DELETE',
				headers: { 'X-WP-Nonce': config.nonce },
			} );
			setNotice( {
				status: 'success',
				message: __(
					'Provider connection deleted. Refreshing provider status…',
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
						'Unable to delete this provider connection.',
						'onesmtp'
					),
			} );
			setIsSaving( false );
		}
	};
	let saveButtonLabel = isEditing
		? __( 'Save changes', 'onesmtp' )
		: __( 'Connect provider', 'onesmtp' );
	if ( isSaving ) {
		saveButtonLabel = (
			<>
				<Spinner />{ ' ' }
				{ isEditing
					? __( 'Saving…', 'onesmtp' )
					: __( 'Connecting…', 'onesmtp' ) }
			</>
		);
	}

	return (
		<>
			<div className="onesmtp-provider-row-actions">
				<Button
					variant="secondary"
					aria-expanded={ isOpen }
					aria-controls={ panelId }
					onClick={ () =>
						connections.length > 0
							? openExistingConnection( connections[ 0 ] )
							: openNewConnection()
					}
				>
					{ connections.length > 0
						? __( 'Manage', 'onesmtp' )
						: __( 'Connect', 'onesmtp' ) }
				</Button>
			</div>
			{ isOpen && (
				<section
					id={ panelId }
					className="onesmtp-provider-inline-settings"
					aria-label={ __(
						'Provider connection settings',
						'onesmtp'
					) }
				>
					<div className="onesmtp-provider-inline-heading">
						<div>
							<h4 ref={ headingRef } tabIndex="-1">
								{ isEditing
									? `${ __( 'Edit', 'onesmtp' ) } ${
											name || config.label
									  }`
									: `${ __( 'Connect', 'onesmtp' ) } ${
											config.label
									  }` }
							</h4>
							<p>
								{ isEditing
									? __(
											'Update this connection without exposing its stored credentials.',
											'onesmtp'
									  )
									: config.description }
							</p>
						</div>
					</div>
					{ notice && (
						<Notice
							status={ notice.status }
							isDismissible={ false }
						>
							{ notice.message }
						</Notice>
					) }
					{ isEditing &&
						editingConnection?.credentialRecoveryRequired && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'This connection needs updated credentials before it can deliver email.',
									'onesmtp'
								) }
							</Notice>
						) }
					{ isEditing && connections.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Connection', 'onesmtp' ) }
							value={ String( editingId ) }
							options={ connections.map( ( connection ) => ( {
								label:
									connection.name ||
									`${ config.label } #${ connection.id }`,
								value: String( connection.id ),
							} ) ) }
							onChange={ ( id ) =>
								openExistingConnection(
									connections.find(
										( connection ) =>
											String( connection.id ) === id
									)
								)
							}
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Connection name', 'onesmtp' ) }
						value={ name }
						onChange={ setName }
						required
						help={ __(
							'Use a clear internal name, such as “Primary transactional”.',
							'onesmtp'
						) }
					/>
					{ ( fieldsByType[ type ] || [] ).map(
						( [ field, label, inputType = 'text' ] ) =>
							inputType === 'number' ? (
								<TextControl
									__next40pxDefaultSize
									key={ field }
									label={ label }
									type="number"
									value={ providerConfig[ field ] || '' }
									min={ 1 }
									onChange={ ( value ) =>
										updateConfig( field, value )
									}
								/>
							) : (
								<TextControl
									__next40pxDefaultSize
									key={ field }
									label={ label }
									type={ inputType }
									value={ providerConfig[ field ] || '' }
									onChange={ ( value ) =>
										updateConfig( field, value )
									}
									required={
										! isEditing &&
										(
											requiredConfigFields[ type ] || []
										).includes( field )
									}
									help={
										isEditing && isSensitiveField( field )
											? __(
													'Leave blank to keep the stored credential.',
													'onesmtp'
											  )
											: undefined
									}
								/>
							)
					) }
					{ type === 'amazon_ses' && (
						<p className="onesmtp-provider-field-note">
							{ __(
								'Use the SMTP credentials generated by Amazon SES for this AWS Region, not your regular AWS access keys. Aculect Mail will use port 587 with TLS.',
								'onesmtp'
							) }
						</p>
					) }
					{ type !== 'php_mail' && (
						<>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'From email', 'onesmtp' ) }
								type="email"
								value={ providerConfig.from_email || '' }
								onChange={ ( value ) =>
									updateConfig( 'from_email', value )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'From name', 'onesmtp' ) }
								value={ providerConfig.from_name || '' }
								onChange={ ( value ) =>
									updateConfig( 'from_name', value )
								}
							/>
						</>
					) }
					<div className="onesmtp-provider-drawer-options">
						<TextControl
							__next40pxDefaultSize
							type="number"
							label={ __( 'Priority', 'onesmtp' ) }
							value={ priority }
							min={ 1 }
							onChange={ setPriority }
							help={ __(
								'Lower values are selected first.',
								'onesmtp'
							) }
						/>
						<TextControl
							__next40pxDefaultSize
							type="number"
							label={ __( 'Weight', 'onesmtp' ) }
							value={ weight }
							min={ 1 }
							onChange={ setWeight }
							help={ __(
								'Used when providers share a priority.',
								'onesmtp'
							) }
						/>
					</div>
					{ config.quotaEnabled ? (
						<fieldset
							className="onesmtp-provider-quota"
							aria-describedby="onesmtp-provider-quota-help"
						>
							<legend>
								{ __( 'Provider sending budget', 'onesmtp' ) }
							</legend>
							<p
								id="onesmtp-provider-quota-help"
								className="description"
							>
								{ __(
									'Count production send attempts for this provider across bounded windows. Enter 0 to disable a window; values above 1,000,000 are safely clamped.',
									'onesmtp'
								) }
							</p>
							{ providerQuotaFields.map( ( [ field, label ] ) => (
								<TextControl
									__next40pxDefaultSize
									key={ field }
									label={ label }
									type="number"
									min={ 0 }
									max={ 1000000 }
									value={ providerQuota[ field ] }
									help={ __(
										'0 disables this window.',
										'onesmtp'
									) }
									onChange={ ( value ) =>
										setProviderQuota( ( current ) => ( {
											...current,
											[ field ]: value,
										} ) )
									}
								/>
							) ) }
						</fieldset>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Per-provider sending budgets are available with Pro and remain disabled on this installation.',
								'onesmtp'
							) }
						</Notice>
					) }
					<ToggleControl
						label={ __( 'Activate for delivery', 'onesmtp' ) }
						checked={ isActive }
						onChange={ setIsActive }
					/>
					{ savedId && (
						<div className="onesmtp-provider-test-step">
							<h5>{ __( 'Test email', 'onesmtp' ) }</h5>
							<p>
								{ __(
									'Verify the saved connection without leaving this provider row.',
									'onesmtp'
								) }
							</p>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Send test to', 'onesmtp' ) }
								type="email"
								value={ testRecipient }
								onChange={ setTestRecipient }
							/>
							<Button
								variant="secondary"
								onClick={ sendTest }
								disabled={ isTesting || ! testRecipient.trim() }
							>
								{ isTesting ? (
									<>
										<Spinner />{ ' ' }
										{ __( 'Sending…', 'onesmtp' ) }
									</>
								) : (
									__( 'Send test email', 'onesmtp' )
								) }
							</Button>
						</div>
					) }
					<div className="onesmtp-drawer-actions">
						{ isEditing && (
							<Button
								className="onesmtp-provider-delete-button"
								variant="tertiary"
								isDestructive
								onClick={ () => setIsConfirmingDelete( true ) }
								disabled={ isSaving }
							>
								{ __( 'Delete connection', 'onesmtp' ) }
							</Button>
						) }
						<span className="onesmtp-provider-drawer-actions-spacer" />
						<Button
							variant="secondary"
							onClick={ close }
							disabled={ isSaving }
						>
							{ __( 'Cancel', 'onesmtp' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ save }
							disabled={
								isSaving || ! name.trim() || ! hasRequiredConfig
							}
						>
							{ saveButtonLabel }
						</Button>
					</div>
					{ isConfirmingDelete && (
						<Notice
							status="warning"
							isDismissible={ false }
							actions={ [
								{
									label: __( 'Keep connection', 'onesmtp' ),
									onClick: () =>
										setIsConfirmingDelete( false ),
								},
								{
									label: __(
										'Delete permanently',
										'onesmtp'
									),
									isDestructive: true,
									onClick: deleteConnection,
								},
							] }
						>
							{ __(
								'Delete this provider connection? This cannot be undone.',
								'onesmtp'
							) }
						</Notice>
					) }
				</section>
			) }
		</>
	);
}
