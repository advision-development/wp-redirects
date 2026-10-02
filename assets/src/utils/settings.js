export function parseExtensions( text ) {
	const seen = new Set();
	return String( text )
		.split( ',' )
		.map( ( value ) => value.trim().replace( /^\.+/, '' ).toLowerCase() )
		.filter(
			( value ) =>
				/^[a-z0-9]{1,10}$/.test( value ) &&
				! seen.has( value ) &&
				seen.add( value )
		);
}

export const formatExtensions = ( list ) => ( list || [] ).join( ', ' );

export const SETTINGS_FIELDS = [
	'log_404_retention_days',
	'log_404_max_rows',
	'excluded_404_extensions',
];

/**
 * Splits a failed settings save into inline field errors and anything else.
 *
 * @param {Object} error REST error (code, message, data.params).
 * @return {{fields: Object<string,string>, general: boolean}} Messages per field, and whether a non-field error remains for a snackbar.
 */
export function splitSettingsErrors( error ) {
	const params =
		error && error.code === 'rest_invalid_param' && error.data
			? error.data.params
			: null;
	if ( ! params ) {
		return { fields: {}, general: true };
	}
	const fields = {};
	let general = false;
	Object.entries( params ).forEach( ( [ key, message ] ) => {
		if ( SETTINGS_FIELDS.includes( key ) && message ) {
			fields[ key ] = String( message );
		} else {
			general = true;
		}
	} );
	return { fields, general: general || ! Object.keys( fields ).length };
}
