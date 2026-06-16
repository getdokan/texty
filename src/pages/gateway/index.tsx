import type { FieldComponentProps, SettingsElement } from '@wedevs/plugin-ui';
import { SettingsProvider, Skeleton, toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import {
  cloneElement,
  isValidElement,
  useCallback,
  useEffect,
  useState,
} from '@wordpress/element';
import { addFilter, applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';

import GatewayStatus from '@/components/GatewayStatus';

import PhoneField from '@/components/PhoneField';
import DetailPane from './DetailPane';
import Sidebar from './Sidebar';

// Register the custom `phone` variant once at module load. plugin-ui's
// FieldRenderer dispatches unknown variants through
// `${hookPrefix}_settings_<variant>_field` — for our `texty` prefix that
// resolves to `texty_settings_phone_field`. Returning a <PhoneField/> from
// the filter swaps the FallbackField for our phone-number input.
addFilter(
  'texty_settings_phone_field',
  'texty/gateway/phone-field',
  (defaultElement: ReactElement<FieldComponentProps>) => {
    if (!isValidElement(defaultElement)) return defaultElement;
    return cloneElement(<PhoneField {...defaultElement.props} />);
  }
);

type SchemaResponse = {
  schema: SettingsElement[];
  values: Record<string, unknown>;
  active_gateway: string;
  connected_gateways: string[];
};

type SaveResponse = {
  success?: boolean;
  values?: Record<string, unknown>;
  active_gateway?: string;
  connected?: string;
  errors?: Record<string, string>;
};

type LifecycleResponse = {
  success?: boolean;
  active_gateway?: string;
  disconnected?: string;
};

const formatErrors = (
  errors: Record<string, string> | undefined,
  fallback: string
): string => {
  if (!errors) return fallback;
  const messages: string[] = Object.values(errors).filter(
    (message: string) => typeof message === 'string' && message.trim() !== ''
  );
  return messages.length > 0 ? messages.join(', ') : fallback;
};

const extractErrorMessage = (err: unknown, fallback: string): string => {
  if (!err || typeof err !== 'object') return fallback;
  const e = err as {
    errors?: Record<string, string>;
    message?: string;
  };
  if (e.errors) return formatErrors(e.errors, fallback);
  if (typeof e.message === 'string' && e.message.trim() !== '') {
    return e.message;
  }
  return fallback;
};

const GatewayLoadingSkeleton = () => (
  <div className="flex flex-1 flex-col gap-4">
    {/* Top status card */}
    <div className="rounded-lg border border-border bg-background px-6 py-5 shadow-xs">
      <div className="flex items-center justify-between gap-4">
        <div className="space-y-2">
          <Skeleton className="h-3 w-28" />
          <Skeleton className="h-6 w-44" />
        </div>
        <Skeleton className="h-6 w-24 rounded-full" />
      </div>
    </div>

    {/* Two-column gateway card */}
    <div className="flex min-h-150 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-background md:flex-row">
      {/* Sidebar skeleton */}
      <aside className="flex w-full flex-col border-b border-border bg-background md:w-64 md:shrink-0 md:border-r md:border-b-0">
        <div className="px-4 pt-4">
          <Skeleton className="h-4 w-32" />
        </div>
        <div className="px-3 pt-3 pb-2">
          <Skeleton className="h-8 w-full rounded-md" />
        </div>
        <div className="flex flex-col gap-1 px-2 pb-3">
          {Array.from({ length: 5 }).map((_value: unknown, index: number) => (
            <div
              key={index}
              className="flex items-center justify-between gap-2 px-3 py-2"
            >
              <Skeleton className="h-3.5 w-24" />
              {index % 3 === 0 && (
                <Skeleton className="size-4 shrink-0 rounded-full" />
              )}
            </div>
          ))}
        </div>
      </aside>

      {/* Detail pane skeleton */}
      <div className="flex min-w-0 flex-1 flex-col">
        {/* Header */}
        <div className="flex items-start gap-3 px-6 pt-6 pb-5">
          <Skeleton className="size-10 shrink-0 rounded-md" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-5 w-32" />
            <Skeleton className="h-3 w-3/4" />
          </div>
        </div>

        {/* Section heading */}
        <div className="border-t border-border px-6 pt-5 pb-2">
          <Skeleton className="h-3.5 w-28" />
          <Skeleton className="mt-2 h-3 w-56" />
        </div>

        {/* Fields */}
        <div className="flex flex-col gap-4 px-6 py-4">
          {Array.from({ length: 4 }).map((_value: unknown, index: number) => (
            <div key={index} className="space-y-2">
              <Skeleton className="h-3 w-24" />
              <Skeleton className="h-9 w-full rounded-md" />
            </div>
          ))}
        </div>

        {/* Footer */}
        <div className="mt-auto flex items-center justify-end gap-2 border-t border-border px-6 py-3">
          <Skeleton className="h-9 w-24 rounded-md" />
        </div>
      </div>
    </div>
  </div>
);

const GatewayConfiguration = () => {
  const [schema, setSchema] = useState<SettingsElement[]>([]);
  const [activeGateway, setActiveGateway] = useState<string>('');
  const [connectedGateways, setConnectedGateways] = useState<string[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [initialPage, setInitialPage] = useState<string>('');

  const fetchSettings = useCallback(async (): Promise<void> => {
    const response = await apiFetch<SchemaResponse>({
      path: '/texty/v1/settings/schema',
      method: 'GET',
    });
    setSchema(response.schema ?? []);
    setActiveGateway(response.active_gateway ?? '');
    setConnectedGateways(response.connected_gateways ?? []);
  }, []);

  useEffect(() => {
    let cancelled = false;

    const loadSettings = async (): Promise<void> => {
      try {
        const response = await apiFetch<SchemaResponse>({
          path: '/texty/v1/settings/schema',
          method: 'GET',
        });
        if (cancelled) return;

        const incomingSchema: SettingsElement[] = response.schema ?? [];
        const incomingActive: string = response.active_gateway ?? '';

        setSchema(incomingSchema);
        setActiveGateway(incomingActive);
        setConnectedGateways(response.connected_gateways ?? []);
        setInitialPage(incomingActive || incomingSchema[0]?.id || '');
      } catch (err) {
        if (cancelled) return;
        console.error('Failed to load gateway settings', err);
        toast.error(__('Failed to load gateway settings.', 'texty'));
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    loadSettings();
    return () => {
      cancelled = true;
    };
  }, []);

  const handleSave = async (
    scopeId: string,
    _treeValues: Record<string, unknown>,
    flatValues: Record<string, unknown>
  ): Promise<void> => {
    try {
      const response = await apiFetch<SaveResponse>({
        path: '/texty/v1/settings/schema',
        method: 'POST',
        data: { scopeId, values: flatValues },
      });

      if (response.success) {
        await fetchSettings();
        toast.success(__('Credentials saved.', 'texty'));
      } else {
        toast.error(
          formatErrors(
            response.errors,
            __('Invalid credentials. Please check your input.', 'texty')
          )
        );
      }
    } catch (err: unknown) {
      console.error('Failed to save gateway', err);
      toast.error(
        extractErrorMessage(
          err,
          __('Invalid credentials. Please check your input.', 'texty')
        )
      );
    }
  };

  const handleActivate = async (gatewayKey: string): Promise<void> => {
    try {
      const response = await apiFetch<LifecycleResponse>({
        path: '/texty/v1/gateway/activate',
        method: 'POST',
        data: { gateway: gatewayKey },
      });
      if (response.success) {
        await fetchSettings();
        toast.success(__('Gateway activated.', 'texty'));
      }
    } catch (err: unknown) {
      console.error('Failed to activate gateway', err);
      toast.error(
        extractErrorMessage(err, __('Failed to activate gateway.', 'texty'))
      );
    }
  };

  const handleDeactivate = async (): Promise<void> => {
    try {
      const response = await apiFetch<LifecycleResponse>({
        path: '/texty/v1/gateway/deactivate',
        method: 'POST',
      });
      if (response.success) {
        await fetchSettings();
        toast.success(__('Gateway deactivated.', 'texty'));
      }
    } catch (err: unknown) {
      console.error('Failed to deactivate gateway', err);
      toast.error(
        extractErrorMessage(err, __('Failed to deactivate gateway.', 'texty'))
      );
    }
  };

  const handleDisconnect = async (gatewayKey: string): Promise<void> => {
    try {
      const response = await apiFetch<LifecycleResponse>({
        path: '/texty/v1/gateway/disconnect',
        method: 'POST',
        data: { gateway: gatewayKey },
      });
      if (response.success) {
        await fetchSettings();
        toast.success(__('Gateway disconnected.', 'texty'));
      }
    } catch (err: unknown) {
      console.error('Failed to disconnect gateway', err);
      toast.error(
        extractErrorMessage(err, __('Failed to disconnect gateway.', 'texty'))
      );
    }
  };

  if (loading) {
    return <GatewayLoadingSkeleton />;
  }

  const activePage: SettingsElement | undefined = schema.find(
    (element: SettingsElement) =>
      element.type === 'page' && element.id === activeGateway
  );
  const activeGatewayName: string =
    (activePage?.label as string | undefined) ?? '';

  return (
    <SettingsProvider
      schema={schema}
      onSave={handleSave}
      hookPrefix="texty"
      applyFilters={applyFilters}
      initialPage={initialPage}
    >
      <div className="flex flex-1 flex-col gap-4">
        <div className="rounded-lg border border-border bg-background px-6 py-5 shadow-xs">
          <div className="flex items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="m-0 text-sm text-muted-foreground">
                {__('Gateway Status', 'texty')}
              </p>
              <p className="m-0 mt-1 text-xl font-bold text-foreground">
                {activeGatewayName || __('No Gateway Connected', 'texty')}
              </p>
            </div>
            <GatewayStatus status={activeGateway ? 'active' : 'inactive'} />
          </div>
        </div>
        <div className="flex min-h-200 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-background md:flex-row p-2">
          <Sidebar connectedGateways={connectedGateways} />
          <DetailPane
            activeGateway={activeGateway}
            connectedGateways={connectedGateways}
            onActivate={handleActivate}
            onDeactivate={handleDeactivate}
            onDisconnect={handleDisconnect}
          />
        </div>
      </div>
    </SettingsProvider>
  );
};

export default GatewayConfiguration;
