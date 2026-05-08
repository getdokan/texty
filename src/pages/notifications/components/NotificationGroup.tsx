import { __ } from '@wordpress/i18n';
import { Globe, ShoppingCart, Store } from 'lucide-react';
import type { ComponentType } from 'react';

import type {
  NotificationGroupInfo,
  NotificationItem,
  NotificationPatch,
  RoleOption,
} from '../types';
import NotificationRow from './NotificationRow';

type Props = {
  groupId: string;
  group: NotificationGroupInfo;
  notifications: NotificationItem[];
  roles: RoleOption[];
  onPatch: (id: string, patch: NotificationPatch) => Promise<void> | void;
};

type IconComponent = ComponentType<{ className?: string }>;

const GROUP_ICONS: Record<string, IconComponent> = {
  wp: Globe,
  wc: ShoppingCart,
  dokan: Store,
};

const GROUP_SUBTITLES: Record<string, string> = {
  wp: __('Default WordPress system alerts', 'texty'),
  wc: __('WooCommerce order and customer alerts', 'texty'),
  dokan: __('Vendor and marketplace alerts', 'texty'),
};

const NotificationGroup = ({
  groupId,
  group,
  notifications,
  roles,
  onPatch,
}: Props) => {
  const Icon: IconComponent = GROUP_ICONS[groupId] ?? Globe;
  const subtitle: string = GROUP_SUBTITLES[groupId] ?? group.description;

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-background shadow-xs">
      <div className="flex items-center gap-3 px-6 py-5">
        <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted">
          <Icon className="size-5 text-foreground/70" />
        </div>
        <div className="min-w-0">
          <h3 className="m-0 text-base font-bold text-foreground">
            {group.title}
          </h3>
          {subtitle && (
            <p className="m-0 text-xs text-muted-foreground">{subtitle}</p>
          )}
        </div>
        {!group.available && (
          <span className="ml-auto rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
            {__('Plugin not installed', 'texty')}
          </span>
        )}
      </div>

      {group.available &&
        notifications.map((item: NotificationItem) => (
          <NotificationRow
            key={item.id}
            item={item}
            roles={roles}
            onPatch={onPatch}
          />
        ))}
    </div>
  );
};

export default NotificationGroup;
