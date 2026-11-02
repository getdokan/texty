import { toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export type EgressIpResult = 'detected' | 'allowed' | 'blocked';

export type EgressIpEntry = {
  ip: string;
  host: string;
  result: EgressIpResult | string;
  gateway: string;
  manual: boolean;
  last_seen: string;
};

type EgressIpsResponse = {
  current: { ip: string; host: string };
  observed: EgressIpEntry[];
};

type UseEgressIps = {
  current: { ip: string; host: string };
  observed: EgressIpEntry[];
  loading: boolean;
  busy: boolean;
  refresh: () => Promise<void>;
  add: (ip: string) => Promise<boolean>;
  remove: (ip?: string) => Promise<void>;
};

const PATH = '/texty/v1/egress-ips';

const errorMessage = (err: unknown, fallback: string): string =>
  (err as { message?: string })?.message ?? fallback;

/**
 * This server's outgoing IP plus every IP observed across servers
 * (GET/POST/DELETE /texty/v1/egress-ips).
 */
export const useEgressIps = (): UseEgressIps => {
  const [current, setCurrent] = useState<{ ip: string; host: string }>({
    ip: '',
    host: '',
  });
  const [observed, setObserved] = useState<EgressIpEntry[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [busy, setBusy] = useState<boolean>(false);

  const apply = (response: EgressIpsResponse): void => {
    setCurrent(response.current ?? { ip: '', host: '' });
    setObserved(response.observed ?? []);
  };

  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      try {
        const response = await apiFetch<EgressIpsResponse>({ path: PATH });
        if (cancelled) {
          return;
        }
        apply(response);
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error(err);
        toast.error(
          errorMessage(err, __("Couldn't load the server IPs.", 'texty'))
        );
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

  const refresh = useCallback(async (): Promise<void> => {
    setBusy(true);
    try {
      apply(
        await apiFetch<EgressIpsResponse>({ path: `${PATH}?refresh=1` })
      );
      toast.success(__('Server IP re-checked.', 'texty'));
    } catch (err) {
      console.error(err);
      toast.error(
        errorMessage(err, __("Couldn't detect the server IP.", 'texty'))
      );
    } finally {
      setBusy(false);
    }
  }, []);

  const add = useCallback(async (ip: string): Promise<boolean> => {
    setBusy(true);
    try {
      apply(
        await apiFetch<EgressIpsResponse>({
          path: PATH,
          method: 'POST',
          data: { ip },
        })
      );
      toast.success(__('IP added.', 'texty'));
      return true;
    } catch (err) {
      console.error(err);
      toast.error(errorMessage(err, __("Couldn't add the IP.", 'texty')));
      return false;
    } finally {
      setBusy(false);
    }
  }, []);

  const remove = useCallback(async (ip?: string): Promise<void> => {
    setBusy(true);
    try {
      apply(
        await apiFetch<EgressIpsResponse>({
          path: ip ? `${PATH}?ip=${encodeURIComponent(ip)}` : PATH,
          method: 'DELETE',
        })
      );
      toast.success(
        ip ? __('IP removed.', 'texty') : __('IP list cleared.', 'texty')
      );
    } catch (err) {
      console.error(err);
      toast.error(errorMessage(err, __("Couldn't update the list.", 'texty')));
    } finally {
      setBusy(false);
    }
  }, []);

  return { current, observed, loading, busy, refresh, add, remove };
};
