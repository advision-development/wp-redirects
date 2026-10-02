import { SnackbarList, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import NotFoundTab from './NotFoundTab';
import RedirectsTab from './RedirectsTab';
import SettingsTab from './SettingsTab';
import Tabs from './Tabs';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );
	const [ prefill, setPrefill ] = useState( null );
	const [ settings, setSettings ] = useState( null );

	useEffect( () => {
		api.getSettings()
			.then( setSettings )
			.catch( ( error ) =>
				notify( { status: 'error', message: errorMessage( error ) } )
			);
	}, [ notify ] );

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
	];

	return (
		<div className="adv-redirects">
			<header className="adv-redirects__header">
				<h1>{ __( 'Redirects', 'wp-redirects' ) }</h1>
			</header>
			<Tabs tabs={ tabs } selected={ tab } onSelect={ setTab } />
			<div
				role="tabpanel"
				id={ `adv-redirects-panel-${ tab }` }
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
				{ tab === 'settings' &&
					( settings ? (
						<SettingsTab
							settings={ settings }
							onSaved={ setSettings }
							notify={ notify }
						/>
					) : (
						<div className="adv-redirects-loading">
							<Spinner />
						</div>
					) ) }
			</div>
			<SnackbarList
				notices={ notices }
				onRemove={ dismiss }
				className="adv-redirects__snackbars"
			/>
		</div>
	);
}
