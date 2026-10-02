import { createRoot } from '@wordpress/element';
import App from './components/App';
import './admin.scss';

const container = document.getElementById( 'adv-redirects-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
