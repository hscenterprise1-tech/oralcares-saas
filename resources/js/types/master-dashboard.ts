export interface MasterDashboardProps {
  totalOrganizations: number;
  activeOrganizations: number;
  trialOrganizations: number;
  expiredOrganizations: number;
  mrr: number;
  pendingRenewals: number;
  
  totalLeads: number;
  newLeads: number;
  followUpsToday: number;
  quotationsPending: number;
  wonThisMonth: number;
  lostThisMonth: number;
  
  activeSubscriptions: number;
  expiringIn30Days: number;
  expiredSubscriptions: number;
  trialEndingSoon: number;
  
  openTickets: number;
  criticalTickets: number;
  pendingReplies: number;
  averageResolutionTime: string;
  
  todaysRevenue: number;
  thisMonthRevenue: number;
  outstandingPayments: number;
  totalCollections: number;
  
  totalEmployees: number;
  onlineEmployees: number;
  todaysTasks: number;
  pendingTasks: number;
  
  recentActivities: any[];
}
