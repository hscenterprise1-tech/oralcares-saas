/**
 * The onboarding pages' contract, as `OnboardingController` and
 * `OnboardingEngine::view()` send it. The server is the contract: every step's
 * fields, rules and option lists come from its section class.
 */

export type OnboardingLinks = {
  index: string;
  account: string;
  /** Contains `__STEP__`, replaced with a step key. */
  draft: string;
  submit: string;
  skip: string;
  pause: string;
  resume: string;
  logout: string;
};

export type StepStatus = 'pending' | 'in_progress' | 'completed' | 'skipped' | 'blocked';

export type SessionStatus = 'pending' | 'in_progress' | 'paused' | 'completed' | 'blocked';

export type SessionKind = 'clinic' | 'staff';

export type OnboardingSession = {
  id: number;
  kind: SessionKind;
  status: SessionStatus;
  template: string;
  template_label: string;
  current_step: string | null;
  blocked_reason: string | null;
  started_at: string | null;
  completed_at: string | null;
  progress: { done: number; total: number };
};

export type StepSummary = {
  key: string;
  title: string;
  status: StepStatus;
  required: boolean;
  completed_at: string | null;
};

export type StepValues = Record<string, unknown>;

export type CurrentStep = {
  key: string;
  title: string;
  description: string;
  component: string;
  required: boolean;
  status: StepStatus;
  values: StepValues;
  has_draft: boolean;
  draft_saved_at: string | null;
  meta: Record<string, unknown>;
};

export type WizardProps =
  | {
      mode: 'waiting';
      session: { kind: SessionKind; status: SessionStatus };
      clinicName: string | null;
      links: OnboardingLinks;
    }
  | {
      mode: 'edit' | 'paused';
      session: OnboardingSession;
      steps: StepSummary[];
      step: CurrentStep;
      clinicName: string | null;
      links: OnboardingLinks;
    };

/** What every step form receives. */
export type StepFormProps = {
  values: StepValues;
  set: (key: string, value: unknown) => void;
  errors: Record<string, string>;
  meta: Record<string, unknown>;
  disabled: boolean;
};

export type Option = { value: string | number; label: string };

export function stepUrl(template: string, key: string): string {
  return template.replace('__STEP__', encodeURIComponent(key));
}
