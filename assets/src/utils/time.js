import { __, _n, sprintf } from '@wordpress/i18n';

export function parseGmt( value ) {
	if ( ! value ) {
		return null;
	}
	const date = new Date( String( value ).replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( date.getTime() ) ? null : date;
}

export function timeAgo( value, now = new Date() ) {
	const date = parseGmt( value );
	if ( ! date ) {
		return __( 'Never', 'wp-redirects' );
	}
	const seconds = Math.max( 0, Math.floor( ( now - date ) / 1000 ) );
	if ( seconds < 60 ) {
		return __( 'Just now', 'wp-redirects' );
	}
	const minutes = Math.floor( seconds / 60 );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes */
			_n( '%d minute ago', '%d minutes ago', minutes, 'wp-redirects' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: number of hours */
			_n( '%d hour ago', '%d hours ago', hours, 'wp-redirects' ),
			hours
		);
	}
	const days = Math.floor( hours / 24 );
	if ( days < 30 ) {
		return sprintf(
			/* translators: %d: number of days */
			_n( '%d day ago', '%d days ago', days, 'wp-redirects' ),
			days
		);
	}
	return date.toLocaleDateString();
}
