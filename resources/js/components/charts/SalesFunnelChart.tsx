import React from 'react';
import { EnterpriseChart } from '../enterprise-system/charts/EnterpriseChart';

const data = [
  { name: 'Leads', value: 450, color: '#0A2540' },
  { name: 'Demo', value: 280, color: '#00B4C6' },
  { name: 'Quotes', value: 150, color: '#F59E0B' },
  { name: 'Won', value: 85, color: '#22C55E' },
];

const formatTooltip = (value: number) => {
  return value.toString();
};

export function SalesFunnelChart() {
  return (
    <EnterpriseChart 
      type="bar"
      layout="vertical"
      data={data}
      xAxisKey="name"
      series={[{ key: 'value', name: 'Leads' }]}
      barSize={24}
      formatTooltip={formatTooltip}
    />
  );
}
