import { createRoot } from '@wordpress/element';
import App from './App';
import './settings.scss';

const root = document.getElementById( 'wptb-settings' );
if ( root && window.wptbSettings ) {
	createRoot( root ).render( <App data={ window.wptbSettings } /> );
}
