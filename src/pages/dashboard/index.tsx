import { __ } from '@wordpress/i18n';

import GatewayStatus from '@/components/GatewayStatus';

import DashboardSkeleton from './components/DashboardSkeleton';
import QuickSendCard from './components/QuickSendCard';
import StatCards from './components/StatCards';
import VolumeAnalytics from './components/VolumeAnalytics';
import WelcomeBanner from './components/WelcomeBanner';
import { useDashboardMetrics } from './hooks/useDashboardMetrics';

const Dashboard = () => {
  const { period, setPeriod, metrics, loading, hasLoaded, error, refresh } =
    useDashboardMetrics();

  if (!hasLoaded) {
    return <DashboardSkeleton />;
  }

  return (
    <div className="space-y-6">
      {metrics.gateway_status && metrics.gateway_name ? (
        <div className="rounded-xl border border-border bg-background px-6 py-5 shadow-xs">
          <div className="flex items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="m-0 text-sm text-muted-foreground">
                {__('Gateway Status', 'texty')}
              </p>
              <p className="m-0 mt-1 text-xl font-bold text-foreground capitalize">
                {metrics.gateway_name}
              </p>
            </div>
            <GatewayStatus status="active" />
          </div>
        </div>
      ) : (
        <WelcomeBanner />
      )}

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

      {metrics.gateway_status && <QuickSendCard onSent={refresh} />}
    </div>
  );
};

export default Dashboard;
