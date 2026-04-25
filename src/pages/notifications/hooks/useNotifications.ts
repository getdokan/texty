import { toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';

import type {
  NotificationItem,
  NotificationPatch,
  NotificationsResponse,
} from '../types';

type SavePayload = Record<
  string,
  {
    enabled: boolean;
    message: string;
    recipients: string[];
    route: string;
  }
>;

const buildPayload = (
  notifications: Record<string, NotificationItem>
): SavePayload => {
  const payload: SavePayload = {};
  for (const item of Object.values(notifications)) {
    payload[item.id] = {
      enabled: item.enabled,
      message: item.message,
      recipients: Array.isArray(item.recipients) ? item.recipients : [],
      route: item.route,
    };
  }
  return payload;
};

type UseNotifications = {
  data: NotificationsResponse | null;
  loading: boolean;
  handlePatch: (id: string, patch: NotificationPatch) => Promise<void>;
};

export const useNotifications = (): UseNotifications => {
  const [data, setData] = useState<NotificationsResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const dataRef = useRef<NotificationsResponse | null>(null);
  dataRef.current = data;

  useEffect(() => {
    let cancelled = false;
    const load = async (): Promise<void> => {
      try {
        const response = await apiFetch<NotificationsResponse>({
          path: '/texty/v1/notifications?context=edit',
          method: 'GET',
        });
        if (cancelled) {
          return;
        }
        setData(response);
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error('Failed to load notifications', err);
        toast.error(__('Failed to load notifications.', 'texty'));
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  const handlePatch = useCallback(
    async (id: string, patch: NotificationPatch): Promise<void> => {
      const prev = dataRef.current;
      if (!prev) {
        return;
      }
      const next: NotificationsResponse = {
        ...prev,
        notifications: {
          ...prev.notifications,
          [id]: { ...prev.notifications[id], ...patch },
        },
      };
      setData(next);
      try {
        await apiFetch({
          path: '/texty/v1/notifications',
          method: 'POST',
          data: buildPayload(next.notifications),
        });
        toast.success(__('Changes saved.', 'texty'));
      } catch (err) {
        console.error('Failed to save notification', err);
        setData(prev);
        const errWithMessage = err as { message?: string };
        toast.error(
          errWithMessage.message ?? __('Failed to save changes.', 'texty')
        );
      }
    },
    []
  );

  return { data, loading, handlePatch };
};
