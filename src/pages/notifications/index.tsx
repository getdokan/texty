import { Tabs, TabsContent, TabsList, TabsTrigger } from '@wedevs/plugin-ui';
import { useMemo } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';

import Integrations from './Integrations';
import Settings from './Settings';
import UserEvents from './UserEvents';

type NotificationTab = {
  value: string;
  label: string;
  content: ReactNode;
};

const TAB_PARAM = 'tab';

const Notifications = () => {
  const [searchParams, setSearchParams] = useSearchParams();

  const tabs = useMemo<NotificationTab[]>(
    () => [
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
    ],
    [],
  );

  const requested = searchParams.get(TAB_PARAM);
  const activeTab = tabs.some((tab) => tab.value === requested)
    ? (requested as string)
    : (tabs[0]?.value ?? '');

  const handleTabChange = (value: string): void => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        next.set(TAB_PARAM, value);
        return next;
      },
      { replace: true },
    );
  };

  return (
    <div className="flex flex-col gap-6">
      <Tabs value={activeTab} onValueChange={handleTabChange}>
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
