import { __ } from '@wordpress/i18n';
import { ChevronLeft } from 'lucide-react';
import { useNavigate, useParams } from 'react-router-dom';

import NotificationGroup from './components/NotificationGroup';
import NotificationGroupSkeleton from './components/NotificationGroupSkeleton';
import type { NotificationItem } from './types';
import { useNotifications } from './hooks/useNotifications';

const IntegrationDetail = () => {
  const params = useParams();
  const navigate = useNavigate();
  const integrationId: string = params.integrationId ?? '';

  const { data, loading, handlePatch } = useNotifications();

  const renderHeader = (title: string) => (
    <div className="flex items-center gap-3">
      <button
        type="button"
        onClick={() => navigate('/notifications')}
        className="inline-flex cursor-pointer items-center gap-1 bg-transparent p-0 text-sm text-muted-foreground hover:text-foreground"
      >
        <ChevronLeft className="size-4" />
        {__('Back to Notifications', 'texty')}
      </button>
      <span className="text-muted-foreground">/</span>
      <h1 className="m-0 text-xl font-bold text-foreground">{title}</h1>
    </div>
  );

  if (loading || !data) {
    return (
      <div className="flex flex-col gap-6">
        {renderHeader(__('Loading…', 'texty'))}
        <NotificationGroupSkeleton rows={4} />
      </div>
    );
  }

  const group = data.groups[integrationId];
  if (!group) {
    return (
      <div className="flex flex-col gap-6">
        {renderHeader(__('Unknown integration', 'texty'))}
        <p className="text-sm text-muted-foreground">
          {__('This integration is not available.', 'texty')}
        </p>
      </div>
    );
  }

  const items: NotificationItem[] = Object.values(data.notifications).filter(
    (n: NotificationItem) => n.group === integrationId
  );

  return (
    <div className="flex flex-col gap-6">
      {renderHeader(group.title)}

      {items.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {__('No events for this integration.', 'texty')}
        </p>
      ) : (
        <NotificationGroup
          groupId={integrationId}
          group={group}
          notifications={items}
          roles={data.roles}
          onPatch={handlePatch}
        />
      )}
    </div>
  );
};

export default IntegrationDetail;
