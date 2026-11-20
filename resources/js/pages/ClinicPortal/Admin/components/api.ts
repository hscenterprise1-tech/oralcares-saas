import { ClinicHttpError, ERROR_COPY } from '@/clinic/http';

/** What the server said went wrong, in words a person can act on. */
export function problem(error: unknown): string {
  if (error instanceof ClinicHttpError) {
    const body = error.body as { message?: string; errors?: Record<string, string[]> } | null;
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;

    return first ?? body?.message ?? ERROR_COPY[error.kind];
  }

  return ERROR_COPY.server;
}
