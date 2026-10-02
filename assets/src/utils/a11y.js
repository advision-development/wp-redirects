/**
 * Joins element ids for aria-describedby, skipping empty values.
 *
 * @param {...(string|false|null|undefined)} ids Candidate ids.
 * @return {string|undefined} Space-separated ids, or undefined when none.
 */
export function joinIds( ...ids ) {
	const list = ids.filter( Boolean );
	return list.length ? list.join( ' ' ) : undefined;
}

/**
 * ARIA props that tie a text input to its help text and its error message.
 *
 * TextControl sets aria-describedby to its help id itself, but a passed
 * aria-describedby replaces it, so the help id is merged back in here.
 *
 * @param {Object}  args
 * @param {string}  args.id      The input id passed to the control.
 * @param {string}  args.errorId Id of the rendered error message.
 * @param {string}  args.error   Current error text, if any.
 * @param {boolean} args.hasHelp Whether the control renders help text.
 * @return {Object} Props to spread onto the control.
 */
export function describedFieldProps( { id, errorId, error, hasHelp } ) {
	return {
		id,
		'aria-invalid': error ? true : undefined,
		'aria-describedby': joinIds(
			hasHelp && `${ id }__help`,
			error && errorId
		),
	};
}

/**
 * Whether a Test URL result no longer describes what is in the input.
 *
 * @param {string|null} testedPath The path that produced the result.
 * @param {string}      value      The current input text.
 * @return {boolean} True when the input changed after the test ran.
 */
export const isStaleTest = ( testedPath, value ) =>
	testedPath !== null && String( value ).trim() !== testedPath;
