import { Tabs, TabsContent, TabsList, TabsTrigger } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';

import Integrations from './Integrations';
import Settings from './Settings';
import UserEvents from './UserEvents';

const Notifications = () => {
  return (
    <div className="flex flex-col gap-6">
      <Tabs defaultValue="user-events">
        <TabsList className="bg-[#E2E2E7]">
          <TabsTrigger value="user-events">
            {__('User Events', 'texty')}
          </TabsTrigger>
          <TabsTrigger value="integrations">
            {__('Integrations', 'texty')}
          </TabsTrigger>
          <TabsTrigger value="settings">{__('Settings', 'texty')}</TabsTrigger>
        </TabsList>

        <TabsContent value="user-events" className="mt-4">
          <UserEvents />
        </TabsContent>

        <TabsContent value="integrations" className="mt-4">
          <Integrations />
        </TabsContent>

        <TabsContent value="settings" className="mt-4">
          <Settings />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default Notifications;
