/** One clinical context a practitioner works in here: module → practice area. */
export interface PractitionerContext {
  area: string | null;
  area_label: string | null;
  specialty: string | null;
  module: string | null;
  module_label: string | null;
}

/**
 * A practitioner as the server offers them for today at this branch
 * (`ClinicalContextResolver::practitionerOptions()`): only those the clinic's
 * modules, specialties and the branch allow, with their shift and leave.
 */
export interface PractitionerOption {
  id: number;
  name: string;
  /** Qualifications, e.g. "BDS · MDS". Shown only; they grant no capability. */
  credentials: string | null;
  area: { key: string; label: string } | null;
  module: { key: string; label: string } | null;
  /** on_shift | not_set | off_shift | on_leave */
  shift: string;
  shift_label: string;
  room: string | null;
  /** The consultation fee from the server; charged once (at check-in or after the visit, as the clinic sets). */
  fee: number | null;
  contexts: PractitionerContext[];
}
