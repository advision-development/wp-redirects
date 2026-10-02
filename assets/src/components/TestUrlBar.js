import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import StatusBadge from './StatusBadge';

function TestResult( { result } ) {
	if ( ! result.matched ) {
		return (
			<span>
				{ result.reason === 'external'
					? __( 'That URL is on another site.', 'wp-redirects' )
					: __( 'No redirect matches this URL.', 'wp-redirects' ) }
			</span>
		);
	}
	if ( result.blocked ) {
		return (
			<span className="is-error">
				{ sprintf(
					/* translators: %d: rule ID */
					__(
						'Rule #%d matches, but its target was blocked as unsafe.',
						'wp-redirects'
					),
					result.rule_id
				) }
			</span>
		);
	}
	if ( ! result.target_url ) {
		return (
			<span>
				<StatusBadge status={ result.status } />{ ' ' }
				{ sprintf(
					/* translators: 1: rule ID, 2: HTTP status */
					__( 'Rule #%1$d responds with %2$d.', 'wp-redirects' ),
					result.rule_id,
					result.status
				) }
			</span>
		);
	}
	return (
		<span>
			<StatusBadge status={ result.status } />{ ' ' }
			<code>{ result.hops.join( ' → ' ) }</code>
			{ result.loop && (
				<strong className="is-error">
					{ ' ' }
					{ __( 'Loop detected', 'wp-redirects' ) }
				</strong>
			) }
		</span>
	);
}

export default function TestUrlBar( { value, onChange, onResult } ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const run = async ( event ) => {
		event.preventDefault();
		const path = value.trim();
		if ( ! path ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const response = await api.testUrl( path );
			setResult( response );
			onResult( response.matched ? response.rule_id : null );
		} catch ( requestError ) {
			setResult( null );
			setError( errorMessage( requestError ) );
			onResult( null );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form className="adv-redirects-test" onSubmit={ run } role="search">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Test a URL', 'wp-redirects' ) }
				placeholder="/old-page"
				value={ value }
				onChange={ ( next ) => {
					onChange( next );
					if ( ! next ) {
						setResult( null );
						onResult( null );
					}
				} }
			/>
			<Button
				variant="secondary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
				__next40pxDefaultSize
			>
				{ __( 'Test', 'wp-redirects' ) }
			</Button>
			<div className="adv-redirects-test__result" aria-live="polite">
				{ error && <span className="is-error">{ error }</span> }
				{ result && <TestResult result={ result } /> }
			</div>
		</form>
	);
}
