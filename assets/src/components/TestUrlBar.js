import { Button, TextControl } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { isStaleTest } from '../utils/a11y';
import StatusBadge from './StatusBadge';

/**
 * Scrolls to the matched rule's row and focuses it.
 *
 * @param {number} ruleId Rule ID.
 * @return {boolean} False when the row is not in the table right now.
 */
function showRule( ruleId ) {
	const row = document.getElementById( `adv-redirects-rule-${ ruleId }` );
	if ( ! row ) {
		return false;
	}
	row.scrollIntoView( { block: 'center' } );
	// The Edit button, or the first field when the row is being edited.
	(
		row.querySelector( '.adv-redirects-col-actions button' ) ||
		row.querySelector( 'input, select, button' )
	)?.focus();
	return true;
}

function MatchedRule( { result, rule, onReveal } ) {
	const [ hidden, setHidden ] = useState( false );
	if ( ! rule ) {
		return null;
	}

	const show = () => {
		if ( showRule( result.rule_id ) ) {
			setHidden( false );
			return;
		}
		// Not rendered: ask the table to page to it, then look again once it
		// has re-rendered. Only a rule that is still missing is "hidden".
		onReveal( result.rule_id );
		let tries = 0;
		const retry = () => {
			if ( showRule( result.rule_id ) ) {
				setHidden( false );
			} else if ( ++tries < 3 ) {
				window.requestAnimationFrame( retry );
			} else {
				setHidden( true );
			}
		};
		window.requestAnimationFrame( retry );
	};
	return (
		<span className="adv-redirects-test__rule">
			{ rule.type === 'regex'
				? __( 'Matched regex redirect:', 'wp-redirects' )
				: __( 'Matched exact redirect:', 'wp-redirects' ) }{ ' ' }
			<code>{ rule.source }</code>{ ' ' }
			<Button variant="link" onClick={ show }>
				{ __( 'Show rule', 'wp-redirects' ) }
			</Button>
			{ hidden && (
				<span>
					{ __(
						'It is hidden by the table search or filters. Clear them to see it.',
						'wp-redirects'
					) }
				</span>
			) }
		</span>
	);
}

function TestResult( { result, rules, onReveal } ) {
	if ( ! result.matched ) {
		const messages = {
			external: __( 'That URL is on another site.', 'wp-redirects' ),
			reserved: __(
				'Not redirected: this path is used by WordPress itself.',
				'wp-redirects'
			),
		};
		return (
			<span>
				{ messages[ result.reason ] ||
					__( 'No redirect matches this URL.', 'wp-redirects' ) }
			</span>
		);
	}
	const rule = rules.find( ( item ) => item.id === result.rule_id );
	const matched = (
		<MatchedRule result={ result } rule={ rule } onReveal={ onReveal } />
	);
	if ( result.blocked ) {
		return (
			<>
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
				{ matched }
			</>
		);
	}
	if ( ! result.target_url ) {
		return (
			<>
				<span>
					<StatusBadge status={ result.status } />{ ' ' }
					{ sprintf(
						/* translators: 1: rule ID, 2: HTTP status */
						__( 'Rule #%1$d responds with %2$d.', 'wp-redirects' ),
						result.rule_id,
						result.status
					) }
				</span>
				{ matched }
			</>
		);
	}
	const isChain = ! result.loop && result.hops.length > 2;
	return (
		<>
			<span>
				<StatusBadge status={ result.status } />{ ' ' }
				<code>{ result.hops.join( ' → ' ) }</code>
				{ result.loop && (
					<strong className="adv-redirects-flag is-error">
						{ __( 'Loop detected', 'wp-redirects' ) }
					</strong>
				) }
				{ isChain && (
					<span className="adv-redirects-flag is-warning">
						{ sprintf(
							/* translators: %d: number of redirects in the chain */
							__( 'Chain of %d redirects', 'wp-redirects' ),
							result.hops.length - 1
						) }
					</span>
				) }
			</span>
			{ matched }
		</>
	);
}

export default function TestUrlBar( {
	value,
	onChange,
	onResult,
	onReveal,
	rules = [],
} ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	// The path the current result describes; null when nothing is shown.
	const [ testedPath, setTestedPath ] = useState( null );
	// Bumped per answer so per-result state (like "Show rule") starts fresh.
	const [ runId, setRunId ] = useState( 0 );
	const latestValue = useRef( value );
	latestValue.current = value;

	const clear = () => {
		setResult( null );
		setError( '' );
		setTestedPath( null );
		onResult( null );
	};

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
			// Drop the answer if the input changed while it was in flight.
			if ( isStaleTest( path, latestValue.current ) ) {
				return;
			}
			setResult( response );
			setRunId( ( current ) => current + 1 );
			setTestedPath( path );
			onResult( response.matched ? response.rule_id : null );
		} catch ( requestError ) {
			setResult( null );
			setTestedPath( path );
			setError( errorMessage( requestError ) );
			onResult( null );
		} finally {
			setBusy( false );
		}
	};

	const showHint = ! result && ! error;

	return (
		<form className="adv-redirects-test" onSubmit={ run }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Test a URL', 'wp-redirects' ) }
				placeholder="/old-page"
				value={ value }
				onChange={ ( next ) => {
					onChange( next );
					// A result only describes the path it was run for.
					if ( ! next || isStaleTest( testedPath, next ) ) {
						clear();
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
				{ result && (
					<TestResult
						key={ runId }
						result={ result }
						rules={ rules }
						onReveal={ onReveal }
					/>
				) }
			</div>
			{ showHint && (
				<p className="adv-redirects-test__hint">
					{ __(
						'Shows which redirect answers a path and where it ends up.',
						'wp-redirects'
					) }
				</p>
			) }
		</form>
	);
}
