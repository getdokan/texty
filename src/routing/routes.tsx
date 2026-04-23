import { __ } from '@wordpress/i18n';
import Dashboard from '../pages/Dashboard';
import Notifications from '../pages/Notifications';
import SettingsPage from '../pages/SettingsPage';
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
    id: 'texty-tools',
    title: __('Tools', 'texty'),
    path: '/tools',
    element: <Tools />,
  },
  {
    id: 'texty-settings',
    title: __('Settings', 'texty'),
    path: '/settings',
    element: <SettingsPage />,
  },
];

export default routes;
