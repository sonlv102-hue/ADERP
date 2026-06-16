import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const page = usePage();

    const hasPermission = (permission) => {
        return page.props.auth?.permissions?.includes(permission) ?? false;
    };

    const hasRole = (role) => {
        return page.props.auth?.roles?.includes(role) ?? false;
    };

    const hasAnyRole = (...roles) => {
        return roles.some(r => hasRole(r));
    };

    return { hasPermission, can: hasPermission, hasRole, hasAnyRole };
}
