import Settings from '@/pages/Settings';
import { __ } from '@wordpress/i18n';
import Dashboard from '../pages/dashboard';
import GatewayConfiguration from '../pages/gateway';
import Notifications from '../pages/notifications';
import IntegrationDetail from '../pages/notifications/IntegrationDetail';
import OldNotifications from '../pages/NotificationsOld';
import Tools from '../pages/Tools';
import type { TextyRoute } from './index';

const routes: TextyRoute[] = [
  {
    id: 'texty-dashboard',
    title: __('Dashboard', 'texty'),
    path: '/dashboard',
    element: <Dashboard />,
  },
  {
    id: 'texty-notifications',
    title: __('Notifications', 'texty'),
    path: '/notifications',
    element: <Notifications />,
  },
  {
    id: 'texty-notifications-integration',
    title: __('Integration', 'texty'),
    path: '/notifications/integrations/:integrationId',
    element: <IntegrationDetail />,
  },
  {
    id: 'texty-tools',
    title: __('Tools', 'texty'),
    path: '/tools',
    element: <Tools />,
  },
  {
    id: 'texty-gateway',
    title: __('Gateway Configuration', 'texty'),
    path: '/gateway',
    element: <GatewayConfiguration />,
  },
  {
    id: 'texty-settings',
    title: __('Settings', 'texty'),
    path: '/settings',
    element: <Settings />,
  },
  {
    id: 'texty-old-notifications',
    title: __('Old Notifications', 'texty'),
    path: '/old-notifications',
    element: <OldNotifications />,
  },
];

export default routes;
