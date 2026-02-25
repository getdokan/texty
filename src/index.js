import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { ThemeProvider } from '@wedevs/plugin-ui';

import menuFix from './utils/admin-menu-fix';
import App from './App';
import '@wedevs/plugin-ui/styles.css';
import 'react-toastify/dist/ReactToastify.css';
import 'react-phone-input-2/lib/style.css';
import './style.scss';
import './app.css';

// WordPress default blue theme for Texty (#2271b1)
const textyTheme = {
    background: 'oklch(1 0 0)',
    foreground: 'oklch(0.1450 0 0)',
    card: 'oklch(1 0 0)',
    cardForeground: 'oklch(0.1450 0 0)',
    popover: 'oklch(1 0 0)',
    popoverForeground: 'oklch(0.1450 0 0)',
    primary: 'oklch(0.5200 0.1300 248.0000)',
    primaryForeground: 'oklch(1 0 0)',
    secondary: 'oklch(0.9700 0 0)',
    secondaryForeground: 'oklch(0.5200 0.1300 248.0000)',
    muted: 'oklch(0.9700 0 0)',
    mutedForeground: 'oklch(0.5560 0 0)',
    accent: 'oklch(0.5200 0.1300 248.0000)',
    accentForeground: 'oklch(1 0 0)',
    destructive: 'oklch(0.5770 0.2450 27.3250)',
    destructiveForeground: 'oklch(1 0 0)',
    success: 'oklch(0.5200 0.1300 248.0000)',
    successForeground: 'oklch(1 0 0)',
    border: 'oklch(0.9222 0 0)',
    input: 'oklch(0.9222 0 0)',
    ring: 'oklch(0.5200 0.1300 248.0000)',
    radius: '0.625rem',
};

domReady(() => {

    const mountNode = document.getElementById('texty-app');
    const root = createRoot(mountNode);
    root.render(
        <ThemeProvider pluginId="texty" tokens={textyTheme}>
            <App />
        </ThemeProvider>
    );

    menuFix('texty');
});
