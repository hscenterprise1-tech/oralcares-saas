import axios from 'axios';

export const WidgetDataService = {
  getHeroData: async () => {
    const response = await axios.get('/api/widgets/hero');
    return response.data;
  },
  
  getKPIs: async () => {
    const response = await axios.get('/api/widgets/kpis');
    return response.data;
  },
  
  getRevenueAnalytics: async () => {
    const response = await axios.get('/api/widgets/revenue');
    return response.data;
  },
  
  getSalesFunnel: async () => {
    const response = await axios.get('/api/widgets/funnel');
    return response.data;
  },
  
  getOperationsOverview: async () => {
    const response = await axios.get('/api/widgets/operations');
    return response.data;
  },
  
  getActivityTimeline: async () => {
    const response = await axios.get('/api/widgets/timeline');
    return response.data;
  },
  
  getSystemHealth: async () => {
    const response = await axios.get('/api/widgets/health');
    return response.data;
  }
};
