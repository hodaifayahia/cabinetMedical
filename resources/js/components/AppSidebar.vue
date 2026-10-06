<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Banknote,
    BellRing,
    CalendarDays,
    ChartColumnBig,
    LayoutGrid,
    Stethoscope,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const permissions = page.props.auth.user?.permissions ?? [];

    // Online service: patient screens live in the desktop app.
    if (page.props.clinicalScreensOpen === false) {
        const onlineItems: NavItem[] = [
            {
                title: 'Espace cabinet',
                href: '/espace-cabinet',
                icon: LayoutGrid,
            },
        ];

        if (page.props.auth.user?.can.manageStaff) {
            onlineItems.push({
                title: 'Utilisateurs',
                href: '/app/staff',
                icon: UserCog,
            });
        }

        return onlineItems;
    }

    const items: NavItem[] = [
        {
            title: 'Tableau de bord',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (permissions.includes('patients.view')) {
        items.push({
            title: 'Patients',
            href: '/app/patients',
            icon: Users,
        });
    }

    if (permissions.includes('appointments.view')) {
        items.push({
            title: 'Rendez-vous',
            href: '/app/appointments',
            icon: CalendarDays,
        });
        items.push({
            title: 'Relances',
            href: '/app/reminders',
            icon: BellRing,
        });
    }

    if (permissions.includes('consultations.view')) {
        items.push({
            title: 'Consultation',
            href: '/app/consultations',
            icon: Stethoscope,
        });
    }

    if (permissions.includes('payments.view')) {
        items.push({
            title: 'Paiements',
            href: '/app/payments',
            icon: Banknote,
        });
        items.push({
            title: 'Analyse financière',
            href: '/app/payments/analytics',
            icon: ChartColumnBig,
        });
    }

    if (permissions.includes('reports.view')) {
        items.push({
            title: 'Statistiques',
            href: '/app/statistics',
            icon: Activity,
        });
    }

    if (page.props.auth.user?.can.manageStaff) {
        items.push({
            title: 'Utilisateurs',
            href: '/app/staff',
            icon: UserCog,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
