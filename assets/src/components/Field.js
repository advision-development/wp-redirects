export default function Field( { error, children, className = '' } ) {
	return (
		<div
			className={ `adv-redirects-field ${ className }${ error ? ' has-error' : '' }` }
		>
			{ children }
			{ error && (
				<p className="adv-redirects-field__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
