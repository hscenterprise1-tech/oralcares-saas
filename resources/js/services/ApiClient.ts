/**
 * API Client Stub
 * Will handle CSRF, Base URL, Interceptors, Offline Detection
 */
export class ApiClient {
    static async get(url: string) {
        return Promise.resolve({ data: [] });
    }

    static async post(url: string, data: any) {
        return Promise.resolve({ data: { success: true } });
    }

    static async put(url: string, data: any) {
        return Promise.resolve({ data: { success: true } });
    }

    static async delete(url: string) {
        return Promise.resolve({ data: { success: true } });
    }
}
