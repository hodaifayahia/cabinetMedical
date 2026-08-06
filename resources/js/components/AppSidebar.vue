<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarDays,
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
