/**
 * Keep provider-event configuration behind the explicit Pro gate.
 *
 * @param {Record<string, unknown>} config
 * @param {boolean}                 providerEventsEnabled
 * @return {Record<string, unknown>} Gate-filtered provider configuration.
 */
function filterProviderConfig( config, providerEventsEnabled ) {
	return Object.entries( config ).reduce( ( filtered, [ field, value ] ) => {
		if ( field === 'webhook_signing_key' && ! providerEventsEnabled ) {
			return filtered;
		}

		filtered[ field ] = value;
		return filtered;
	}, {} );
}

module.exports = { filterProviderConfig };
