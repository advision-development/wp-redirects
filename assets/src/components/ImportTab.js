import { Button, Notice, Spinner } from '@wordpress/components';
import { useId, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import {
	BATCH_SIZE,
	buildReport,
	checkRedirectionExport,
	chunk,
	importableEntries,
	stripExport,
} from '../utils/redirectionImport';
import ImportPreview from './ImportPreview';

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
	URL.revokeObjectURL( url );
}

export default function ImportTab( { onImported, onViewRedirects } ) {
	const inputId = useId();
	const inputRef = useRef();
	const cancelRef = useRef( false );
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

	const reset = () => {
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
		reset();
		setFileName( file.name );
		let data;
		try {
			data = JSON.parse( await file.text() );
		} catch {
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

	const runPreview = async () => {
		setPhase( 'previewing' );
		setRequestError( '' );
		try {
			setPreview( await api.importPreview( payload ) );
			setPhase( 'previewed' );
		} catch ( error ) {
			setRequestError( errorMessage( error ) );
			setPhase( 'checked' );
		}
	};

	const runImport = async () => {
		const entries = importableEntries( preview, payload.redirects );
		const totals = { created: 0, updated: 0, skipped: [] };
		let done = 0;
		cancelRef.current = false;
		setProgress( { done: 0, total: entries.length } );
		setPhase( 'importing' );
		try {
			for ( const batch of chunk( entries, BATCH_SIZE ) ) {
				if ( cancelRef.current ) {
					break;
				}
				const response = await api.importBatch( {
					source: 'redirection',
					groups: payload.groups,
					redirects: batch.map( ( item ) => item.entry ),
				} );
				response.entries.forEach( ( item, position ) => {
					if ( item.result === 'created' ) {
						totals.created++;
					} else if ( item.result === 'updated' ) {
						totals.updated++;
					} else {
						totals.skipped.push( {
							index: batch[ position ].index,
							source: batch[ position ].entry.url,
							error: item.error,
						} );
					}
				} );
				done += batch.length;
				setProgress( { done, total: entries.length } );
			}
			setResult( { ...totals, done, cancelled: done < entries.length } );
			setPhase( 'done' );
		} catch ( error ) {
			setResult( { ...totals, done, failed: errorMessage( error ) } );
			setPhase( 'failed' );
		} finally {
			onImported();
		}
	};

	const counts = preview ? preview.counts : null;
	const importCount = counts ? counts.new + counts.overwrite : 0;
	const busy = phase === 'previewing' || phase === 'importing';
	const percent = progress.total
		? Math.round( ( progress.done / progress.total ) * 100 )
		: 0;

	return (
		<>
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
						id={ inputId }
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

				{ requestError && (
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
							onClick={ runPreview }
							__next40pxDefaultSize
						>
							{ __( 'Preview import', 'wp-redirects' ) }
						</Button>
					) }
			</section>

			{ preview && phase !== 'done' && phase !== 'failed' && (
				<section className="adv-redirects-card">
					<h2 className="adv-redirects-card__title">
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
					{ phase === 'importing' && (
						<div className="adv-redirects-import__progress">
							<progress
								max={ progress.total }
								value={ progress.done }
								aria-label={ __(
									'Import progress',
									'wp-redirects'
								) }
							/>
							<p aria-live="polite">
								{ sprintf(
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
							</p>
							<Button
								variant="secondary"
								onClick={ () => ( cancelRef.current = true ) }
							>
								{ __( 'Cancel', 'wp-redirects' ) }
							</Button>
						</div>
					) }
				</section>
			) }

			{ result && (
				<section className="adv-redirects-card">
					<Notice
						status={ phase === 'failed' ? 'error' : 'success' }
						isDismissible={ false }
					>
						{ phase === 'failed'
							? sprintf(
									/* translators: 1: error message, 2: rules imported before the failure */
									__(
										'Import stopped: %1$s. %2$d redirects were imported before it stopped; running the import again is safe.',
										'wp-redirects'
									),
									result.failed,
									result.created + result.updated
								)
							: sprintf(
									/* translators: 1: created, 2: updated, 3: skipped */
									__(
										'Import complete: %1$d created, %2$d updated, %3$d skipped.',
										'wp-redirects'
									),
									result.created,
									result.updated,
									result.skipped.length + counts.skipped
								) }
						{ result.cancelled &&
							phase === 'done' &&
							' ' +
								__(
									'Cancelled before all batches ran.',
									'wp-redirects'
								) }
					</Notice>
					<div className="adv-redirects-import__actions">
						<Button
							variant="secondary"
							onClick={ () =>
								downloadJson(
									buildReport( {
										summary: check.summary,
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
						<Button variant="tertiary" onClick={ reset }>
							{ __( 'Import another file', 'wp-redirects' ) }
						</Button>
					</div>
				</section>
			) }
		</>
	);
}
