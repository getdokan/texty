import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from 'react';

import QuickSendCard from './QuickSendCard';
import StatCards from './StatCards';
import type { DashboardMetrics, DashboardPeriod } from './types';
import VolumeAnalytics from './VolumeAnalytics';
import WelcomeBanner from './WelcomeBanner';

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

const Dashboard = () => {
  const [period, setPeriod] = useState<DashboardPeriod>('this_month');
  const [metrics, setMetrics] = useState<DashboardMetrics>(DEFAULT_METRICS);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshTick, setRefreshTick] = useState<number>(0);

  useEffect(() => {
    let cancelled = false;

    const fetchMetrics = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiFetch<DashboardMetrics>({
          path: `/texty/v1/metrics?period=${period}`,
          method: 'GET',
        });
        if (cancelled) return;
        setMetrics(data);
      } catch (err) {
        if (cancelled) return;
        console.error('Failed to fetch metrics', err);
        setError(__('Failed to load dashboard data.', 'texty'));
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    fetchMetrics();

    return () => {
      cancelled = true;
    };
  }, [period, refreshTick]);

  const handleSmsSent = (): void => {
    setRefreshTick((tick: number) => tick + 1);
  };

  return (
    <div className="space-y-6 py-8">
      <WelcomeBanner
        gatewayConnected={metrics.gateway_status}
        gatewayName={metrics.gateway_name}
      />

      {error && (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
          {error}
        </div>
      )}

      <StatCards
        loading={loading}
        smsSent={metrics.sms_sent}
        delivered={metrics.delivered}
        failed={metrics.failed}
        deliveryRate={metrics.delivery_rate}
      />

      <VolumeAnalytics
        data={metrics.volume_chart}
        period={period}
        onPeriodChange={setPeriod}
        loading={loading}
      />

      <QuickSendCard onSent={handleSmsSent} />
    </div>
  );
};

export default Dashboard;
