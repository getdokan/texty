import { Fragment, useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import StatCard from '../components/StatCard';
import VolumeChart from '../components/VolumeChart';
import QuickSend from '../components/QuickSend';
import HelpResources from '../components/HelpResources';

function Dashboard() {
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [metrics, setMetrics] = useState({
    gateway_status: false,
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

  return (
    <Fragment>
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
            value={metrics.gateway_status ? __('Twilio Connected', 'texty') : __('Disconnected', 'texty')}
            indicator={metrics.gateway_status ? 'connected' : 'error'}
            loading={isLoading}
            actionText={__('View Settings', 'texty')}
            actionLink="#/settings"
          />

          <StatCard
            icon="chart"
            title={__('Monthly Usage', 'texty')}
            value={formatUsage(metrics.monthly_usage)}
            subLabel={metrics.usage_change > 0 ? `+${metrics.usage_change.toFixed(1)}% ${__('from last month', 'texty')}` : __('No change from last month', 'texty')}
            loading={isLoading}
            progressValue={Math.min((metrics.monthly_usage / 10000) * 100, 100)}
          />

          <StatCard
            icon="trending"
            title={__('Delivery Rate', 'texty')}
            value={metrics.delivery_rate !== null ? `${metrics.delivery_rate.toFixed(1)}%` : __('Coming soon', 'texty')}
            subLabel={metrics.delivery_rate !== null ? __('Based on last 30 days', 'texty') : ''}
            loading={isLoading}
            actionText={__('View Details', 'texty')}
            actionLink="#/tools"
          />
        </div>

        {/* Main Content Grid */}
        <div className="texty-dashboard-main">
          {/* Volume Chart */}
          <div className="texty-dashboard-main__chart">
            <div className="texty-card">
              <div className="texty-card__header">
                <h2>{__('SMS Volume (Last 12 Months)', 'texty')}</h2>
              </div>
              <div className="texty-card__body">
                {isLoading ? (
                  <div className="texty-chart-loading">
                    <div className="texty-spinner"></div>
                  </div>
                ) : (
                  <VolumeChart data={metrics.volume_chart} />
                )}
              </div>
            </div>
          </div>

          {/* Quick Send */}
          <div className="texty-dashboard-main__quick-send">
            <div className="texty-card">
              <div className="texty-card__header">
                <h2>{__('Quick Send', 'texty')}</h2>
              </div>
              <div className="texty-card__body">
                <QuickSend />
              </div>
            </div>
          </div>
        </div>

        {/* Help Resources */}
        <div className="texty-help-resources-section">
          <div className="texty-help-resources-header">
            <h2>{__('Resources', 'texty')}</h2>
          </div>
          <HelpResources />
        </div>
      </div>
    </Fragment>
  );
}

export default Dashboard;
