import {
  Button,
  Settings,
  toast,
  type SettingsElement,
} from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import NotificationGroupSkeleton from './NotificationGroupSkeleton';

// Separator for child field ids — `<id>::message`, `<id>::recipients`.
const SEP = '::';

type SchemaResponse = {
  schema: SettingsElement[];
  values: Record<string, unknown>;
};

type SavePayloadEntry = {
  enabled: boolean;
  message: string;
  recipients?: string[];
};

type SavePayload = Record<string, SavePayloadEntry>;

type Props = {
  groupId: string;
};

// The full plugin-ui Settings schema is built on the server
// (`GET /texty/v1/notifications/schema?group=…`). This component only fetches
// it, renders it, and maps edits back into the save payload.
const NotificationGroupSettings = ({ groupId }: Props) => {
  const [schema, setSchema] = useState<SettingsElement[] | null>(null);
  // plugin-ui <Settings> is controlled for `values` (external values take
  // precedence over its internal edits), so edits must be lifted into state
  // and fed back through the `values` prop — a ref alone never re-renders.
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);

  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      setLoading(true);
      try {
        const resp = await apiFetch<SchemaResponse>({
          path: `/texty/v1/notifications/schema?group=${encodeURIComponent(groupId)}`,
        });
        if (cancelled) {
          return;
        }
        setSchema(resp.schema);
        setValues({ ...resp.values });
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error('Failed to load notification schema', err);
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
  }, [groupId]);

  const handleChange = (
    _scopeId: string,
    key: string,
    value: unknown,
  ): void => {
    setValues((prev: Record<string, unknown>) => ({ ...prev, [key]: value }));
  };

  // Each `collapsible_switch` field id is a notification id; only role-type
  // notifications carry a recipients child. Posts only this group's ids — the
  // backend merges them over the stored option, preserving the other groups.
  const handleSave = async (): Promise<void> => {
    if (!schema) {
      return;
    }

    const cur = values;
    const roleIds = new Set<string>(
      schema
        .filter((el: SettingsElement) => el.variant === 'multicheck')
        .map((el: SettingsElement) => String(el.field_group_id)),
    );

    const payload: SavePayload = {};
    for (const el of schema) {
      if (el.variant !== 'collapsible_switch') {
        continue;
      }

      const id = el.id;
      const message = cur[`${id}${SEP}message`];
      const entry: SavePayloadEntry = {
        enabled: Boolean(cur[id]),
        message: typeof message === 'string' ? message : '',
      };

      if (roleIds.has(id)) {
        const recipients = cur[`${id}${SEP}recipients`];
        entry.recipients = Array.isArray(recipients)
          ? (recipients as string[])
          : [];
      }

      payload[id] = entry;
    }

    setSaving(true);
    try {
      await apiFetch({
        path: '/texty/v1/notifications',
        method: 'POST',
        data: payload,
      });
      toast.success(__('Changes saved.', 'texty'));
    } catch (err) {
      console.error('Failed to save notifications', err);
      toast.error(__('Failed to save changes.', 'texty'));
    } finally {
      setSaving(false);
    }
  };

  if (loading || !schema) {
    return (
      <div className="flex flex-col gap-6">
        <NotificationGroupSkeleton rows={4} />
      </div>
    );
  }

  return (
    <Settings
      key={groupId}
      schema={schema}
      values={values}
      onChange={handleChange}
      onSave={handleSave}
      hookPrefix="texty"
      applyFilters={applyFilters}
      searchable={false}
      renderSaveButton={({ dirty, onSave }) => (
        <Button onClick={onSave} disabled={!dirty || saving}>
          {saving ? __('Saving…', 'texty') : __('Save Changes', 'texty')}
        </Button>
      )}
    />
  );
};

export default NotificationGroupSettings;
