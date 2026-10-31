import {
  Button,
  cn,
  Input,
  Skeleton,
  toast,
  type FieldComponentProps,
  type SettingsElement,
} from '@wedevs/plugin-ui';
import { humanTimeDiff } from '@wordpress/date';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
  Copy,
  ExternalLink,
  Plus,
  RefreshCw,
  Server,
  Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';

import { useEgressIps, type EgressIpEntry } from '../hooks/useEgressIps';

type IpWhitelistElement = SettingsElement & {
  whitelist_url?: string;
};

const RESULT_TONE: Record<string, { label: string; className: string }> = {
  allowed: {
    label: __('Allowed', 'texty'),
    className: 'bg-emerald-100 text-emerald-700',
  },
  blocked: {
    label: __('Blocked', 'texty'),
    className: 'bg-rose-100 text-rose-700',
  },
  detected: {
    label: __('Not used yet', 'texty'),
    className: 'bg-gray-100 text-gray-600',
  },
};

const copyText = async (text: string, message: string): Promise<void> => {
  try {
    await navigator.clipboard.writeText(text);
    toast.success(message);
  } catch (err) {
    console.error(err);
    toast.error(__("Couldn't copy. Select the IP and copy it manually.", 'texty'));
  }
};

const ResultBadge = ({ entry }: { entry: EgressIpEntry }) => {
  const tone = RESULT_TONE[entry.result] ?? RESULT_TONE.detected;

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        tone.className
      )}
    >
      {tone.label}
    </span>
  );
};

/**
 * "IP whitelist" panel for gateways that only accept whitelisted server IPs.
 * Rendered for the `ip_whitelist` settings variant; display-only (nothing is
 * saved with the gateway credentials).
 */
const IpWhitelistField = ({ element }: FieldComponentProps) => {
  const { whitelist_url: whitelistUrl } = element as IpWhitelistElement;
  const { current, observed, loading, busy, refresh, add, remove } =
    useEgressIps();
  const [manualIp, setManualIp] = useState<string>('');

  const handleAdd = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    const ip: string = manualIp.trim();
    if (!ip) {
      return;
    }
    if (await add(ip)) {
      setManualIp('');
    }
  };

  const allIps: string = observed
    .map((entry: EgressIpEntry) => entry.ip)
    .join('\n');

  return (
    <div
      className="flex w-full flex-col gap-4 p-4"
      id={element.id}
      data-testid={`settings-field-${element.id}`}
    >
      <p className="m-0 text-sm text-muted-foreground">
        {__(
          'The gateway sees the IP your server sends requests from. Whitelist every IP below with your provider.',
          'texty'
        )}{' '}
        {whitelistUrl && (
          <a
            href={whitelistUrl}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
          >
            {__('Open whitelist page', 'texty')}
            <ExternalLink className="size-3.5" />
          </a>
        )}
      </p>

      {/* This server */}
      <div className="flex flex-col gap-3 rounded-md border border-border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-center gap-3">
          <Server className="size-4 shrink-0 text-muted-foreground" />
          <div className="min-w-0">
            <p className="m-0 text-xs text-muted-foreground">
              {current.host
                ? sprintf(
                    /* translators: %s: server hostname */
                    __('This server (%s)', 'texty'),
                    current.host
                  )
                : __('This server', 'texty')}
            </p>
            {loading ? (
              <Skeleton className="mt-1 h-5 w-32" />
            ) : (
              <p className="m-0 mt-0.5 font-mono text-base font-semibold text-foreground">
                {current.ip || __('Not detected', 'texty')}
              </p>
            )}
          </div>
        </div>
        <div className="flex gap-2">
          {current.ip && (
            <Button
              size="sm"
              variant="outline"
              className="gap-1.5"
              onClick={() =>
                copyText(current.ip, __('Server IP copied.', 'texty'))
              }
            >
              <Copy className="size-3.5" />
              {__('Copy', 'texty')}
            </Button>
          )}
          <Button
            size="sm"
            variant="ghost"
            className="gap-1.5"
            disabled={busy || loading}
            onClick={refresh}
          >
            <RefreshCw className={cn('size-3.5', busy && 'animate-spin')} />
            {__('Re-check', 'texty')}
          </Button>
        </div>
      </div>

      {/* Every IP seen across servers */}
      <div className="flex flex-col gap-2">
        <div className="flex items-center justify-between gap-2">
          <p className="m-0 text-sm font-medium text-foreground">
            {__('All outgoing IPs seen', 'texty')}
          </p>
          {observed.length > 1 && (
            <Button
              size="sm"
              variant="ghost"
              className="gap-1.5"
              onClick={() =>
                copyText(allIps, __('All IPs copied.', 'texty'))
              }
            >
              <Copy className="size-3.5" />
              {__('Copy all', 'texty')}
            </Button>
          )}
        </div>

        {loading ? (
          <Skeleton className="h-10 w-full" />
        ) : observed.length === 0 ? (
          <p className="m-0 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground">
            {__(
              'No IPs yet. They appear here as each server sends, or add one below.',
              'texty'
            )}
          </p>
        ) : (
          <ul className="m-0 flex list-none flex-col divide-y divide-border rounded-md border border-border p-0">
            {observed.map((entry: EgressIpEntry) => (
              <li
                key={entry.ip}
                className="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                  <span className="font-mono text-sm font-semibold text-foreground">
                    {entry.ip}
                  </span>
                  <ResultBadge entry={entry} />
                  <span className="text-xs text-muted-foreground">
                    {[
                      entry.manual
                        ? __('Added manually', 'texty')
                        : entry.host,
                      humanTimeDiff(entry.last_seen),
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                </div>
                <div className="flex gap-1">
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 gap-1.5 px-2 text-xs"
                    onClick={() =>
                      copyText(entry.ip, __('IP copied.', 'texty'))
                    }
                  >
                    <Copy className="size-3.5" />
                    {__('Copy', 'texty')}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 px-2 text-xs text-muted-foreground"
                    disabled={busy}
                    onClick={() => remove(entry.ip)}
                    aria-label={sprintf(
                      /* translators: %s: IP address */
                      __('Remove %s', 'texty'),
                      entry.ip
                    )}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}

        <form onSubmit={handleAdd} className="flex gap-2">
          <Input
            value={manualIp}
            onChange={(e: FormEvent<HTMLInputElement>) =>
              setManualIp(e.currentTarget.value)
            }
            placeholder={__("Add an IP from your host, e.g. 203.0.113.7", 'texty')}
            className="font-mono"
            disabled={busy}
          />
          <Button
            type="submit"
            size="sm"
            variant="outline"
            className="h-9 shrink-0 gap-1.5"
            disabled={busy || !manualIp.trim()}
          >
            <Plus className="size-3.5" />
            {__('Add', 'texty')}
          </Button>
        </form>
      </div>

      <div className="rounded-md bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
        <p className="m-0">
          <strong className="text-foreground">
            {__('Several servers or a load balancer?', 'texty')}
          </strong>{' '}
          {__(
            "Each server can send from a different IP, so whitelist all of them. Each server adds its IP here when it sends: one that isn't whitelisted shows as Blocked after its first failed message. If your host uses a fixed outgoing (NAT) IP, add that instead.",
            'texty'
          )}
        </p>
        <p className="m-0 mt-1.5">
          {__(
            'Requests to this gateway always use IPv4, so whitelist the IPv4 addresses shown here.',
            'texty'
          )}
        </p>
      </div>
    </div>
  );
};

export default IpWhitelistField;
