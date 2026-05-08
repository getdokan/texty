import type { SettingsElement } from '@wedevs/plugin-ui';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  useSettings,
} from '@wedevs/plugin-ui';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Loader2, X } from 'lucide-react';

import Header from './Header';
import Section from './Section';

type PendingAction =
  | 'connect'
  | 'disconnect'
  | 'activate'
  | 'deactivate'
  | null;

type Props = {
  activeGateway: string;
  connectedGateways: string[];
  onActivate: (gatewayKey: string) => Promise<void> | void;
  onDeactivate: () => Promise<void> | void;
  onDisconnect: (gatewayKey: string) => Promise<void> | void;
};

const DetailPane = ({
  activeGateway,
  connectedGateways,
  onActivate,
  onDeactivate,
  onDisconnect,
}: Props) => {
  const {
    activePage,
    activeSubpage,
    getActiveContentSource,
    getActiveContent,
    isPageDirty,
    hasScopeErrors,
    getPageValues,
    save,
  } = useSettings();

  const [pending, setPending] = useState<PendingAction>(null);
  const [confirmDeactivate, setConfirmDeactivate] = useState<boolean>(false);

  const source: SettingsElement | undefined = getActiveContentSource();
  const content: SettingsElement[] = getActiveContent();
  const scopeId: string = activeSubpage || activePage;
  const hasErrors: boolean = hasScopeErrors(scopeId);
  const dirty: boolean = isPageDirty(scopeId);

  if (!source) {
    return (
      <div className="flex-1 rounded-lg border border-border bg-background" />
    );
  }

  const connected: boolean = connectedGateways.includes(source.id);
  const isActive: boolean = source.id === activeGateway;
  const busy: boolean = pending !== null;

  // A gateway with no field-bearing sections (e.g. Fake) needs no credentials.
  // Skip the Connect/Disconnect step and let the user activate directly.
  const hasFields: boolean = content.some(
    (item: SettingsElement) =>
      item.type === 'section' &&
      Array.isArray(item.children) &&
      item.children.length > 0
  );

  const handleConnect = async (): Promise<void> => {
    if (!save || hasErrors || busy) return;
    setPending('connect');
    try {
      await save(scopeId, getPageValues(scopeId));
    } finally {
      setPending(null);
    }
  };

  const handleActivateClick = async (): Promise<void> => {
    if (hasErrors || busy) return;
    setPending('activate');
    try {
      if (dirty && save) {
        await save(scopeId, getPageValues(scopeId));
      }
      await onActivate(source.id);
    } finally {
      setPending(null);
    }
  };

  const handleDeactivateClick = (): void => {
    if (busy) return;
    setConfirmDeactivate(true);
  };

  const confirmDeactivateClick = async (): Promise<void> => {
    if (busy) return;
    setConfirmDeactivate(false);
    setPending('deactivate');
    try {
      await onDeactivate();
    } finally {
      setPending(null);
    }
  };

  const handleDisconnectClick = async (): Promise<void> => {
    if (busy) return;
    setPending('disconnect');
    try {
      await onDisconnect(source.id);
    } finally {
      setPending(null);
    }
  };

  return (
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-background">
      <div className="relative">
        <Header source={source} />
        {connected && hasFields && (
          <Badge variant="success" className="absolute top-6 right-6">
            {__('Connected', 'texty')}
          </Badge>
        )}
      </div>

      {content.map((item: SettingsElement, idx: number) =>
        item.type === 'section' ? (
          <Section key={item.id} section={item} isFirst={idx === 0} />
        ) : null
      )}

      <AlertDialog open={confirmDeactivate} onOpenChange={setConfirmDeactivate}>
        <AlertDialogContent>
          <AlertDialogCancel
            variant="ghost"
            size="icon"
            className="absolute top-3 right-3 size-7"
            aria-label={__('Close', 'texty')}
          >
            <X className="size-4" />
          </AlertDialogCancel>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {__('Are you sure to deactivate?', 'texty')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {__(
                'Deactivating this gateway will stop all SMS traffic.',
                'texty'
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{__('Cancel', 'texty')}</AlertDialogCancel>
            <AlertDialogAction onClick={confirmDeactivateClick}>
              {__('Yes, deactivate', 'texty')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {save && !source.hide_save && (
        <div className="flex items-center justify-between gap-2 border-t border-border bg-background px-6 py-3">
          <div>
            {isActive && (
              <button
                type="button"
                onClick={handleDeactivateClick}
                disabled={busy}
                className="inline-flex cursor-pointer items-center gap-1.5 bg-transparent p-0 text-sm font-medium text-destructive hover:underline disabled:cursor-not-allowed disabled:opacity-60"
              >
                {pending === 'deactivate' && (
                  <Loader2 className="size-3.5 animate-spin" />
                )}
                {__('Deactivate', 'texty')}
              </button>
            )}
          </div>

          <div className="flex items-center gap-2">
            {hasFields ? (
              <>
                {!connected && (
                  <Button
                    onClick={handleConnect}
                    disabled={hasErrors || !dirty || busy}
                  >
                    {pending === 'connect' && (
                      <Loader2 className="size-4 animate-spin" />
                    )}
                    {__('Connect', 'texty')}
                  </Button>
                )}

                {connected && !isActive && (
                  <>
                    <Button
                      variant="outline"
                      onClick={handleDisconnectClick}
                      disabled={busy}
                    >
                      {pending === 'disconnect' && (
                        <Loader2 className="size-4 animate-spin" />
                      )}
                      {__('Disconnect', 'texty')}
                    </Button>
                    <Button
                      onClick={handleActivateClick}
                      disabled={hasErrors || busy}
                    >
                      {pending === 'activate' && (
                        <Loader2 className="size-4 animate-spin" />
                      )}
                      {dirty
                        ? __('Save & Activate', 'texty')
                        : __('Activate', 'texty')}
                    </Button>
                  </>
                )}

                {connected && isActive && (
                  <Button disabled>{__('Activated', 'texty')}</Button>
                )}
              </>
            ) : (
              <>
                {!isActive && (
                  <Button onClick={handleActivateClick} disabled={busy}>
                    {pending === 'activate' && (
                      <Loader2 className="size-4 animate-spin" />
                    )}
                    {__('Activate', 'texty')}
                  </Button>
                )}
                {isActive && (
                  <Button disabled>{__('Activated', 'texty')}</Button>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default DetailPane;
