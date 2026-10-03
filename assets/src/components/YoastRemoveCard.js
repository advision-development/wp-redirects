import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { chunk } from '../utils/redirectionImport';
import {
	pendingRemovals,
	REMOVE_CHUNK,
	serverModeWarning,
} from '../utils/yoastImport';

export default function YoastRemoveCard( {
	candidates,
	status,
	onDone,
	onRemovingChange = () => {},
} ) {
	// ask | removing | removed | kept | failed
	const [ state, setState ] = useState( 'ask' );
	const [ done, setDone ] = useState( 0 );
	// How many items the current run sends (a retry skips the ones already removed).
	const [ sending, setSending ] = useState( 0 );
	// Response items received so far, across runs.
	const reported = useRef( [] );
	const [ totals, setTotals ] = useState( {
		removed: 0,
		notCovered: 0,
		notFound: 0,
	} );
	const [ error, setError ] = useState( '' );
	const resultRef = useRef();
	const progressRef = useRef();

	useEffect( () => {
		if ( [ 'removed', 'kept', 'failed' ].includes( state ) ) {
			resultRef.current?.focus();
		} else if ( state === 'removing' ) {
			// The Remove button just unmounted; keep focus on the progress text.
			progressRef.current?.focus();
		}
	}, [ state ] );

	if ( ! candidates.length ) {
		return null;
	}

	const remove = async () => {
		const pending = pendingRemovals( candidates, reported.current );
		// Removed counts add up across runs; the rest is counted again by this run.
		const sum = { removed: totals.removed, notCovered: 0, notFound: 0 };
		let sent = 0;
		setState( 'removing' );
		setSending( pending.length );
		setDone( 0 );
		setError( '' );
		onRemovingChange( true );
		try {
			for ( const items of chunk( pending, REMOVE_CHUNK ) ) {
				const response = await api.yoastRemove( items );
				reported.current = reported.current.concat(
					response.items || []
				);
				sum.removed += response.removed;
				sum.notCovered += response.not_covered;
				sum.notFound += response.not_found;
				sent += items.length;
				setDone( sent );
			}
			setTotals( sum );
			setState( 'removed' );
		} catch ( requestError ) {
			setTotals( sum );
			setError( errorMessage( requestError ) );
			setState( 'failed' );
		} finally {
			onRemovingChange( false );
			onDone();
		}
	};

	const warning = serverModeWarning( status );
	const left = totals.notCovered + totals.notFound;

	return (
		<div className="adv-redirects-yoast-remove">
			{ ( state === 'ask' || state === 'failed' ) && (
				<>
					<p>
						{ sprintf(
							/* translators: %d: number of redirects */
							_n(
								'Remove %d imported redirect from Yoast? A backup is kept, and you can restore it here.',
								'Remove %d imported redirects from Yoast? A backup is kept, and you can restore it here.',
								candidates.length,
								'wp-redirects'
							),
							candidates.length
						) }
					</p>
					{ status && status.premium_active && (
						<p>
							{ __(
								'While Yoast SEO Premium is active, it serves these redirects before WP Redirects does.',
								'wp-redirects'
							) }
						</p>
					) }
					{ warning && <p>{ warning }</p> }
				</>
			) }

			<div ref={ resultRef } tabIndex={ -1 }>
				{ state === 'removed' && (
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of redirects */
							_n(
								'Removed %d redirect from Yoast.',
								'Removed %d redirects from Yoast.',
								totals.removed,
								'wp-redirects'
							),
							totals.removed
						) }
						{ left > 0 &&
							' ' +
								sprintf(
									/* translators: %d: number of redirects */
									_n(
										'%d was left in Yoast: WP Redirects has no matching rule, or Yoast no longer has it.',
										'%d were left in Yoast: WP Redirects has no matching rule, or Yoast no longer has them.',
										left,
										'wp-redirects'
									),
									left
								) }
					</Notice>
				) }
				{ state === 'kept' && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Kept in Yoast.', 'wp-redirects' ) }
					</Notice>
				) }
				{ state === 'failed' && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: error message, 2: redirects removed before the failure */
							__(
								'Removing stopped: %1$s. %2$d redirects were removed before it stopped; running it again is safe.',
								'wp-redirects'
							),
							error.replace( /\.+$/, '' ),
							totals.removed
						) }
					</Notice>
				) }
			</div>

			{ /* Always mounted so the first update is announced. */ }
			<p ref={ progressRef } tabIndex={ -1 } aria-live="polite">
				{ state === 'removing' && (
					<>
						{ sprintf(
							/* translators: 1: processed so far, 2: total */
							__( 'Removing %1$d of %2$d…', 'wp-redirects' ),
							done,
							sending
						) }{ ' ' }
						<Spinner />
					</>
				) }
			</p>

			{ ( state === 'ask' || state === 'failed' ) && (
				<div className="adv-redirects-import__actions">
					<Button
						variant="primary"
						isDestructive
						onClick={ remove }
						__next40pxDefaultSize
					>
						{ __( 'Remove from Yoast', 'wp-redirects' ) }
					</Button>
					{ state === 'ask' && (
						<Button
							variant="tertiary"
							onClick={ () => setState( 'kept' ) }
						>
							{ __( 'Keep in Yoast', 'wp-redirects' ) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
}
