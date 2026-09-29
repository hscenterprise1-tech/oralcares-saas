export interface User {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
    /**
     * Mirrors the server's `Gate::before` bypass.
     *
     * True for a master admin, who is authorized for every ability regardless
     * of the permissions listed above. Read it through `usePermissions()`
     * rather than testing it directly, so one rule covers every surface.
     */
    is_super_admin?: boolean;
    user_type?: string;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User | null;
    };
    flash: {
        message: string | null;
        error: string | null;
    };
};
