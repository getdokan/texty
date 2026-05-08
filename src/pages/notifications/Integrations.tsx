import { __ } from '@wordpress/i18n';
import { Bell, Calendar, Repeat } from 'lucide-react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import IntegrationCard from './components/IntegrationCard';
import IntegrationCardSkeleton from './components/IntegrationCardSkeleton';
import { useNotifications } from './hooks/useNotifications';
import type { NotificationGroupInfo, NotificationItem } from './types';

type IntegrationMeta = {
  description: string;
  icon: ReactNode;
  iconBgClassName: string;
  iconColorClassName: string;
};

const assetUrl: string = window.texty?.asset_url ?? '';

const logoImg = (file: string, alt: string): ReactNode => (
  <img
    src={`${assetUrl}images/${file}`}
    alt={alt}
    className="size-6 object-contain"
  />
);

const META: Record<string, IntegrationMeta> = {
  wc: {
    description: __(
      'Get order status updates, low-stock alerts, and customer notifications across the WooCommerce store.',
      'texty'
    ),
    icon: logoImg('woocommerce.svg', __('WooCommerce', 'texty')),
    iconBgClassName: 'bg-violet-50',
    iconColorClassName: 'text-violet-600',
  },
  dokan: {
    description: __(
      'SMS notifications will be sent to vendors for new orders and withdrawals, and to admins for new vendor registrations.',
      'texty'
    ),
    icon: logoImg('dokan.svg', __('Dokan', 'texty')),
    iconBgClassName: 'bg-rose-50',
    iconColorClassName: 'text-rose-600',
  },
  'woo-subscriptions': {
    description: __(
      'Customers are notified to update card details on failed payments and for any subscription changes.',
      'texty'
    ),
    icon: <Repeat className="size-5 text-violet-600" />,
    iconBgClassName: 'bg-violet-100',
    iconColorClassName: 'text-violet-600',
  },
  'woo-bookings': {
    description: __(
      'Customers will receive reminders before their appointments, while admins and staff will be alerted of every new schedule slot.',
      'texty'
    ),
    icon: <Calendar className="size-5 text-violet-600" />,
    iconBgClassName: 'bg-violet-100',
    iconColorClassName: 'text-violet-600',
  },
};

const FALLBACK_META: IntegrationMeta = {
  description: __(
    'Notifications for this integration. Click Configure to manage events.',
    'texty'
  ),
  icon: <Bell className="size-5 text-foreground/70" />,
  iconBgClassName: 'bg-muted',
  iconColorClassName: 'text-foreground/70',
};

const Integrations = () => {
  const { data, loading } = useNotifications();
  const navigate = useNavigate();

  if (loading || !data) {
    return (
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
        {Array.from({ length: 6 }).map((_value: unknown, index: number) => (
          <IntegrationCardSkeleton key={index} />
        ))}
      </div>
    );
  }

  const integrationGroups: [string, NotificationGroupInfo][] = Object.entries(
    data.groups
  ).filter(([groupId]: [string, NotificationGroupInfo]) => groupId !== 'wp');

  if (integrationGroups.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {__('No integrations available yet.', 'texty')}
      </p>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
      {integrationGroups.map(
        ([groupId, group]: [string, NotificationGroupInfo]) => {
          const meta: IntegrationMeta = META[groupId] ?? FALLBACK_META;
          const eventCount: number = Object.values(data.notifications).filter(
            (n: NotificationItem) => n.group === groupId
          ).length;

          return (
            <IntegrationCard
              key={groupId}
              title={group.title}
              description={
                group.available
                  ? meta.description
                  : __('Plugin not installed.', 'texty')
              }
              icon={meta.icon}
              iconBgClassName={meta.iconBgClassName}
              iconColorClassName={meta.iconColorClassName}
              active={group.available && eventCount > 0}
              onConfigure={
                group.available
                  ? () => navigate(`/notifications/integrations/${groupId}`)
                  : undefined
              }
            />
          );
        }
      )}
    </div>
  );
};

export default Integrations;
