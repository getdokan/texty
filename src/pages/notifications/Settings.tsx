import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
  Button,
  Card,
  Input,
  Label,
  Modal,
  ModalDescription,
  ModalFooter,
  ModalHeader,
  ModalTitle,
  Spinner,
  Switch,
  cn,
  toast,
} from '@wedevs/plugin-ui';
import { Info, Settings as SettingsIcon, ShieldCheck } from 'lucide-react';
import type { ChangeEvent, ReactNode } from 'react';

type SettingsState = {
  admin_phone: string;
  global_sender_id: string;
  pause_all: boolean;
  append_company_name: boolean;
};

type SettingsResponse = {
  settings: SettingsState;
  opted_out: string[];
};

const defaultState: SettingsState = {
  admin_phone: '',
  global_sender_id: '',
  pause_all: false,
  append_company_name: false,
};

const getStoreName = (): string => {
  const localized = (window as unknown as { texty?: { site_name?: string } })
    .texty;
  return localized?.site_name ?? '';
};

type SectionHeaderProps = {
  icon: ReactNode;
  title: string;
  description: string;
};

const SectionHeader = ({ icon, title, description }: SectionHeaderProps) => (
  <div className="flex items-start gap-4 px-6 py-5">
    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white">
      {icon}
    </div>
    <div className="flex flex-col">
      <h3 className="m-0 text-base font-semibold text-gray-900">{title}</h3>
      <p className="m-0 text-sm text-gray-500">{description}</p>
    </div>
  </div>
);

type ToggleRowProps = {
  title: string;
  description: string;
  checked: boolean;
  onChange: (value: boolean) => void;
  muted?: boolean;
};

const ToggleRow = ({
  title,
  description,
  checked,
  onChange,
  muted,
}: ToggleRowProps) => (
  <div
    className={cn(
      'flex items-center justify-between gap-4 border-t border-gray-200 px-6 py-5',
      muted && 'bg-gray-50',
    )}
  >
    <div className="flex flex-col">
      <p className="m-0 text-sm font-semibold text-gray-900">{title}</p>
      <p className="m-0 text-sm text-gray-500">{description}</p>
    </div>
    <Switch checked={checked} onCheckedChange={onChange} aria-label={title} />
  </div>
);

const Settings = () => {
  const [state, setState] = useState<SettingsState>(defaultState);
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [confirmPause, setConfirmPause] = useState<boolean>(false);

  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      setLoading(true);
      try {
        const resp = await apiFetch<SettingsResponse>({
          path: '/texty/v1/notification-settings',
        });
        if (cancelled) {
          return;
        }
        setState({ ...defaultState, ...resp.settings });
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error(err);
        toast.error(__('Failed to load settings.', 'texty'));
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

  const update = <K extends keyof SettingsState>(
    key: K,
    value: SettingsState[K],
  ): void => {
    setState((prev) => ({ ...prev, [key]: value }));
  };

  const onSenderIdChange = (e: ChangeEvent<HTMLInputElement>): void => {
    update('global_sender_id', e.target.value);
  };

  const onSave = async (): Promise<void> => {
    setSaving(true);
    try {
      const resp = await apiFetch<SettingsResponse>({
        path: '/texty/v1/notification-settings',
        method: 'POST',
        data: state,
      });
      setState({ ...defaultState, ...resp.settings });
      toast.success(__('Settings saved.', 'texty'));
    } catch (err) {
      console.error(err);
      toast.error(__('Failed to save settings.', 'texty'));
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <Card className="gap-0 py-0">
        <SectionHeader
          icon={<SettingsIcon className="size-5 text-gray-700" />}
          title={__('General Settings', 'texty')}
          description={__(
            'Global configuration for all notifications.',
            'texty',
          )}
        />

        <div className="flex flex-col gap-2 border-t border-gray-200 px-6 py-5">
          <Label
            htmlFor="texty-admin-phone"
            className="text-sm font-semibold text-gray-900"
          >
            {__('Admin Phone Number', 'texty')}
          </Label>
          <Input
            id="texty-admin-phone"
            name="admin-phone"
            value={state.admin_phone}
            readOnly
            disabled
            placeholder={__('Not configured', 'texty')}
          />
          <p className="m-0 flex items-center gap-2 text-sm text-gray-500">
            <Info className="size-4 text-gray-400" />
            {__(
              'Pulled from the active gateway’s "From Number". Edit it under Gateway settings.',
              'texty',
            )}
          </p>
        </div>

        <div className="flex flex-col gap-2 border-t border-gray-200 px-6 py-5">
          <Label
            htmlFor="texty-sender-id"
            className="text-sm font-semibold text-gray-900"
          >
            {__('Global Sender ID (Fallback)', 'texty')}
          </Label>
          <Input
            id="texty-sender-id"
            value={state.global_sender_id}
            onChange={onSenderIdChange}
          />
          <p className="m-0 flex items-center gap-2 text-sm text-gray-500">
            <Info className="size-4 text-gray-400" />
            {__(
              "Used if a specific gateway doesn't support dynamic Sender IDs.",
              'texty',
            )}
          </p>
        </div>

        <ToggleRow
          muted
          title={__('Pause All Notifications', 'texty')}
          description={__('Temporarily disable all outgoing SMS.', 'texty')}
          checked={state.pause_all}
          onChange={(v) => {
            if (v) {
              setConfirmPause(true);
              return;
            }
            update('pause_all', false);
          }}
        />
      </Card>

      <Card className="gap-0 py-0">
        <SectionHeader
          icon={<ShieldCheck className="size-5 text-gray-700" />}
          title={__('Compliance & Footer', 'texty')}
          description={__('Manage legally required text additions.', 'texty')}
        />

        <ToggleRow
          title={__('Append Company Name', 'texty')}
          description={sprintf(
            /* translators: %s: store / site name */
            __('Adds "from %s" at the end of messages.', 'texty'),
            getStoreName() || __('your store', 'texty'),
          )}
          checked={state.append_company_name}
          onChange={(v) => update('append_company_name', v)}
        />
      </Card>

      <div className="flex justify-end">
        <Button onClick={onSave} disabled={saving}>
          {saving ? __('Saving…', 'texty') : __('Save Settings', 'texty')}
        </Button>
      </div>

      <Modal
        open={confirmPause}
        onClose={() => setConfirmPause(false)}
        size="default"
      >
        <ModalHeader>
          <ModalTitle>
            {__('Are you sure to Pause All Notifications SMS?', 'texty')}
          </ModalTitle>
        </ModalHeader>
        <ModalDescription>
          {__(
            'Pausing this will stop all SMS notifications on your channels. You can resume them at any time by switching the toggle back on.',
            'texty',
          )}
        </ModalDescription>
        <ModalFooter>
          <Button variant="outline" onClick={() => setConfirmPause(false)}>
            {__('Cancel', 'texty')}
          </Button>
          <Button
            onClick={() => {
              update('pause_all', true);
              setConfirmPause(false);
            }}
          >
            {__('Yes, Pause', 'texty')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  );
};

export default Settings;
