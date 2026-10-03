import { __ } from '@wordpress/i18n';

export const STATUS_OPTIONS = [
	{ value: 301, label: __( '301 Moved Permanently', 'wp-redirects' ) },
	{ value: 302, label: __( '302 Found', 'wp-redirects' ) },
	{ value: 307, label: __( '307 Temporary Redirect', 'wp-redirects' ) },
	{ value: 308, label: __( '308 Permanent Redirect', 'wp-redirects' ) },
	{ value: 410, label: __( '410 Content Deleted', 'wp-redirects' ) },
	{
		value: 451,
		label: __( '451 Unavailable For Legal Reasons', 'wp-redirects' ),
	},
];

export const isGone = ( status ) => [ 410, 451 ].includes( Number( status ) );

export function statusTone( status ) {
	if ( isGone( status ) ) {
		return 'gone';
	}
	return [ 301, 308 ].includes( Number( status ) )
		? 'permanent'
		: 'temporary';
}

const FIELD_BY_CODE = {
	adv_redirects_invalid_source: 'source',
	adv_redirects_invalid_regex: 'source',
	adv_redirects_duplicate: 'source',
	adv_redirects_reserved_source: 'source',
	adv_redirects_invalid_target: 'target',
	adv_redirects_loop: 'target',
	adv_redirects_invalid_status: 'status_code',
};

const FORM_FIELDS = [ 'source', 'target', 'status_code' ];

export function fieldForError( error ) {
	if ( ! error ) {
		return 'form';
	}
	if ( FIELD_BY_CODE[ error.code ] ) {
		return FIELD_BY_CODE[ error.code ];
	}
	if (
		error.code === 'rest_invalid_param' &&
		error.data &&
		error.data.params
	) {
		const key = Object.keys( error.data.params ).find( ( name ) =>
			FORM_FIELDS.includes( name )
		);
		if ( key ) {
			return key;
		}
	}
	return 'form';
}

export function errorMessage( error ) {
	if (
		error &&
		error.code === 'rest_invalid_param' &&
		error.data &&
		error.data.params
	) {
		const first = Object.values( error.data.params )[ 0 ];
		if ( first ) {
			return String( first );
		}
	}
	return (
		( error && error.message ) ||
		__( 'Something went wrong. Please try again.', 'wp-redirects' )
	);
}

export const ruleToPayload = ( rule ) => ( {
	type: rule.type,
	source: rule.source,
	target: rule.target,
	status_code: rule.status_code,
	enabled: rule.enabled,
	// Undefined (an older rule shape) is dropped from the JSON body, so the server default applies.
	trailing_slash: rule.trailing_slash,
	note: rule.note,
} );
