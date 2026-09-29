import React from 'react';
import { EnterpriseChart } from '../enterprise-system/charts/EnterpriseChart';

const data = [
  { name: 'Jan', revenue: 4200000 },
  { name: 'Feb', revenue: 4800000 },
  { name: 'Mar', revenue: 5100000 },
  { name: 'Apr', revenue: 5900000 },
  { name: 'May', revenue: 6500000 },
  { name: 'Jun', revenue: 7200000 },
  { name: 'Jul', revenue: 8400000 },
];

const formatYAxis = (tickItem: number) => {
  return `₹${(tickItem / 100000).toFixed(0)}L`;
};

const formatTooltip = (value: number) => {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0
  }).format(value);
};

export function RevenueTrendChart() {
  return (
    <EnterpriseChart 
      type="area"
      data={data}
      xAxisKey="name"
      series={[{ key: 'revenue', name: 'Revenue', color: '#00B4C6' }]}
      formatYAxis={formatYAxis}
      formatTooltip={formatTooltip}
    />
  );
}
