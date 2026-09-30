import { ApiClient } from './ApiClient';

/**
 * Patient Service Stub
 * All components must use this service instead of calling ApiClient or Axios directly.
 */
export class PatientService {
    static async getPatients() {
        return ApiClient.get('/api/patients');
    }

    static async registerPatient(data: any) {
        return ApiClient.post('/api/patients', data);
    }
}
