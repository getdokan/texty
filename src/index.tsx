import { ThemeProvider, type ThemeTokens } from '@wedevs/plugin-ui';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

// plugin-ui's stylesheet ships with the shared `plugin-ui` entry
// (dist/plugin-ui.css, handle `texty-plugin-ui`) — importing it here too would
// emit a second 300KB+ copy into dist/index.css. Texty\Admin\Menu pulls it in
// as a dependency of `texty-vendor-css`.
import 'react-phone-input-2/lib/style.css';
import App from './App';
import Header from './components/Header';
import './styles/app.css';
import './styles/style.scss';
import './tailwind.css';
import menuFix from './utils/admin-menu-fix';

// WordPress default blue theme for Texty (#2271b1)
const textyTheme: ThemeTokens = {
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
  destructive: 'oklch(0.5770 0.2450 27.3250)',
  destructiveForeground: 'oklch(1 0 0)',
  success: 'oklch(0.5200 0.1300 248.0000)',
  successForeground: 'oklch(1 0 0)',
  border: 'oklch(0.9222 0 0)',
  input: 'oklch(0.9222 0 0)',
  ring: 'oklch(0.5200 0.1300 248.0000)',
  radius: '0.625rem',
};

// Admin notices are captured into a hidden wrapper by `Texty\Admin\Menu`. Move
// it into the slot under the header and unhide it. This runs at script-eval
// time — before core's `DOMContentLoaded` pass, which appends stray notices
// after the first `.wp-header-end` — so the catcher is already in its final
// position when core relocates them.
const noticeList = document.getElementById('texty__notice-list');
const noticeSlot = document.getElementById('texty-notices');
if (noticeList && noticeSlot) {
  noticeSlot.appendChild(noticeList);
  noticeList.classList.remove('texty-notice-list-hide');
}

domReady(() => {
  // The header mounts in its own root, outside the router, so it sits above
  // the notice slot in the DOM: header → notices → app.
  const headerNode = document.getElementById('texty-header');
  if (headerNode) {
    createRoot(headerNode).render(
      <ThemeProvider pluginId="texty" className="texty-app" tokens={textyTheme}>
        <Header />
      </ThemeProvider>
    );
  }

  const mountNode = document.getElementById('texty-app');
  if (!mountNode) {
    return;
  }

  const root = createRoot(mountNode);
  root.render(
    <ThemeProvider pluginId="texty" className="texty-app" tokens={textyTheme}>
      <App />
    </ThemeProvider>
  );

  menuFix('texty');
});
