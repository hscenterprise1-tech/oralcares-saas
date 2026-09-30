/**
 * Future Offline Sync Architecture Stub
 */
export class OfflineManager {
    static isOnline = true;

    static async syncPendingQueue() {
        return Promise.resolve();
    }

    static queueOfflineMutation(mutation: any) {
        // save to indexedDB
    }
}
