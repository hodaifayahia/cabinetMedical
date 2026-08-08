import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type ApplicationBackNavigation = {
    href: BreadcrumbItem['href'];
    label: string;
    visible: boolean;
};

function pathOf(url: BreadcrumbItem['href'] | string): string {
    try {
        return new URL(toUrl(url as BreadcrumbItem['href']), 'http://localhost')
            .pathname;
    } catch {
        return '/';
    }
}

export function applicationBackNavigation(
    currentUrl: string,
    breadcrumbs: BreadcrumbItem[],
    dashboardHref: BreadcrumbItem['href'],
): ApplicationBackNavigation {
    const currentPath = pathOf(currentUrl);
    const dashboardPath = pathOf(dashboardHref);
    const parent = [...breadcrumbs]
        .reverse()
        .find((breadcrumb) => pathOf(breadcrumb.href) !== currentPath);

    return {
        href: parent?.href ?? dashboardHref,
        label: parent
            ? `Retour vers ${parent.title}`
            : 'Retour au tableau de bord',
        visible:
            currentPath !== dashboardPath &&
            (currentPath.startsWith('/app/') ||
                currentPath.startsWith('/settings/')),
    };
}
