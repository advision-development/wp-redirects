import { dateI18n } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

// "manual" is the default and has no label.
export const VIA_LABELS = {
	import: __( 'Imported', 'wp-redirects' ),
	slug: __( 'Slug change', 'wp-redirects' ),
	api: __( 'API', 'wp-redirects' ),
};

/**
 * Formats a GMT MySQL datetime ("2026-10-02 15:03:00") in the site's time zone.
 *
 * @param {string} value GMT datetime.
 * @return {string} Formatted date, or '' for an empty value.
 */
export const formatGmt = ( value ) =>
	value
		? dateI18n( 'M j, Y g:i A', String( value ).replace( ' ', 'T' ) + 'Z' )
		: '';

/**
 * "By: user · date", or "Added date" for rules that predate attribution.
 *
 * @param {Object}                    rule   Rule from the REST API.
 * @param {(value: string) => string} format Date formatter, injectable for tests.
 * @return {string} Line of plain text.
 */
export function createdLine( rule, format = formatGmt ) {
	const date = format( rule.created_at );
	if ( rule.created_by_name ) {
		return sprintf(
			/* translators: 1: user name, 2: date and time the redirect was created */
			__( 'By: %1$s · %2$s', 'wp-redirects' ),
			rule.created_by_name,
			date
		);
	}
	return sprintf(
		/* translators: %s: date and time the redirect was created */
		__( 'Added %s', 'wp-redirects' ),
		date
	);
}

/**
 * "Last edited by user on date", or '' when the rule was never edited by a known user.
 *
 * @param {Object}                    rule   Rule from the REST API.
 * @param {(value: string) => string} format Date formatter, injectable for tests.
 * @return {string} Line of plain text, possibly empty.
 */
export function editedLine( rule, format = formatGmt ) {
	if ( ! rule.updated_by_name || rule.updated_at === rule.created_at ) {
		return '';
	}
	return sprintf(
		/* translators: 1: user name, 2: date and time of the last edit */
		__( 'Last edited by %1$s on %2$s', 'wp-redirects' ),
		rule.updated_by_name,
		format( rule.updated_at )
	);
}
