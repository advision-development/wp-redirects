import { Button, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { formatGmt } from '../utils/attribution';
import { MAX_PREVIEW } from '../utils/redirectionImport';
import {
	hideNotice,
	noticeHidden,
	serverModeWarning,
} from '../utils/yoastImport';
import ConfirmModal from './ConfirmModal';

export default function YoastNotices( {
	status,
	busy,
	previewing,
	previewError,
	flowActive,
	onPreview,
	onChanged,
} ) {
	const [ hidden, setHidden ] = useState( noticeHidden );
	const [ confirm, setConfirm ] = useState( null );
	const [ working, setWorking ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const messageRef = useRef();
	const errorRef = useRef();
	const previewErrorRef = useRef();

	// A failed preview shows its error in the notice; move focus there.
	useEffect( () => {
		if ( previewError ) {
			previewErrorRef.current?.focus();
		}
	}, [ previewError ] );

	// A failed restore or delete shows its error; move focus there.
	useEffect( () => {
		if ( error ) {
			errorRef.current?.focus();
		}
	}, [ error ] );

	if ( ! status ) {
		return null;
	}

	const total = status.entries.length;
	const warning = serverModeWarning( status );
	const backupCount = status.backup ? status.backup.count : 0;

	const run = async ( action ) => {
		setConfirm( null );
		setWorking( true );
		setError( '' );
		setMessage( '' );
		let failed = false;
		try {
			if ( action === 'restore' ) {
				const result = await api.yoastRestore();
				setMessage(
					sprintf(
						/* translators: 1: redirects restored, 2: redirects Yoast already had */
						__(
							'Restored %1$d redirects to Yoast; %2$d were already there.',
							'wp-redirects'
						),
						result.restored,
						result.already_present
					)
				);
			} else {
				await api.yoastDeleteBackup();
				setMessage( __( 'Backup deleted.', 'wp-redirects' ) );
			}
			await onChanged();
		} catch ( requestError ) {
			failed = true;
			setError( errorMessage( requestError ) );
		} finally {
			setWorking( false );
			// The notice that held the focused button may be gone; keep focus in the flow.
			// On failure the error effect above moves focus to the error instead.
			if ( ! failed ) {
				messageRef.current?.focus();
			}
		}
	};

	return (
		<div className="adv-redirects-yoast">
			{ status.detected && ! hidden && ! flowActive && (
				<>
					<Notice status="info" isDismissible={ false }>
						<p>
							{ sprintf(
								/* translators: 1: plain redirects, 2: regex redirects */
								__(
									'Yoast SEO Premium redirects found: %1$d plain, %2$d regex.',
									'wp-redirects'
								),
								status.counts.plain,
								status.counts.regex
							) }
						</p>
						{ status.premium_active && (
							<p>
								{ __(
									'Yoast serves these first until you remove them from Yoast after importing.',
									'wp-redirects'
								) }
							</p>
						) }
						{ warning && <p>{ warning }</p> }
						{ total > MAX_PREVIEW && (
							<p>
								{ sprintf(
									/* translators: 1: number of Yoast redirects, 2: maximum per import */
									__(
										'Yoast has %1$d redirects; the maximum per import is %2$d.',
										'wp-redirects'
									),
									total,
									MAX_PREVIEW
								) }
							</p>
						) }
						<div className="adv-redirects-import__actions">
							<Button
								variant="primary"
								isBusy={ previewing }
								disabled={ total > MAX_PREVIEW }
								aria-disabled={ busy || undefined }
								onClick={ () => {
									if ( ! busy ) {
										onPreview();
									}
								} }
								__next40pxDefaultSize
							>
								{ __( 'Preview Yoast import', 'wp-redirects' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => {
									hideNotice();
									setHidden( true );
								} }
							>
								{ __( 'Not now', 'wp-redirects' ) }
							</Button>
						</div>
					</Notice>
					{ previewError && (
						<div ref={ previewErrorRef } tabIndex={ -1 }>
							<Notice status="error" isDismissible={ false }>
								{ previewError }
							</Notice>
						</div>
					) }
				</>
			) }

			{ status.backup && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: number of redirects, 2: date */
							_n(
								'%1$d redirect removed from Yoast, last on %2$s.',
								'%1$d redirects removed from Yoast, last on %2$s.',
								backupCount,
								'wp-redirects'
							),
							backupCount,
							formatGmt( status.backup.last_removed_at )
						) }
					</p>
					<div className="adv-redirects-import__actions">
						<Button
							variant="secondary"
							disabled={ busy || working }
							onClick={ () => setConfirm( 'restore' ) }
						>
							{ __( 'Restore to Yoast', 'wp-redirects' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy || working }
							onClick={ () => setConfirm( 'delete' ) }
						>
							{ __( 'Delete backup', 'wp-redirects' ) }
						</Button>
					</div>
				</Notice>
			) }

			{ error && (
				<div ref={ errorRef } tabIndex={ -1 }>
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				</div>
			) }
			{ /* Always mounted so results are announced. */ }
			<p
				ref={ messageRef }
				tabIndex={ -1 }
				role="status"
				className="adv-redirects-yoast__message"
			>
				{ message }
			</p>

			{ confirm === 'restore' && (
				<ConfirmModal
					title={ __(
						'Restore redirects to Yoast?',
						'wp-redirects'
					) }
					message={ sprintf(
						/* translators: %d: number of backed-up redirects */
						__(
							'This puts the %d backed-up redirects back into Yoast SEO Premium. Redirects Yoast already has are left as they are. Your WP Redirects rules are not changed.',
							'wp-redirects'
						),
						backupCount
					) }
					confirmLabel={ __( 'Restore to Yoast', 'wp-redirects' ) }
					onConfirm={ () => run( 'restore' ) }
					onCancel={ () => setConfirm( null ) }
				/>
			) }
			{ confirm === 'delete' && (
				<ConfirmModal
					title={ __( 'Delete the Yoast backup?', 'wp-redirects' ) }
					message={ sprintf(
						/* translators: %d: number of backed-up redirects */
						__(
							'The %d backed-up redirects can no longer be restored to Yoast. Your WP Redirects rules are not changed.',
							'wp-redirects'
						),
						backupCount
					) }
					confirmLabel={ __( 'Delete backup', 'wp-redirects' ) }
					onConfirm={ () => run( 'delete' ) }
					onCancel={ () => setConfirm( null ) }
				/>
			) }
		</div>
	);
}
