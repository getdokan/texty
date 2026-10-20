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
  gateway_name: string;
  sms_sent: number;
  delivered: number;
  failed: number;
  delivery_rate: number;
  volume_chart: VolumePoint[];
};
