import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type StatusResponse = {
  success: boolean;
};

type GatewayStatus = {
  loading: boolean;
  active: boolean;
  error: string | null;
};

/**
 * Whether an SMS gateway is active, from `GET /texty/v1/status`.
 */
export const useGatewayStatus = (): GatewayStatus => {
  const [loading, setLoading] = useState<boolean>(true);
  const [active, setActive] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      try {
        const resp = await apiFetch<StatusResponse>({
          path: '/texty/v1/status',
        });
        if (cancelled) {
          return;
        }
        setActive(Boolean(resp.success));
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error(err);
        setError(__('Failed to load the gateway status.', 'texty'));
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

  return { loading, active, error };
};
