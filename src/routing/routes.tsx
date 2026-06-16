import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ComponentType, ReactElement } from 'react';
import type { TextyRoute } from './index';

const Dashboard = lazy(
  () => import(/* webpackChunkName: "dashboard" */ '../pages/dashboard')
);
const Notifications = lazy(
  () => import(/* webpackChunkName: "notifications" */ '../pages/notifications')
);
const IntegrationDetail = lazy(
  () =>
    import(
      /* webpackChunkName: "integration-detail" */ '../pages/notifications/IntegrationDetail'
    )
);
const Gateway = lazy(
  () => import(/* webpackChunkName: "gateway" */ '../pages/gateway')
);
const Logs = lazy(() => import(/* webpackChunkName: "logs" */ '../pages/logs'));

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
    id: 'texty-notifications-integration',
    title: __('Integration', 'texty'),
    path: '/notifications/integrations/:integrationId',
    element: withSuspense(IntegrationDetail),
  },
  {
    id: 'texty-logs',
    title: __('Logs', 'texty'),
    path: '/logs',
    element: withSuspense(Logs),
  },
  {
    id: 'texty-gateway',
    title: __('Gateway Configuration', 'texty'),
    path: '/gateway',
    element: withSuspense(Gateway),
  },
];

export default routes;
