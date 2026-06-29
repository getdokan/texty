/**
 * Public component surface for add-ons.
 *
 * Built as the `components` webpack entry (library `textyComponents`, handle
 * `texty-components`) and consumed by add-ons via `import { … } from
 * '@texty/components'`, which webpack externalizes to the shared global.
 */
export { default as SchemaSettings } from '../pages/notifications/components/NotificationGroupSettings';
