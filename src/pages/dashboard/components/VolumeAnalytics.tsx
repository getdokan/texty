import {
  ChartContainer,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  Skeleton,
} from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Calendar, ChartNoAxesColumn } from 'lucide-react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from 'recharts';

import type { DashboardPeriod, VolumePoint } from '../types';

type ChartConfig = Record<string, { label?: string; color?: string }>;

type Props = {
  data: VolumePoint[];
  period: DashboardPeriod;
  onPeriodChange: (next: DashboardPeriod) => void;
  loading: boolean;
};

const chartConfig: ChartConfig = {
  count: {
    label: __('Number of SMS', 'texty'),
    color: '#111827',
  },
};

const periodOptions: { value: DashboardPeriod; label: string }[] = [
  { value: 'this_month', label: __('This Month', 'texty') },
  { value: 'last_month', label: __('Last Month', 'texty') },
  { value: 'last_7_days', label: __('Last 7 Days', 'texty') },
  { value: 'last_30_days', label: __('Last 30 Days', 'texty') },
  { value: 'this_year', label: __('This Year', 'texty') },
];

type TooltipPayload = {
  payload: VolumePoint;
  value: number;
}[];

type CustomTooltipProps = {
  active?: boolean;
  payload?: TooltipPayload;
};

const CustomTooltip = ({ active, payload }: CustomTooltipProps) => {
  if (!active || !payload?.length) {
    return null;
  }

  const point = payload[0]?.payload;
  if (!point) return null;

  return (
    <div className="min-w-44 rounded-md border border-gray-200 bg-white shadow-md">
      <div className="border-b border-gray-100 px-3 py-2 text-sm font-medium text-gray-900">
        {point.date}
      </div>
      <div className="flex items-center justify-between gap-4 px-3 py-2 text-sm text-gray-700">
        <span>{__('Number of SMS', 'texty')}</span>
        <span className="font-semibold tabular-nums text-gray-900">
          {point.count.toLocaleString()}
        </span>
      </div>
    </div>
  );
};

const buildAxisTicks = (data: VolumePoint[]): string[] => {
  if (data.length <= 8) {
    return data.map((p: VolumePoint) => p.key);
  }

  const step: number = Math.max(1, Math.ceil(data.length / 8));
  const ticks: string[] = [];
  for (let i = 0; i < data.length; i += step) {
    ticks.push(data[i].key);
  }
  // Always include the last point so the axis ends cleanly.
  const lastKey = data[data.length - 1]?.key;
  if (lastKey && ticks[ticks.length - 1] !== lastKey) {
    ticks.push(lastKey);
  }
  return ticks;
};

const VolumeAnalytics = ({ data, period, onPeriodChange, loading }: Props) => {
  const ticks: string[] = buildAxisTicks(data);
  const labelByKey: Map<string, string> = new Map(
    data.map((p: VolumePoint) => [p.key, p.label])
  );
  const activePeriodLabel: string =
    periodOptions.find((opt) => opt.value === period)?.label ?? '';

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex size-10 items-center justify-center rounded-full border border-gray-200 text-gray-600">
            <ChartNoAxesColumn className="size-4" />
          </div>
          <h3 className="m-0 text-base font-semibold text-gray-900">
            {__('SMS Volume Analytics', 'texty')}
          </h3>
        </div>

        <Select
          value={period}
          onValueChange={(value: string | null) => {
            if (value) onPeriodChange(value as DashboardPeriod);
          }}
        >
          <SelectTrigger className="w-40 gap-2">
            <Calendar className="size-4 text-gray-500" />
            <span>{activePeriodLabel}</span>
          </SelectTrigger>
          <SelectContent>
            {periodOptions.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="mt-6">
        {loading ? (
          <Skeleton className="h-70 w-full" />
        ) : (
          <ChartContainer config={chartConfig} className="h-70 w-full">
            <AreaChart
              data={data}
              margin={{ top: 10, right: 16, left: 0, bottom: 0 }}
            >
              <defs>
                <linearGradient id="volume-fill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#6b7280" stopOpacity={0.35} />
                  <stop offset="100%" stopColor="#6b7280" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid
                vertical={false}
                strokeDasharray="4 4"
                stroke="#e5e7eb"
              />
              <XAxis
                dataKey="key"
                tickLine={false}
                axisLine={false}
                tickMargin={12}
                ticks={ticks}
                tickFormatter={(key: string) => labelByKey.get(key) ?? key}
                tick={{ fill: '#6b7280', fontSize: 12 }}
              />
              <YAxis
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                tick={{ fill: '#6b7280', fontSize: 12 }}
                allowDecimals={false}
              />
              <RechartsTooltip
                cursor={{
                  stroke: '#9ca3af',
                  strokeDasharray: '4 4',
                }}
                content={<CustomTooltip />}
              />
              <Area
                dataKey="count"
                type="monotone"
                stroke="var(--color-count)"
                strokeWidth={2}
                fill="url(#volume-fill)"
                dot={false}
                activeDot={{
                  r: 5,
                  strokeWidth: 2,
                  stroke: 'var(--color-count)',
                  fill: '#fff',
                }}
              />
            </AreaChart>
          </ChartContainer>
        )}
      </div>
    </div>
  );
};

export default VolumeAnalytics;
