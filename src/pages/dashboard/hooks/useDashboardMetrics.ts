import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';

import type { DashboardMetrics, DashboardPeriod } from '../types';

const DEFAULT_METRICS: DashboardMetrics = {
  period: 'this_month',
  gateway_status: false,
  gateway_name: '',
  sms_sent: 0,
  delivered: 0,
  failed: 0,
  delivery_rate: 0,
  volume_chart: [],
};

type UseDashboardMetrics = {
  period: DashboardPeriod;
  setPeriod: (next: DashboardPeriod) => void;
  metrics: DashboardMetrics;
  loading: boolean;
  hasLoaded: boolean;
  error: string | null;
  refresh: () => void;
};

export const useDashboardMetrics = (): UseDashboardMetrics => {
  const [period, setPeriod] = useState<DashboardPeriod>('this_month');
  const [metrics, setMetrics] = useState<DashboardMetrics>(DEFAULT_METRICS);
  const [loading, setLoading] = useState<boolean>(true);
  const [hasLoaded, setHasLoaded] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshTick, setRefreshTick] = useState<number>(0);

  useEffect(() => {
    let cancelled = false;

    const fetchMetrics = async (): Promise<void> => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiFetch<DashboardMetrics>({
          path: `/texty/v1/metrics?period=${period}`,
          method: 'GET',
        });
        if (cancelled) {
          return;
        }
        setMetrics(data);
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error('Failed to fetch metrics', err);
        setError(__('Failed to load dashboard data.', 'texty'));
      } finally {
        if (!cancelled) {
          setLoading(false);
          setHasLoaded(true);
        }
      }
    };

    fetchMetrics();

    return () => {
      cancelled = true;
    };
  }, [period, refreshTick]);

  const refresh = useCallback((): void => {
    setRefreshTick((tick: number) => tick + 1);
  }, []);

  return { period, setPeriod, metrics, loading, hasLoaded, error, refresh };
};
