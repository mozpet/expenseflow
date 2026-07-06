import React from 'react';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell,
  PieChart,
  Pie,
} from 'recharts';

interface WeeklyData {
  name: string;
  total: number;
}

interface CategoryData {
  name: string;
  value: number;
  color: string;
}

interface AnalyticsChartsProps {
  weeklyTrend: WeeklyData[];
  categoryDistribution: CategoryData[];
}

export const AnalyticsCharts: React.FC<AnalyticsChartsProps> = ({
  weeklyTrend,
  categoryDistribution,
}) => {
  // Safe formatter for active tooltips
  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(value);
  };

  const CustomTooltip = ({ active, payload }: any) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-slate-900 border border-slate-700 p-2 rounded-lg text-white font-mono text-xs shadow-xl">
          <p className="font-semibold">{payload[0].name}</p>
          <p className="text-[#38bdf8]">{formatCurrency(payload[0].value)}</p>
        </div>
      );
    }
    return null;
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      {/* Chart 1: Trend Mingguan */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
        <div className="flex justify-between items-center mb-4">
          <div>
            <h4 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Trend Pengeluaran Mingguan
            </h4>
            <p className="text-xs text-slate-400 dark:text-slate-500">
              Perkembangan arus keluar per minggu di Mei 2026 (Rp jt)
            </p>
          </div>
          <span className="text-[10px] font-mono bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400 px-2 py-1 rounded">
            Live Update
          </span>
        </div>

        <div className="w-full h-64">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              data={weeklyTrend}
              margin={{ top: 10, right: 10, left: -20, bottom: 0 }}
            >
              <defs>
                <linearGradient id="barGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#4f46e5" stopOpacity={0.95} />
                  <stop offset="100%" stopColor="#818cf8" stopOpacity={0.75} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
              <XAxis
                dataKey="name"
                axisLine={false}
                tickLine={false}
                tick={{ fill: '#64748b', fontSize: 11 }}
              />
              <YAxis
                axisLine={false}
                tickLine={false}
                tickFormatter={(val) => `Rp ${val / 1000000}jt`}
                tick={{ fill: '#64748b', fontSize: 11 }}
              />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: '#f8fafc' }} />
              <Bar
                dataKey="total"
                radius={[6, 6, 0, 0]}
                maxBarSize={45}
              >
                {weeklyTrend.map((entry, index) => (
                  <Cell
                    key={`cell-${index}`}
                    fill="url(#barGradient)"
                    className="transition-all duration-300 hover:opacity-90"
                  />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Chart 2: Distribusi Kategori (Donut) */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
        <div className="flex justify-between items-center mb-2">
          <div>
            <h4 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Distribusi Kategori Pengeluaran
            </h4>
            <p className="text-xs text-slate-400 dark:text-slate-500">
              Proporsi pengeluaran berdasarkan peruntukannya
            </p>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-between gap-4 h-64">
          <div className="w-full sm:w-1/2 h-full flex items-center justify-center">
            <ResponsiveContainer width="100%" height="80%">
              <PieChart>
                <Pie
                  data={categoryDistribution}
                  cx="50%"
                  cy="50%"
                  innerRadius={50}
                  outerRadius={75}
                  paddingAngle={3}
                  dataKey="value"
                >
                  {categoryDistribution.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip content={<CustomTooltip />} />
              </PieChart>
            </ResponsiveContainer>
          </div>

          <div className="w-full sm:w-1/2 flex flex-col gap-2.5 max-h-[85%] overflow-y-auto pr-1">
            {categoryDistribution.map((entry, index) => {
              const totalSum = categoryDistribution.reduce((acc, curr) => acc + curr.value, 0);
              const percentage = totalSum > 0 ? ((entry.value / totalSum) * 100).toFixed(1) : '0';
              return (
                <div key={index} className="flex flex-col gap-1 w-full text-xs">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span
                        className="w-2.5 h-2.5 rounded-full shrink-0"
                        style={{ backgroundColor: entry.color }}
                      />
                      <span className="font-medium text-slate-700 dark:text-slate-300 truncate max-w-[110px]">
                        {entry.name}
                      </span>
                    </div>
                    <span className="font-mono text-slate-500 dark:text-slate-400 font-semibold shrink-0">
                      {percentage}%
                    </span>
                  </div>
                  <div className="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
                    <div
                      className="h-full rounded-full transition-all duration-500"
                      style={{
                        backgroundColor: entry.color,
                        width: `${percentage}%`,
                      }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
};
