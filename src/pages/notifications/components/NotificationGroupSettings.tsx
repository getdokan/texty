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
import { addQueryArgs } from '@wordpress/url';
import { Bell, Globe, ShoppingCart, Store, Zap } from 'lucide-react';
import type { ComponentType } from 'react';

import NotificationGroupSkeleton from './NotificationGroupSkeleton';

// Maps the backend `icon` string on the page element to a lucide component.
const ICON_MAP: Record<string, ComponentType<{ className?: string }>> = {
  Globe,
  ShoppingCart,
  Store,
  Bell,
  Zap,
};

const assetUrl: string = window.texty?.asset_url ?? '';

// Groups with a brand SVG logo (rendered instead of a lucide icon), keyed by
// group id. Files live in assets/images/.
const LOGO_MAP: Record<string, string> = {
  wc: 'woocommerce.svg',
  dokan: 'dokan.svg',
};

type SchemaResponse = {
  schema: SettingsElement[];
  values: Record<string, unknown>;
};

type SavePayload = Record<string, Record<string, unknown>>;

type Props =
  | {
      // Built-in notifications group (renders /notifications/schema?group=…).
      groupId: string;
      schemaPath?: never;
      savePath?: never;
    }
  | {
      // Or point at any endpoint returning { schema, values } (e.g. Texty Pro's
      // custom notification features). Both paths are required together.
      groupId?: never;
      schemaPath: string;
      savePath: string;
    };

// The full plugin-ui Settings schema is built on the server. This component only
// fetches it, renders it, and folds each collapsible card's children back into
// the per-id save payload — generic over any group/feature that follows the
// `<id>` + `<id>_<key>` / `<id>::<key>` field convention.
const NotificationGroupSettings = ({
  groupId,
  schemaPath,
  savePath,
}: Props) => {
  const fetchPath =
    schemaPath ??
    addQueryArgs('/texty/v1/notifications/schema', { group: groupId ?? '' });
  const postPath = savePath ?? '/texty/v1/notifications';
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
        const resp = await apiFetch<SchemaResponse>({ path: fetchPath });
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
  }, [fetchPath]);

  const handleChange = (
    _scopeId: string,
    key: string,
    value: unknown
  ): void => {
    setValues((prev: Record<string, unknown>) => ({ ...prev, [key]: value }));
  };

  // Each `collapsible_switch` field id is a record id; its children
  // (`<id>_message`, `<id>_recipients`, or `<id>::<key>`) are folded back into a
  // nested entry keyed by the part after the id + separator. Posts only this
  // group's ids — the backend merges them over the stored option.
  const handleSave = async (): Promise<void> => {
    if (!schema) {
      return;
    }

    const cur = values;
    const payload: SavePayload = {};

    for (const el of schema) {
      if (el.variant !== 'collapsible_switch') {
        continue;
      }

      const id = el.id;
      const entry: Record<string, unknown> = { enabled: Boolean(cur[id]) };

      for (const child of schema) {
        if (child.field_group_id !== id || child.variant === 'info') {
          continue;
        }

        const childId = String(child.id);
        const key = (
          childId.startsWith(id) ? childId.slice(id.length) : childId
        ).replace(/^(::|_)/, '');

        let value = cur[childId];
        if (child.variant === 'multicheck') {
          value = Array.isArray(value) ? value : [];
        } else if (key === 'message') {
          value = typeof value === 'string' ? value : '';
        }

        entry[key] = value;
      }

      payload[id] = entry;
    }

    setSaving(true);
    try {
      await apiFetch({ path: postPath, method: 'POST', data: payload });
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

  // Heading is rendered here (plugin-ui's built-in heading has no icon slot).
  // Add-ons can register icons/logos for their own groups via these filters.
  const page = schema.find((el: SettingsElement) => el.type === 'page');
  const iconMap = applyFilters(
    'texty_notification_icon_map',
    ICON_MAP
  ) as Record<string, ComponentType<{ className?: string }>>;
  const logoMap = applyFilters(
    'texty_notification_logo_map',
    LOGO_MAP
  ) as Record<string, string>;
  const HeaderIcon = iconMap[String(page?.icon ?? '')] ?? Bell;
  const logoFile = logoMap[String(page?.id ?? '')];
  const title = page?.label ?? '';
  const description = page?.description ?? '';

  return (
    <div className="flex flex-col gap-2 bg-white rounded-lg">
      <div className="flex items-center gap-3 px-6 py-4">
        <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-gray-100">
          {logoFile ? (
            <img
              src={`${assetUrl}images/${logoFile}`}
              alt={title}
              className="size-6 object-contain"
            />
          ) : (
            <HeaderIcon className="size-5 text-gray-700" />
          )}
        </div>
        <div className="min-w-0">
          <h2 className="m-0 text-2xl font-bold leading-tight text-foreground">
            {title}
          </h2>
          {description && (
            <p className="m-0 text-sm text-muted-foreground">{description}</p>
          )}
        </div>
      </div>

      <Settings
        // Remount on any identity change so form state never leaks between
        // groups or custom endpoints. fetchPath already folds in groupId.
        key={`${fetchPath}::${postPath}`}
        schema={schema}
        values={values}
        onChange={handleChange}
        onSave={handleSave}
        hookPrefix="texty"
        applyFilters={applyFilters}
        searchable={false}
        className="border-t rounded-none"
        renderSaveButton={({ dirty, onSave }) => (
          <Button onClick={onSave} disabled={!dirty || saving}>
            {saving ? __('Saving…', 'texty') : __('Save Changes', 'texty')}
          </Button>
        )}
      />
    </div>
  );
};

export default NotificationGroupSettings;
