import { Button, Notice, SnackbarList, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import ImportTab from './ImportTab';
import NotFoundTab from './NotFoundTab';
import RedirectsTab from './RedirectsTab';
import SettingsTab from './SettingsTab';
import Tabs from './Tabs';

const PANEL_ID = 'adv-redirects-panel';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );
	const [ prefill, setPrefill ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ settingsError, setSettingsError ] = useState( '' );

	const loadSettings = useCallback( () => {
		setSettingsError( '' );
		api.getSettings()
			.then( setSettings )
			.catch( ( error ) => setSettingsError( errorMessage( error ) ) );
	}, [] );

	useEffect( () => {
		loadSettings();
	}, [ loadSettings ] );

	const clearPrefill = useCallback( () => setPrefill( null ), [] );
	const createFrom404 = useCallback( ( path ) => {
		setPrefill( path );
		setTab( 'redirects' );
	}, [] );

	const tabs = [
		{
			name: 'redirects',
			title: __( 'Redirects', 'wp-redirects' ),
			count: redirects.items.length,
		},
		{ name: '404s', title: __( '404 Log', 'wp-redirects' ) },
		{ name: 'settings', title: __( 'Settings', 'wp-redirects' ) },
		{ name: 'import', title: __( 'Import', 'wp-redirects' ) },
	];

	return (
		<div className="adv-redirects">
			<header className="adv-redirects__header">
				<h1>{ __( 'Redirects', 'wp-redirects' ) }</h1>
			</header>
			<Tabs
				tabs={ tabs }
				selected={ tab }
				onSelect={ setTab }
				panelId={ PANEL_ID }
			/>
			<div
				role="tabpanel"
				id={ PANEL_ID }
				aria-labelledby={ `adv-redirects-tab-${ tab }` }
				className="adv-redirects__panel"
			>
				{ tab === 'redirects' && (
					<RedirectsTab
						redirects={ redirects }
						notify={ notify }
						prefill={ prefill }
						onPrefillUsed={ clearPrefill }
					/>
				) }
				{ tab === '404s' && (
					<NotFoundTab
						settings={ settings }
						notify={ notify }
						onCreateRedirect={ createFrom404 }
						onOpenSettings={ () => setTab( 'settings' ) }
					/>
				) }
				{ tab === 'settings' && settings && (
					<SettingsTab
						settings={ settings }
						onSaved={ setSettings }
						notify={ notify }
					/>
				) }
				{ tab === 'settings' && ! settings && settingsError && (
					<Notice status="error" isDismissible={ false }>
						{ settingsError }{ ' ' }
						<Button variant="link" onClick={ loadSettings }>
							{ __( 'Try again', 'wp-redirects' ) }
						</Button>
					</Notice>
				) }
				{ tab === 'settings' && ! settings && ! settingsError && (
					<div className="adv-redirects-loading">
						<Spinner />
					</div>
				) }
				{ /* Stays mounted so switching tabs never interrupts a running import. */ }
				<div
					className="adv-redirects-import-pane"
					hidden={ tab !== 'import' }
				>
					<ImportTab
						onImported={ redirects.reload }
						onViewRedirects={ () => setTab( 'redirects' ) }
					/>
				</div>
			</div>
			<SnackbarList
				notices={ notices }
				onRemove={ dismiss }
				className="adv-redirects__snackbars"
			/>
		</div>
	);
}
