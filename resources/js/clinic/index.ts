/*
 * The Clinic Portal's shared UI pieces still in use after the 2026-10-11
 * frontend reset: only what the protected Login and the Appointment Calendar
 * (with its New Case / Repeat Case intake) import from '@/clinic'. The new
 * Clinic frontend is built separately; no OVS/OEDL, no Company `crmTheme`.
 */

// Feedback
export { Notice } from './ui/Notice';
export { ErrorState, LoadingState } from './ui/states';
export { Spinner } from './ui/Spinner';

// Controls
export { Button, ButtonLink, type ButtonProps } from './ui/Button';
export { Checkbox, Field, Input, Select, Textarea } from './ui/controls';
export { ChoiceChips, chipClass } from './ui/ChoiceChips';
export { DuplicateOverride, duplicateOverrideProblem } from './patients/DuplicateOverride';
export { PatientPicker } from './patients/PatientPicker';
export { PractitionerPicker, contextLabel } from './practitioners/PractitionerPicker';
export { PaymentForm, paymentPayloadOf, paymentProblem } from './billing/PaymentForm';
export { DatePicker, toIsoDate } from './ui/DatePicker';

// Overlays
export { Modal } from './ui/Modal';

// People
export { PersonAvatar } from './ui/PersonAvatar';

// Status
export { StatusBadge, ContextBadge } from './ui/badges';

// Vocabulary
export { contextForSpecialty, firstNameOf, humanize, withDoctorTitle } from './ui/visuals';

export type * from './types';
