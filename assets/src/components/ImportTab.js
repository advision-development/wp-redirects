import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import {
	BATCH_SIZE,
	buildReport,
	checkRedirectionExport,
	chunk,
	importableEntries,
	importErrorMessage,
	stripExport,
} from '../utils/redirectionImport';
import {
	batchPayload,
	entrySource,
	removalCandidates,
	yoastPayload,
} from '../utils/yoastImport';
import ImportPreview from './ImportPreview';
import YoastNotices from './YoastNotices';
import YoastRemoveCard from './YoastRemoveCard';

function downloadJson( data, filename ) {
	const url = URL.createObjectURL(
		new Blob( [ JSON.stringify( data, null, 2 ) ], {
			type: 'application/json',
		} )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	// Give the browser a tick to start the download before releasing the blob.
	setTimeout( () => URL.revokeObjectURL( url ), 0 );
}

export default function ImportTab( { onImported, onViewRedirects } ) {
	const inputRef = useRef();
	const cancelRef = useRef( false );
	const readCounter = useRef( 0 );
	const previewHeadingRef = useRef();
	const cancelButtonRef = useRef();
	const chooseButtonRef = useRef();
	const resultRef = useRef();
	// idle | checked | previewing | previewed | importing | done | failed
	const [ phase, setPhase ] = useState( 'idle' );
	const [ fileName, setFileName ] = useState( '' );
	const [ check, setCheck ] = useState( null );
	const [ payload, setPayload ] = useState( null );
	const [ preview, setPreview ] = useState( null );
	const [ progress, setProgress ] = useState( { done: 0, total: 0 } );
	const [ result, setResult ] = useState( null );
	const [ requestError, setRequestError ] = useState( '' );
	const [ dragging, setDragging ] = useState( false );
	const [ cancelling, setCancelling ] = useState( false );
	const [ yoast, setYoast ] = useState( null );

	const loadYoast = useCallback( async () => {
		try {
			setYoast( await api.yoastStatus() );
		} catch {
			setYoast( null );
		}
	}, [] );

	useEffect( () => {
		loadYoast();
	}, [ loadYoast ] );

	// If the tab is ever unmounted mid-import, stop posting further batches.
	useEffect( () => {
		return () => {
			cancelRef.current = true;
		};
	}, [] );

	// Move focus to where the next step is, so keyboard and screen reader users
	// are not left on an element that just disappeared.
	useEffect( () => {
		if ( phase === 'previewed' ) {
			previewHeadingRef.current?.focus();
		} else if ( phase === 'importing' ) {
			cancelButtonRef.current?.focus();
		} else if ( phase === 'done' || phase === 'failed' ) {
			resultRef.current?.focus();
		}
	}, [ phase ] );

	const reset = () => {
		setCancelling( false );
		setPhase( 'idle' );
		setFileName( '' );
		setCheck( null );
		setPayload( null );
		setPreview( null );
		setProgress( { done: 0, total: 0 } );
		setResult( null );
		setRequestError( '' );
		if ( inputRef.current ) {
			inputRef.current.value = '';
		}
	};

	const readFile = async ( file ) => {
		if ( ! file ) {
			return;
		}
		const readId = ++readCounter.current;
		reset();
		setFileName( file.name );
		let data;
		try {
			const text = await file.text();
			if ( readId !== readCounter.current ) {
				return;
			}
			data = JSON.parse( text );
		} catch {
			if ( readId !== readCounter.current ) {
				return;
			}
			setCheck( {
				ok: false,
				errors: [
					__( 'This file is not valid JSON.', 'wp-redirects' ),
				],
				summary: null,
			} );
			setPhase( 'checked' );
			return;
		}
		const checked = checkRedirectionExport( data );
		setCheck( checked );
		setPayload( checked.ok ? stripExport( data ) : null );
		setPhase( 'checked' );
	};

	const runPreview = async ( data = payload ) => {
		setPhase( 'previewing' );
		setRequestError( '' );
		try {
			setPreview( await api.importPreview( data ) );
			setPhase( 'previewed' );
		} catch ( error ) {
			setRequestError( importErrorMessage( error ) );
			setPhase( 'checked' );
		}
	};

	const startYoast = () => {
		const data = yoastPayload( yoast.entries );
		reset();
		setPayload( data );
		runPreview( data );
	};

	const runImport = async () => {
		const entries = importableEntries( preview, payload.redirects );
		const totals = { created: 0, updated: 0, skipped: [], imported: [] };
		let done = 0;
		cancelRef.current = false;
		setCancelling( false );
		setProgress( { done: 0, total: entries.length } );
		setPhase( 'importing' );
		try {
			for ( const batch of chunk( entries, BATCH_SIZE ) ) {
				if ( cancelRef.current ) {
					break;
				}
				const response = await api.importBatch(
					batchPayload(
						payload,
						batch.map( ( item ) => item.entry )
					)
				);
				response.entries.forEach( ( item, position ) => {
					if ( item.result === 'created' ) {
						totals.created++;
						totals.imported.push( batch[ position ].index );
					} else if ( item.result === 'updated' ) {
						totals.updated++;
						totals.imported.push( batch[ position ].index );
					} else {
						totals.skipped.push( {
							index: batch[ position ].index,
							source: entrySource( batch[ position ].entry ),
							error: item.error,
						} );
					}
				} );
				done += batch.length;
				setProgress( { done, total: entries.length } );
			}
			setResult( {
				...totals,
				done,
				cancelled: done < entries.length,
				notRun: entries.length - done,
			} );
			setPhase( 'done' );
		} catch ( error ) {
			setResult( {
				...totals,
				done,
				failed: importErrorMessage( error ),
			} );
			setPhase( 'failed' );
		} finally {
			onImported();
			if ( payload.source === 'yoast' ) {
				loadYoast();
			}
		}
	};

	const yoastFlow = Boolean( payload && payload.source === 'yoast' );
	const reportSummary = yoastFlow
		? {
				plugin: 'yoast',
				version: ( yoast && yoast.premium_version ) || '',
				date: '',
			}
		: check && check.summary;
	const counts = preview ? preview.counts : null;
	const importCount = counts ? counts.new + counts.overwrite : 0;
	const busy = phase === 'previewing' || phase === 'importing';
	let resultMessage = '';
	if ( result && phase === 'failed' ) {
		const imported = result.created + result.updated;
		const reason = result.failed.replace( /\.+$/, '' );
		resultMessage =
			imported > 0
				? sprintf(
						/* translators: 1: error message, 2: rules imported before the failure */
						_n(
							'Import stopped: %1$s. At least %2$d redirect was imported before it stopped; running the import again is safe.',
							'Import stopped: %1$s. At least %2$d redirects were imported before it stopped; running the import again is safe.',
							imported,
							'wp-redirects'
						),
						reason,
						imported
					)
				: sprintf(
						/* translators: %s: error message */
						__(
							'Import stopped: %s. No redirects were confirmed as imported; running the import again is safe.',
							'wp-redirects'
						),
						reason
					);
	} else if ( result && result.cancelled ) {
		resultMessage = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: superseded, 5: not imported */
			__(
				'Import cancelled: %1$d created, %2$d updated, %3$d skipped, %4$d superseded; %5$d not imported.',
				'wp-redirects'
			),
			result.created,
			result.updated,
			result.skipped.length + counts.skipped,
			counts.superseded,
			result.notRun
		);
	} else if ( result ) {
		resultMessage = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: superseded */
			__(
				'Import complete: %1$d created, %2$d updated, %3$d skipped, %4$d superseded.',
				'wp-redirects'
			),
			result.created,
			result.updated,
			result.skipped.length + counts.skipped,
			counts.superseded
		);
	}
	const announcement =
		phase === 'previewed' && counts
			? sprintf(
					/* translators: 1: new, 2: overwrite, 3: skipped, 4: superseded */
					__(
						'Preview ready: %1$d new, %2$d overwrite, %3$d skipped, %4$d superseded.',
						'wp-redirects'
					),
					counts.new,
					counts.overwrite,
					counts.skipped,
					counts.superseded
				)
			: '';
	const percent = progress.total
		? Math.round( ( progress.done / progress.total ) * 100 )
		: 0;

	return (
		<>
			<YoastNotices
				status={ yoast }
				busy={ busy }
				previewing={ yoastFlow && phase === 'previewing' }
				previewError={ yoastFlow ? requestError : '' }
				flowActive={
					yoastFlow &&
					! [ 'idle', 'previewing', 'checked' ].includes( phase )
				}
				onPreview={ startYoast }
				onChanged={ loadYoast }
			/>
			<section className="adv-redirects-card adv-redirects-import">
				<h2 className="adv-redirects-card__title">
					{ __( 'Import from Redirection', 'wp-redirects' ) }
				</h2>
				<p className="description">
					{ __(
						'Upload a JSON export from the Redirection plugin. Only the redirect rules are read; its logs and 404 records stay on your computer.',
						'wp-redirects'
					) }
				</p>
				<div
					className={ `adv-redirects-import__drop${ dragging ? ' is-dragging' : '' }` }
					onDragOver={ ( event ) => {
						event.preventDefault();
						setDragging( true );
					} }
					onDragLeave={ () => setDragging( false ) }
					onDrop={ ( event ) => {
						event.preventDefault();
						setDragging( false );
						if ( ! busy ) {
							readFile( event.dataTransfer.files[ 0 ] );
						}
					} }
				>
					<input
						ref={ inputRef }
						tabIndex={ -1 }
						type="file"
						accept=".json,application/json"
						className="adv-redirects-import__input"
						aria-label={ __(
							'Redirection export file',
							'wp-redirects'
						) }
						disabled={ busy }
						onChange={ ( event ) =>
							readFile( event.target.files[ 0 ] )
						}
					/>
					<Button
						ref={ chooseButtonRef }
						variant="secondary"
						disabled={ busy }
						onClick={ () => inputRef.current.click() }
						__next40pxDefaultSize
					>
						{ __( 'Choose file', 'wp-redirects' ) }
					</Button>
					<span className="adv-redirects-muted">
						{ fileName ||
							__( 'or drop a .json file here', 'wp-redirects' ) }
					</span>
				</div>

				<p className="screen-reader-text" role="status">
					{ announcement }
				</p>

				{ check && ! check.ok && (
					<Notice status="error" isDismissible={ false }>
						<p>
							{ __(
								'This file can’t be imported:',
								'wp-redirects'
							) }
						</p>
						<ul>
							{ check.errors.map( ( message ) => (
								<li key={ message }>{ message }</li>
							) ) }
						</ul>
					</Notice>
				) }

				{ check && check.ok && (
					<dl className="adv-redirects-import__summary">
						<dt>{ __( 'Exported from', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: plugin version, 2: export date */
								__( 'Redirection %1$s, %2$s', 'wp-redirects' ),
								check.summary.version,
								check.summary.date
							) }
						</dd>
						<dt>{ __( 'Redirects found', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: total, 2: exact count, 3: regex count */
								__(
									'%1$d (%2$d exact, %3$d regex)',
									'wp-redirects'
								),
								check.summary.total,
								check.summary.exact,
								check.summary.regex
							) }
						</dd>
						<dt>{ __( 'Not imported', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: log entries, 2: 404 records */
								__(
									'%1$d log entries and %2$d 404 records (they never leave your computer)',
									'wp-redirects'
								),
								check.summary.logs,
								check.summary.errors404
							) }
						</dd>
					</dl>
				) }

				{ requestError && ! yoastFlow && (
					<Notice status="error" isDismissible={ false }>
						{ requestError }
					</Notice>
				) }

				{ ( phase === 'checked' || phase === 'previewing' ) &&
					check &&
					check.ok && (
						<Button
							variant="primary"
							isBusy={ phase === 'previewing' }
							disabled={ busy }
							onClick={ () => runPreview() }
							__next40pxDefaultSize
						>
							{ __( 'Preview import', 'wp-redirects' ) }
						</Button>
					) }
			</section>

			{ preview && phase !== 'done' && phase !== 'failed' && (
				<section className="adv-redirects-card">
					<h2
						className="adv-redirects-card__title"
						ref={ previewHeadingRef }
						tabIndex={ -1 }
					>
						{ __( 'Preview', 'wp-redirects' ) }
					</h2>
					<ImportPreview preview={ preview } />
					{ phase === 'previewed' && (
						<Button
							variant="primary"
							disabled={ importCount === 0 }
							onClick={ runImport }
							__next40pxDefaultSize
						>
							{ counts.overwrite > 0
								? sprintf(
										/* translators: 1: number to import, 2: number that overwrite existing rules */
										_n(
											'Import %1$d redirect (%2$d overwrite existing)',
											'Import %1$d redirects (%2$d overwrite existing)',
											importCount,
											'wp-redirects'
										),
										importCount,
										counts.overwrite
									)
								: sprintf(
										/* translators: %d: number to import */
										_n(
											'Import %d redirect',
											'Import %d redirects',
											importCount,
											'wp-redirects'
										),
										importCount
									) }
						</Button>
					) }
					<div className="adv-redirects-import__progress">
						{ phase === 'importing' && (
							<progress
								max={ progress.total }
								value={ progress.done }
								aria-label={ __(
									'Import progress',
									'wp-redirects'
								) }
							/>
						) }
						{ /* Always mounted so the first update is announced. */ }
						<p aria-live="polite">
							{ phase === 'importing' && (
								<>
									{ cancelling
										? __(
												'Cancelling after this batch…',
												'wp-redirects'
											)
										: sprintf(
												/* translators: 1: imported so far, 2: total, 3: percent */
												__(
													'Imported %1$d of %2$d (%3$d%%)',
													'wp-redirects'
												),
												progress.done,
												progress.total,
												percent
											) }{ ' ' }
									<Spinner />
								</>
							) }
						</p>
						{ phase === 'importing' && (
							<Button
								ref={ cancelButtonRef }
								variant="secondary"
								aria-disabled={ cancelling }
								onClick={ () => {
									if ( ! cancelling ) {
										cancelRef.current = true;
										setCancelling( true );
									}
								} }
							>
								{ __( 'Cancel', 'wp-redirects' ) }
							</Button>
						) }
					</div>
				</section>
			) }

			{ result && (
				<section className="adv-redirects-card">
					<div ref={ resultRef } tabIndex={ -1 }>
						<Notice
							status={ phase === 'failed' ? 'error' : 'success' }
							isDismissible={ false }
						>
							{ resultMessage }
						</Notice>
					</div>
					{ yoastFlow && phase === 'done' && ! result.cancelled && (
						<YoastRemoveCard
							candidates={ removalCandidates(
								preview,
								payload.redirects,
								result.imported
							) }
							status={ yoast }
							onDone={ loadYoast }
						/>
					) }
					<div className="adv-redirects-import__actions">
						<Button
							variant="secondary"
							onClick={ () =>
								downloadJson(
									buildReport( {
										summary: reportSummary,
										preview,
										importSkipped: result.skipped,
									} ),
									'wp-redirects-import-report.json'
								)
							}
						>
							{ __( 'Download report', 'wp-redirects' ) }
						</Button>
						<Button variant="primary" onClick={ onViewRedirects }>
							{ __( 'View redirects', 'wp-redirects' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								reset();
								// This button is about to unmount; keep focus in the flow.
								chooseButtonRef.current?.focus();
							} }
						>
							{ __( 'Import another file', 'wp-redirects' ) }
						</Button>
					</div>
				</section>
			) }
		</>
	);
}
