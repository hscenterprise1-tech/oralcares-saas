export interface AssignableUser {
  id: number;
  name: string;
  email: string;
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
}

/**
 * Aggregate load carried by a team's members. Null when the viewer lacks
 * `crm.overview.team` — team workload names individuals' numbers, so it is a
 * management view rather than part of the roster.
 */
export interface TeamWorkload {
  member_count: number;
  open_leads: number;
  open_pipeline_value: number;
  won: number;
  lost: number;
  overdue_followups: number;
  average_open_leads_per_member: number | null;
}

export interface Team {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  assignment_strategy: 'round_robin' | 'territory';
  territory_map: Record<string, number>;
  manager: TeamMember | null;
  member_count: number;
  members: TeamMember[];
  workload: TeamWorkload | null;
}

export interface TeamPermissions {
  manage: boolean;
  view_performance: boolean;
}

export interface MemberPerformance {
  user_id: number;
  name: string;
  leads_assigned: number;
  demos_completed: number;
  trials_active: number;
  quotations_sent: number;
  wins: number;
  losses: number;
  conversion_rate: number | null;
  average_cycle_time_days: number | null;
  overdue_followups: number;
  active_weighted_pipeline: number;
}

export interface TeamsIndexProps {
  teams: Team[];
  assignableUsers: AssignableUser[];
  can: TeamPermissions;
}

export interface TeamShowProps {
  team: Team;
  assignableUsers: AssignableUser[];
  memberPerformance: MemberPerformance[] | null;
  can: TeamPermissions;
}
