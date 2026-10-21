/** A patient as the clinic's patient search returns them (`clinic.patients.search`): enough to recognise and pick. */
export interface PatientHit {
  id: number;
  uhid: string | null;
  name: string;
  phone: string | null;
  gender: string | null;
  age: number | null;
}
