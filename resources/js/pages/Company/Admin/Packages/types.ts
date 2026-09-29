export interface Package {
  id: number;
  name: string;
  description: string | null;
  price: number;
  billing_period: 'monthly' | 'yearly';
  max_users: number;
  additional_user_price: number | null;
  max_doctors: number;
  max_staff: number | null;
  max_branches: number;
  storage_limit_gb: number;
  is_active: boolean;
  is_trial_available: boolean;
  trial_duration_days: number;
  subscriptions_count: number;
  /** The keys of the `CompanyFeature` rows attached to this plan — see `EntitlementResolver::modulesFor()`. */
  module_keys: string[];
}

export interface CompanyFeatureOption {
  id: number;
  key: string;
  name: string;
  description: string | null;
}

export interface PackagesIndexProps {
  packages: Package[];
  features: CompanyFeatureOption[];
}
