/**
 * Public component surface for add-ons.
 *
 * Built as the `components` webpack entry (library `textyComponents`, handle
 * `texty-components`) and consumed by add-ons via `import { … } from
 * '@texty/components'`, which webpack externalizes to the shared global.
 *
 * PhoneField wraps react-phone-input-2; its base stylesheet is imported here
 * so the components entry emits its own `dist/components.css`. Add-ons that use
 * PhoneField on storefront surfaces (where the admin SPA sheet never loads)
 * pull it in by depending on the `texty-components` style handle.
 *
 * The `?texty-components` resource query is load-bearing: the admin `index.tsx`
 * imports the same `react-phone-input-2/lib/style.css`, and without a distinct
 * request webpack would extract the single shared module into `style-index.css`
 * (leaving no components sheet). The query makes this a distinct module so the
 * components entry gets its own extracted stylesheet.
 */
import 'react-phone-input-2/lib/style.css?texty-components';

export { default as NotificationGroupSettings } from '../pages/notifications/components/NotificationGroupSettings';
export { default as PhoneField } from './PhoneField';
