import NotificationGroup from './components/NotificationGroup';
import NotificationGroupSkeleton from './components/NotificationGroupSkeleton';
import { useNotifications } from './hooks/useNotifications';
import type { NotificationItem } from './types';

const USER_GROUP_ID = 'wp';

const UserEvents = () => {
  const { data, loading, handlePatch } = useNotifications();

  if (loading || !data) {
    return (
      <div className="flex flex-col gap-6">
        <NotificationGroupSkeleton rows={4} />
        <NotificationGroupSkeleton rows={3} />
      </div>
    );
  }

  const group = data.groups[USER_GROUP_ID];
  if (!group) {
    return null;
  }

  const items: NotificationItem[] = Object.values(data.notifications).filter(
    (n: NotificationItem) => n.group === USER_GROUP_ID
  );

  return (
    <div className="flex flex-col gap-6">
      <NotificationGroup
        groupId={USER_GROUP_ID}
        group={group}
        notifications={items}
        roles={data.roles}
        onPatch={handlePatch}
      />
    </div>
  );
};

export default UserEvents;
