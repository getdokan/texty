import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';

function VolumeChart({ data }) {
  return (
    <div className="texty-chart-card">
      <div className="texty-chart-card__header">
        <h3 className="texty-chart-card__title">SMS Volume</h3>
      </div>
      <div className="texty-chart-card__body">
        <ResponsiveContainer width="100%" height={300}>
          <BarChart
            data={data || []}
            margin={{ top: 10, right: 10, left: 0, bottom: 0 }}
          >
            <CartesianGrid
              strokeDasharray="0"
              horizontal={true}
              vertical={false}
              stroke="#f3f4f6"
            />
            <XAxis
              dataKey="month"
              tick={{ fill: '#9ca3af', fontSize: 13 }}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              domain={[0, 10000]}
              ticks={[0, 2500, 5000, 7500, 10000]}
              tick={{ fill: '#9ca3af', fontSize: 13 }}
              axisLine={false}
              tickLine={false}
            />
            <Tooltip cursor={{ fill: '#f0fdf4' }} />
            <Bar
              dataKey="count"
              fill="#16a34a"
              radius={[4, 4, 0, 0]}
              barSize={40}
            />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}

export default VolumeChart;
