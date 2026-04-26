import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ComponentType, ReactElement } from 'react';
import type { TextyRoute } from './index';

const Dashboard = lazy(
  () => import(/* webpackChunkName: "dashboard" */ '../pages/dashboard')
);
const Notifications = lazy(
  () => import(/* webpackChunkName: "notifications" */ '../pages/Notifications')
);
const Gateway = lazy(
  () => import(/* webpackChunkName: "gateway" */ '../pages/gateway')
);
const Tools = lazy(
  () => import(/* webpackChunkName: "tools" */ '../pages/Tools')
);
const SettingsPage = lazy(
  () => import(/* webpackChunkName: "settings" */ '../pages/Settings')
);

const RouteFallback = () => (
  <div className="flex items-center justify-center p-8 text-sm text-gray-500">
    {__('Loading…', 'texty')}
  </div>
);

const withSuspense = (Component: ComponentType): ReactElement => (
  <Suspense fallback={<RouteFallback />}>
    <Component />
  </Suspense>
);

const routes: TextyRoute[] = [
  {
    id: 'texty-dashboard',
    title: __('Dashboard', 'texty'),
    path: '/dashboard',
    element: withSuspense(Dashboard),
  },
  {
    id: 'texty-notifications',
    title: __('Notifications', 'texty'),
    path: '/notifications',
    element: withSuspense(Notifications),
  },
  {
    id: 'texty-tools',
    title: __('Tools', 'texty'),
    path: '/tools',
    element: withSuspense(Tools),
  },
  {
    id: 'texty-gateway',
    title: __('Gateway Configuration', 'texty'),
    path: '/gateway',
    element: withSuspense(Gateway),
  },
  {
    id: 'texty-settings',
    title: __('Settings', 'texty'),
    path: '/settings',
    element: withSuspense(SettingsPage),
  },
];

export default routes;
