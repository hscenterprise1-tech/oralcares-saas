/**
 * Centralized Logging Architecture Stub
 * Consumes events from the EventBus or manual errors to send to backend or Datadog/Sentry.
 */
export class Logger {
    static info(message: string, context?: any) {
        console.log(`[INFO] ${message}`, context);
    }

    static error(message: string, error?: any) {
        console.error(`[ERROR] ${message}`, error);
    }

    static audit(action: string, user: string, details?: any) {
        console.log(`[AUDIT] ${user} performed ${action}`, details);
    }
}
