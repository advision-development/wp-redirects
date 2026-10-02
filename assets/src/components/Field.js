import { useId } from '@wordpress/element';
import { describedFieldProps } from '../utils/a11y';

/**
 * Wraps a form control and its inline error.
 *
 * Pass a function as children to receive the ARIA props (id, aria-invalid,
 * aria-describedby) that link the control to the error message.
 *
 * @param {Object}                            props
 * @param {string}                            props.error     Error text, if any.
 * @param {boolean}                           props.hasHelp   Whether the control shows help text.
 * @param {string}                            props.control   'text' (default) or 'select'.
 * @param {(Object|function(Object): Object)} props.children  Control, or a render function.
 * @param {string}                            props.className Extra class names.
 */
export default function Field( {
	error,
	hasHelp = false,
	control = 'text',
	children,
	className = '',
} ) {
	const id = useId();
	const errorId = `${ id }-error`;
	const controlId = `${ id }-control`;
	// SelectControl always sets its own aria-describedby, so a select points
	// at its error through aria-errormessage instead.
	const controlProps =
		control === 'select'
			? {
					id: controlId,
					'aria-invalid': error ? true : undefined,
					'aria-errormessage': error ? errorId : undefined,
				}
			: describedFieldProps( {
					id: controlId,
					errorId,
					error,
					hasHelp,
				} );

	return (
		<div
			className={ `adv-redirects-field ${ className }${ error ? ' has-error' : '' }` }
		>
			{ typeof children === 'function'
				? children( controlProps )
				: children }
			{ error && (
				<p
					id={ errorId }
					className="adv-redirects-field__error"
					role="alert"
				>
					{ error }
				</p>
			) }
		</div>
	);
}
