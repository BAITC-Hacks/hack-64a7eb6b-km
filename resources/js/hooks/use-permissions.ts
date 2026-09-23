import { usePage } from '@inertiajs/react';
import type { PermissionName } from '@/types/auth';

export function usePermissions() {
    const { auth } = usePage().props;

    return {
        can: (permission: PermissionName) =>
            Boolean(auth.user) && auth.permissions.includes(permission),
        hasRole: (role: string) =>
            Boolean(auth.user) && auth.roles.includes(role),
        roles: auth.roles,
        permissions: auth.permissions,
    };
}
