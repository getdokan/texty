import { createRoot } from '@wordpress/element';
import menuFix from './utils/admin-menu-fix';
import App from './App';
import 'react-toastify/dist/ReactToastify.css';
import 'react-phone-input-2/lib/style.css';
import './style.scss';

const mountNode = document.getElementById('texty-app');
const root = createRoot(mountNode);
root.render(<App />);

menuFix('texty');
