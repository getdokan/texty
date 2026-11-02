export type DashboardPeriod =
  | 'this_month'
  | 'last_month'
  | 'last_7_days'
  | 'last_30_days'
  | 'this_year';

export type VolumePoint = {
  key: string;
  label: string;
  date: string;
  count: number;
};

export type DashboardMetrics = {
  period: DashboardPeriod;
  gateway_status: boolean;
  /** Active gateway's registry key, e.g. `sslwireless`. */
  gateway_name: string;
  /** Active gateway's display name, e.g. "SSL Wireless". Optional for older API responses. */
  gateway_label?: string;
  sms_sent: number;
  delivered: number;
  failed: number;
  delivery_rate: number;
  volume_chart: VolumePoint[];
};
