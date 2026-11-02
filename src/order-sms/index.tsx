import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import OrderSmsBox from './components/OrderSmsBox';
import type { OrderSmsConfig } from './types';

// Mounts the "Send SMS" box rendered by Texty\Admin\OrderSmsBox on the
// WooCommerce order edit screen; the server passes its data as JSON.
domReady(() => {
  const mount: HTMLElement | null = document.getElementById(
    'texty-order-sms-root'
  );

  if (!mount?.dataset.config) {
    return;
  }

  let config: OrderSmsConfig;

  try {
    config = JSON.parse(mount.dataset.config) as OrderSmsConfig;
  } catch (err) {
    console.error('Texty: invalid order SMS config', err);
    return;
  }

  createRoot(mount).render(<OrderSmsBox config={config} />);
});
