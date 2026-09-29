import React from 'react';
import { usePermissions } from '@/hooks/usePermissions';

/**
 * The one guard every Company navigation surface asks.
 *
 * The header module nav, its mobile sheet, the floating rails, the create menus
 * and the command palette each carried their own copy of this rule, and the
 * copies had already drifted — which is how a master admin ended up seeing four
 * different answers to the same question. There is now one rule, and they all
 * call it.
 *
 * It resolves in this order:
 *
 *   1. No permission on the entry — everyone sees it. This is what makes an
 *      entry deliberately open: `/profile` and the personal notification inbox
 *      are the reader's own account, so they are never gated, and they sit on
 *      the avatar rather than in a module for exactly that reason.
 *   2. Super admin — sees everything, because the server's `Gate::before` hook
 *      already authorizes them for every ability. Read from a server-supplied
 *      flag, never inferred from role names: no role in this database is called
 *      `Super Admin` or `master-admin`, so the old name checks never matched
 *      the user they were written for.
 *   3. Otherwise, the permission the user actually holds.
 *
 * What is deliberately *not* here any more: "an empty permission list means
 * show everything". That was a convenience for a database where nobody had been
 * given a role yet, and it inverted the rule it was bolted onto — a user with
 * no grants at all was shown every destination in the product and then refused
 * by each route in turn. A menu that offers what the server will deny is worse
 * than a short menu: it teaches the reader that the product is broken rather
 * than that their account is incomplete.
 *
 * This narrows navigation only. Every route keeps its own `can:` middleware,
 * controller `Gate::authorize` and policy, and none of them changed — hiding an
 * entry has never been what protects the page behind it.
 */
export function useNavAuthorization() {
    const { permissions, isSuperAdmin, hasPermission } = usePermissions();

    return React.useCallback(
        (permission?: string): boolean => {
            if (!permission) {
                return true;
            }

            if (isSuperAdmin) {
                return true;
            }

            return hasPermission(permission);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [permissions, isSuperAdmin]
    );
}
