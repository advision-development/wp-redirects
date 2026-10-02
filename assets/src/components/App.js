import { SnackbarList } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import RedirectsTab from './RedirectsTab';
import Tabs from './Tabs';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );
	const [ prefill, setPrefill ] = useState( null );
	const clearPrefill = useCallback( () => setPrefill( null ), [] );

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
			</div>
			<SnackbarList
				notices={ notices }
				onRemove={ dismiss }
				className="adv-redirects__snackbars"
			/>
		</div>
	);
}
