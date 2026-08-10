const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const {
	filterProviderConfig,
} = require( '../../../src/Admin/modules/providerConfigFilter' );

test( 'removes the Mailgun signing key when provider events are disabled', () => {
	assert.deepEqual(
		filterProviderConfig(
			{
				api_key: 'fixture-api-key',
				webhook_signing_key: 'fixture-signing-key',
				domain: 'example.test',
			},
			false
		),
		{
			api_key: 'fixture-api-key',
			domain: 'example.test',
		}
	);
} );

test( 'retains the Mailgun signing key when provider events are enabled', () => {
	assert.equal(
		filterProviderConfig(
			{ webhook_signing_key: 'fixture-signing-key' },
			true
		).webhook_signing_key,
		'fixture-signing-key'
	);
} );
