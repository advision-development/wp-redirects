import { __ } from '@wordpress/i18n';

export const REMOVE_CHUNK = 500;
export const NOTICE_KEY = 'adv_redirects_yoast_notice_hidden';

const SERVER_FILE_MODES = [ 'htaccess', 'apache_file', 'nginx' ];

export function yoastPayload( entries ) {
	return { source: 'yoast', redirects: entries };
}

/**
 * The body for one /import batch: Redirection batches carry the groups, Yoast batches don't.
 *
 * @param {Object}   payload   The preview payload.
 * @param {Object[]} redirects Raw entries for this batch.
 * @return {Object} Request body.
 */
export function batchPayload( payload, redirects ) {
	if ( payload.source === 'yoast' ) {
		return { source: 'yoast', redirects };
	}
	return { source: payload.source, groups: payload.groups, redirects };
}

export function entrySource( entry ) {
	if ( ! entry ) {
		return '';
	}
	if ( typeof entry.origin === 'string' ) {
		return entry.origin;
	}
	return typeof entry.url === 'string' ? entry.url : '';
}

/**
 * What to remove from Yoast after an import: the entries the import confirmed as created or
 * updated, plus the superseded ones (their winner now answers those URLs). The server still
 * checks each one against the stored rules before removing it.
 *
 * @param {Object}   preview         Preview response.
 * @param {Object[]} redirects       The entries the preview was built from.
 * @param {number[]} importedIndexes Preview indexes the import reported as created or updated.
 * @return {Array<{origin: string, format: string}>} Items for /import/yoast/remove.
 */
export function removalCandidates( preview, redirects, importedIndexes ) {
	const imported = new Set( importedIndexes );
	return preview.entries
		.filter(
			( entry ) =>
				imported.has( entry.index ) || entry.status === 'superseded'
		)
		.map( ( entry ) => redirects[ entry.index ] )
		.filter( Boolean )
		.map( ( entry ) => ( { origin: entry.origin, format: entry.format } ) );
}

export function noticeHidden() {
	try {
		return window.localStorage.getItem( NOTICE_KEY ) === '1';
	} catch {
		return false;
	}
}

export function hideNotice() {
	try {
		window.localStorage.setItem( NOTICE_KEY, '1' );
	} catch {
		// Storage blocked: the notice comes back next visit.
	}
}

export function serverModeWarning( status ) {
	if ( ! status || ! SERVER_FILE_MODES.includes( status.server_mode ) ) {
		return '';
	}
	return status.premium_active
		? __(
				'Yoast writes these redirects to your server configuration. Removing them updates that file; nginx needs a reload to pick it up.',
				'wp-redirects'
			)
		: __(
				"Yoast's redirects are still in your server configuration and keep working until that block is removed. WP Redirects does not edit server files.",
				'wp-redirects'
			);
}
