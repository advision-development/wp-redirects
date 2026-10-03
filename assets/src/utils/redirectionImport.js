import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../constants';

export const MAX_PREVIEW = 5000;
export const BATCH_SIZE = 50;
const MAX_ERRORS = 5;

const KEEP_FIELDS = [
	'id',
	'url',
	'action_code',
	'action_type',
	'match_type',
	'title',
	'regex',
	'group_id',
	'position',
	'enabled',
];

// The only match_data keys the server reads.
const SOURCE_FLAGS = [
	'flag_case',
	'flag_query',
	'flag_regex',
	'flag_trailing',
];

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );
const isNumberLike = ( value ) =>
	Number.isInteger( value ) ||
	( typeof value === 'string' && /^\d+$/.test( value ) );

const ENTRY_FIELDS = [
	[
		'url',
		( v ) => typeof v === 'string' && v.trim() !== '',
		() => __( 'a non-empty string', 'wp-redirects' ),
	],
	[
		'regex',
		( v ) => typeof v === 'boolean',
		() => __( 'true or false', 'wp-redirects' ),
	],
	[
		'action_type',
		( v ) => typeof v === 'string',
		() => __( 'a string', 'wp-redirects' ),
	],
	[ 'action_code', isNumberLike, () => __( 'a number', 'wp-redirects' ) ],
	[
		'action_data',
		( v ) => v === null || typeof v === 'object',
		() => __( 'an object or null', 'wp-redirects' ),
	],
	[
		'match_type',
		( v ) => typeof v === 'string',
		() => __( 'a string', 'wp-redirects' ),
	],
	[
		'enabled',
		( v ) => typeof v === 'boolean',
		() => __( 'true or false', 'wp-redirects' ),
	],
	[ 'group_id', isNumberLike, () => __( 'a number', 'wp-redirects' ) ],
];

const fail = ( errors ) => ( {
	ok: false,
	errors: errors.slice( 0, MAX_ERRORS ),
	summary: null,
} );

export function checkRedirectionExport( data ) {
	if (
		! isObject( data ) ||
		! isObject( data.plugin ) ||
		typeof data.plugin.version !== 'string'
	) {
		return fail( [
			__(
				'This file is not a Redirection export: "plugin.version" is missing.',
				'wp-redirects'
			),
		] );
	}
	if ( ! Array.isArray( data.redirects ) ) {
		return fail( [
			__( 'This file has no "redirects" list.', 'wp-redirects' ),
		] );
	}
	if ( data.redirects.length === 0 ) {
		return fail( [
			__( 'The export contains no redirects.', 'wp-redirects' ),
		] );
	}
	if ( data.redirects.length > MAX_PREVIEW ) {
		return fail( [
			sprintf(
				/* translators: 1: number of redirects in the file, 2: maximum per import */
				__(
					'This file has %1$d redirects; the maximum per import is %2$d.',
					'wp-redirects'
				),
				data.redirects.length,
				MAX_PREVIEW
			),
		] );
	}

	const errors = [];
	if (
		data.groups !== undefined &&
		( ! Array.isArray( data.groups ) ||
			data.groups.some(
				( group ) =>
					! isObject( group ) ||
					! isNumberLike( group.id ) ||
					typeof group.name !== 'string'
			) )
	) {
		errors.push(
			__(
				'"groups" must be a list of objects with an id and a name.',
				'wp-redirects'
			)
		);
	}

	data.redirects.forEach( ( entry, index ) => {
		if ( errors.length >= MAX_ERRORS ) {
			return;
		}
		if ( ! isObject( entry ) ) {
			errors.push(
				sprintf(
					/* translators: %d: entry number */
					__( 'Entry %d is not an object.', 'wp-redirects' ),
					index + 1
				)
			);
			return;
		}
		const broken = ENTRY_FIELDS.find(
			( [ field, test ] ) => ! test( entry[ field ] )
		);
		if ( broken ) {
			errors.push(
				sprintf(
					/* translators: 1: entry number, 2: field name, 3: expected type */
					__( 'Entry %1$d: "%2$s" must be %3$s.', 'wp-redirects' ),
					index + 1,
					broken[ 0 ],
					broken[ 2 ]()
				)
			);
		}
	} );

	if ( errors.length ) {
		return fail( errors );
	}

	const regex = data.redirects.filter( ( entry ) => entry.regex ).length;
	return {
		ok: true,
		errors: [],
		summary: {
			version: data.plugin.version,
			date: typeof data.plugin.date === 'string' ? data.plugin.date : '',
			total: data.redirects.length,
			exact: data.redirects.length - regex,
			regex,
			logs: Array.isArray( data.logs ) ? data.logs.length : 0,
			errors404: Array.isArray( data.errors_404 )
				? data.errors_404.length
				: 0,
		},
	};
}

/**
 * Reduces match_data and action_data to what the server reads. Conditional
 * rules (login, IP, agent, cookie, header...) keep their IPs, user-agent
 * patterns and cookie names in action_data and match_data, so none of it is
 * sent: only a redirect's target url and the source flags.
 *
 * @param {Object} entry One raw Redirection entry.
 * @return {Object} The entry's `match_data` and `action_data`, reduced.
 */
function reducedData( entry ) {
	const reduced = {};
	if ( 'match_data' in entry ) {
		const source = isObject( entry.match_data )
			? entry.match_data.source
			: null;
		reduced.match_data = {
			source: Object.fromEntries(
				SOURCE_FLAGS.filter(
					( flag ) => isObject( source ) && flag in source
				).map( ( flag ) => [ flag, source[ flag ] ] )
			),
		};
	}
	if ( 'action_data' in entry ) {
		reduced.action_data =
			isObject( entry.action_data ) &&
			typeof entry.action_data.url === 'string'
				? { url: entry.action_data.url }
				: null;
	}
	return reduced;
}

export function stripExport( data ) {
	const redirects = data.redirects
		.map( ( entry, order ) => ( {
			order,
			entry: {
				...Object.fromEntries(
					KEEP_FIELDS.filter( ( field ) => field in entry ).map(
						( field ) => [ field, entry[ field ] ]
					)
				),
				...reducedData( entry ),
			},
		} ) )
		.sort(
			( a, b ) =>
				( Number( a.entry.position ) || 0 ) -
					( Number( b.entry.position ) || 0 ) || a.order - b.order
		)
		.map( ( item ) => item.entry );

	return {
		source: 'redirection',
		version: data.plugin.version,
		groups: ( Array.isArray( data.groups ) ? data.groups : [] ).map(
			( group ) => ( {
				id: Number( group.id ),
				name: group.name,
				// Redirection skips every rule in a disabled group.
				...( typeof group.status === 'string' && {
					status: group.status,
				} ),
				...( typeof group.enabled === 'boolean' && {
					enabled: group.enabled,
				} ),
			} )
		),
		redirects,
	};
}

/**
 * True when the server (or a proxy in front of it) refused the request body as
 * too large.
 *
 * @param {Object|null} error What apiFetch rejected with.
 * @return {boolean} Whether the upload was too large.
 */
export function isTooLarge( error ) {
	if ( ! error ) {
		return false;
	}
	return (
		error.status === 413 ||
		( error.data && error.data.status === 413 ) ||
		error.code === 'rest_request_too_large'
	);
}

/**
 * True when the request got no response at all. apiFetch turns the TypeError
 * a dropped connection raises into a `fetch_error`. Some servers reset the
 * connection instead of answering 413, so this can also mean "too large".
 *
 * @param {Object|null} error What apiFetch rejected with.
 * @return {boolean} Whether no response arrived.
 */
export function isNoResponse( error ) {
	if ( ! error ) {
		return false;
	}
	return error.code === 'fetch_error' || error instanceof TypeError;
}

export function importErrorMessage( error ) {
	if ( isTooLarge( error ) ) {
		return __(
			'The server rejected the upload as too large. Split the export into smaller files and import them one at a time.',
			'wp-redirects'
		);
	}
	if ( isNoResponse( error ) ) {
		return __(
			'The server did not respond. Check your connection and try again. If it keeps failing, the file may be too large for the server: split the export into smaller files and import them one at a time.',
			'wp-redirects'
		);
	}
	return errorMessage( error );
}

export function chunk( items, size ) {
	const out = [];
	for ( let i = 0; i < items.length; i += size ) {
		out.push( items.slice( i, i + size ) );
	}
	return out;
}

export function importableEntries( preview, redirects ) {
	return preview.entries
		.filter(
			( entry ) => entry.status === 'new' || entry.status === 'overwrite'
		)
		.map( ( entry ) => ( {
			index: entry.index,
			entry: redirects[ entry.index ],
		} ) );
}

export function groupPreview( preview ) {
	const groups = {
		new: [],
		overwrite: [],
		warnings: [],
		skipped: [],
		superseded: [],
	};
	preview.entries.forEach( ( entry ) => {
		groups[ entry.status ].push( entry );
		if ( entry.warnings && entry.warnings.length ) {
			groups.warnings.push( entry );
		}
	} );
	return groups;
}

export const NOTE_LABELS = {
	case_insensitive: __(
		'Imported as case-insensitive (WP Redirects always ignores case).',
		'wp-redirects'
	),
	case_sensitive_source: __(
		'Yoast matched this pattern case-sensitively; WP Redirects matches regardless of case.',
		'wp-redirects'
	),
	trailing_slash_ignored: __(
		'A trailing slash is ignored when matching.',
		'wp-redirects'
	),
	query_mode: __(
		'Query strings follow WP Redirects rules: matched only when the source has one, forwarded per Settings.',
		'wp-redirects'
	),
	regex_query: __(
		'Regex rules match the path only, so the query part of this pattern will not match.',
		'wp-redirects'
	),
	group_disabled: __(
		'Its Redirection group is disabled, so it is imported disabled.',
		'wp-redirects'
	),
};

export function buildReport( { summary, preview, importSkipped = [] } ) {
	return {
		generated: new Date().toISOString(),
		file: {
			plugin: summary.plugin || 'redirection',
			version: summary.version,
			date: summary.date,
		},
		skipped: [
			...preview.entries
				.filter( ( entry ) => entry.status === 'skipped' )
				.map( ( entry ) => ( {
					index: entry.index,
					source: entry.source,
					code: entry.error.code,
					message: entry.error.message,
					stage: 'preview',
				} ) ),
			...importSkipped.map( ( item ) => ( {
				index: item.index,
				source: item.source,
				code: item.error ? item.error.code : 'unknown',
				message: item.error ? item.error.message : '',
				stage: 'import',
			} ) ),
		],
		superseded: preview.entries
			.filter( ( entry ) => entry.status === 'superseded' )
			.map( ( entry ) => ( {
				index: entry.index,
				source: entry.source,
				superseded_by: entry.superseded_by,
				message: entry.error ? entry.error.message : undefined,
			} ) ),
	};
}
