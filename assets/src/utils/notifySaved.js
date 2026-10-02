import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../constants';

export function notifySaved( result, { notify, onUpdate, message } ) {
	const chain = ( result.warnings || [] ).find(
		( warning ) => warning.code === 'chain'
	);
	if ( ! chain ) {
		notify( { message } );
		return;
	}
	notify( {
		message: sprintf(
			/* translators: %s: redirect hops, e.g. "/a → /b → /c" */
			__( 'Saved, but this creates a chain: %s', 'wp-redirects' ),
			chain.hops.join( ' → ' )
		),
		actions: [
			{
				label: sprintf(
					/* translators: %s: final destination path */
					__( 'Point directly to %s', 'wp-redirects' ),
					chain.final
				),
				onClick: () =>
					onUpdate( result.rule.id, { target: chain.final } )
						.then( () =>
							notify( {
								message: __(
									'Redirect now points to the final destination.',
									'wp-redirects'
								),
							} )
						)
						.catch( ( error ) =>
							notify( {
								status: 'error',
								message: errorMessage( error ),
							} )
						),
			},
		],
	} );
}
