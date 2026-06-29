import { Tabs, TabsContent, TabsList, TabsTrigger } from '@wedevs/plugin-ui';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { type ReactNode } from 'react';

import Integrations from './Integrations';
import Settings from './Settings';
import UserEvents from './UserEvents';

type NotificationTab = {
  value: string;
  label: string;
  content: ReactNode;
};

const Notifications = () => {
  const tabs = [
    {
      value: 'user-events',
      label: __('User Events', 'texty'),
      content: <UserEvents />,
    },
    {
      value: 'integrations',
      label: __('Integrations', 'texty'),
      content: <Integrations />,
    },
    ...(applyFilters('texty_notifications_tabs', []) as NotificationTab[]),
    {
      value: 'settings',
      label: __('Settings', 'texty'),
      content: <Settings />,
    },
  ] as NotificationTab[];

  return (
    <div className="flex flex-col gap-6">
      <Tabs defaultValue={tabs[0]?.value}>
        <TabsList className="bg-[#E2E2E7]">
          {tabs.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {tabs.map((tab) => (
          <TabsContent key={tab.value} value={tab.value} className="mt-4">
            {tab.content}
          </TabsContent>
        ))}
      </Tabs>
    </div>
  );
};

export default Notifications;
