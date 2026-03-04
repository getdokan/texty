import { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { SendHorizontal, CircleCheckBig } from 'lucide-react';

import StatCard from '../components/StatCard';
import VolumeChart from '../components/VolumeChart';
import QuickSend from '../components/QuickSend';
import HelpResources from '../components/HelpResources';

function Dashboard() {
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [metrics, setMetrics] = useState({
    gateway_status: false,
    gateway_name: '',
    monthly_usage: 0,
    usage_change: 0,
    delivery_rate: null,
    volume_chart: [],
  });

  useEffect(() => {
    fetchMetrics();
  }, []);

  const fetchMetrics = () => {
    setIsLoading(true);
    setError(null);

    apiFetch({
      path: '/texty/v1/metrics',
      method: 'GET',
    })
      .then((data) => {
        setMetrics(data);
        setIsLoading(false);
      })
      .catch((err) => {
        console.error('Failed to fetch metrics:', err);
        setError(__('Failed to load dashboard data. Please try again.', 'texty'));
        setIsLoading(false);
      });
  };

  const formatUsage = (usage) => {
    return usage.toLocaleString();
  };

  const getUsageSubLabel = () => {
    if (metrics.usage_change > 0) {
      return `+${metrics.usage_change.toFixed(0)}% ${__('from last month', 'texty')}`;
    } else if (metrics.usage_change < 0) {
      return `${metrics.usage_change.toFixed(0)}% ${__('from last month', 'texty')}`;
    }
  };

  return (
    <>
      <div className="texty-dashboard">
        <div className="texty-dashboard__header">
          <h1>{__('Dashboard', 'texty')}</h1>
        </div>

        {error && (
          <div className="texty-error-alert">
            {error}
          </div>
        )}

        {/* Stats Section */}
        <div className="texty-stat-cards">
          <StatCard
            icon="activity"
            title={__('Gateway Status', 'texty')}
            value={
              metrics.gateway_status
                ? <><CircleCheckBig size={20} style={{ color: '#2271b1', verticalAlign: 'text-bottom', marginRight: '6px', display: 'inline' }} /> {metrics.gateway_name.charAt(0).toUpperCase() + metrics.gateway_name.slice(1)} {__('Connected', 'texty')}</>
                : __('Disconnected', 'texty')
            }
            indicator={metrics.gateway_status ? 'connected' : 'error'}
            actionText={__('View Settings', 'texty')}
            actionLink="#/settings"
            loading={isLoading}
          />

          <StatCard
            icon="chart"
            title={__('Monthly Usage', 'texty')}
            value={formatUsage(metrics.monthly_usage)}
            progressValue={Math.min((metrics.monthly_usage / 10000) * 100, 100)}
            subLabel={getUsageSubLabel()}
            loading={isLoading}
          />

          <StatCard
            icon="trending"
            title={__('Delivery Rate', 'texty')}
            value={
              metrics.delivery_rate !== null
                ? `${metrics.delivery_rate.toFixed(1)}%`
                : __('N/A', 'texty')
            }
            subLabel={
              metrics.delivery_rate !== null
                ? __('Based on last 30 days', 'texty')
                : null
            }
            actionText={__('View Details', 'texty')}
            actionLink="#/tools"
            loading={isLoading}
          />
        </div>

        {/* Main Content Grid */}
        <div className="texty-dashboard-main">
          {/* Volume Chart */}
          <div className="texty-dashboard-main__chart">
            {isLoading ? (
              <div className="texty-chart-card">
                <div className="texty-chart-card__header">
                  <h3 className="texty-chart-card__title">{__('SMS Volume', 'texty')}</h3>
                </div>
                <div className="texty-chart-card__body">
                  <div className="texty-chart-loading">
                    <div className="texty-spinner"></div>
                  </div>
                </div>
              </div>
            ) : (
              <VolumeChart data={metrics.volume_chart} />
            )}
          </div>

          {/* Quick Send */}
          <div className="texty-dashboard-main__quick-send">
            <div className="texty-card">
              <div className="texty-card__header">
                <h2>
                  <SendHorizontal size={18} style={{ marginRight: '8px', verticalAlign: 'text-bottom' }} />
                  {__('Quick Send', 'texty')}
                </h2>
              </div>
              <div className="texty-card__body">
                <QuickSend />
              </div>
            </div>
          </div>
        </div>

        {/* Help Resources */}
        <HelpResources />
      </div>
    </>
  );
}

export default Dashboard;
