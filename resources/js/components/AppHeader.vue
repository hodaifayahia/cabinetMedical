<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarCog,
    CalendarDays,
    ChevronDown,
    Cloud,
    CloudOff,
    Clock,
    Download,
    LayoutGrid,
    Menu,
    Settings,
    Shield,
    Stethoscope,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { useSessionLockTimer } from '@/composables/useSessionLockTimer';
import { configurationNavForPermissions } from '@/lib/configurationNav';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const clinicName = computed(() => String(page.props.name ?? 'ClickDZ'));
const clinicLogoUrl = computed(() => page.props.cabinet?.logo_url ?? null);
const desktopDownload = computed(() => page.props.desktopDownload);
const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();

const canConfigureAppointments = computed(
    () =>
        auth.value.user?.permissions?.includes('appointments.configure') ??
        false,
);

const visibleConfigurationNav = computed(() =>
    configurationNavForPermissions(auth.value.user?.permissions ?? []),
);
const canManageConfiguration = computed(
    () => visibleConfigurationNav.value.length > 0,
);
const configurationLinks = computed(() =>
    visibleConfigurationNav.value.flatMap((group) => group.links),
);
const isConfigurationActive = computed(() =>
    configurationLinks.value.some((link) => isCurrentOrParentUrl(link.href)),
);

const mainNavItems = computed<NavItem[]>(() => {
    const permissions = auth.value.user?.permissions ?? [];

    const items: NavItem[] = [
        { title: 'Tableau de bord', href: dashboard(), icon: LayoutGrid },
    ];

    if (permissions.includes('patients.view')) {
        items.push({ title: 'Patients', href: '/app/patients', icon: Users });
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

    if (auth.value.user?.can.manageStaff) {
        items.push({
            title: 'Utilisateurs',
            href: '/app/staff',
            icon: UserCog,
        });
    }

    return items;
});

const rightNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (auth.value.user?.can.accessAdminPanel) {
        items.push({ title: 'Administration', href: '/admin', icon: Shield });
    }

    return items;
});

const roleLabels: Record<string, string> = {
    'Super Administrator': 'Super administrateur',
    Administrator: 'Administrateur',
    Doctor: 'Médecin',
    Receptionist: 'Réceptionniste',
    Cashier: 'Caissier',
    'Stock Manager': 'Gestionnaire de stock',
    Pharmacist: 'Pharmacien',
};

const userRole = computed(() => {
    const role = auth.value.user?.roles?.[0];

    return role ? (roleLabels[role] ?? role) : 'Membre';
});

const sessionLockState = computed(() => page.props.sessionLock);
const {
    isExpiringSoon: isSessionExpiringSoon,
    isPrivacyShieldActive,
    remainingSeconds,
} = useSessionLockTimer(sessionLockState);
const isOnline = ref(
    typeof navigator === 'undefined' ? true : navigator.onLine,
);

const formattedSessionTime = computed(() => {
    const total = Math.max(0, remainingSeconds.value);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});

const updateOnlineState = () => {
    isOnline.value = navigator.onLine;
};

onMounted(() => {
    window.addEventListener('online', updateOnlineState);
    window.addEventListener('offline', updateOnlineState);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateOnlineState);
    window.removeEventListener('offline', updateOnlineState);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isPrivacyShieldActive"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950"
            role="alert"
            aria-live="assertive"
            data-session-lock-no-activity
        >
            <div class="max-w-sm px-6 text-center text-white">
                <Shield class="mx-auto mb-4 size-10 text-blue-300" />
                <p class="text-lg font-semibold">Session verrouillée</p>
                <p class="mt-2 text-sm text-slate-300">
                    Protection de l’écran en cours…
                </p>
            </div>
        </div>
    </Teleport>

    <header
        class="sticky top-0 z-50 w-full border-b border-slate-200/80 bg-white/90 shadow-sm shadow-slate-900/5 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/90"
    >
        <div
            class="flex h-16 w-full min-w-0 items-center gap-2 px-4 lg:px-5 2xl:px-6"
        >
            <!-- Mobile menu -->
            <div class="xl:hidden">
                <Sheet>
                    <SheetTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="mr-1 h-9 w-9"
                        >
                            <Menu class="h-5 w-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        class="w-[300px] overflow-y-auto p-6"
                    >
                        <SheetTitle class="sr-only">
                            Menu de navigation
                        </SheetTitle>
                        <SheetHeader class="px-0 text-left">
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="clinicLogoUrl"
                                    :src="clinicLogoUrl"
                                    alt=""
                                    class="size-8 rounded-lg object-contain"
                                />
                                <Stethoscope
                                    v-else
                                    class="h-6 w-6 text-[#d89d16]"
                                />
                                <span
                                    class="text-lg font-bold tracking-tight text-slate-800 dark:text-white"
                                >
                                    {{ clinicName }}
                                </span>
                            </div>
                        </SheetHeader>
                        <nav class="mt-6 flex flex-col gap-1">
                            <Link
                                v-for="item in mainNavItems"
                                :key="item.title"
                                :href="item.href"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium"
                                :class="
                                    isCurrentUrl(item.href)
                                        ? 'bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400'
                                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                                "
                            >
                                <component :is="item.icon" class="h-4 w-4" />
                                {{ item.title }}
                            </Link>

                            <template v-if="canManageConfiguration">
                                <p
                                    class="mt-3 px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase"
                                >
                                    Configuration
                                </p>
                                <Link
                                    v-for="link in configurationLinks"
                                    :key="link.href"
                                    :href="link.href"
                                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
                                    :class="
                                        isCurrentUrl(link.href)
                                            ? 'bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300'
                                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                                    "
                                >
                                    {{ link.title }}
                                </Link>
                            </template>

                            <p
                                v-if="desktopDownload || rightNavItems.length"
                                class="mt-3 px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase"
                            >
                                Liens
                            </p>
                            <a
                                v-if="
                                    desktopDownload?.available &&
                                    desktopDownload.url
                                "
                                :href="desktopDownload.url"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                <Download class="h-4 w-4" />
                                {{ desktopDownload.label }}
                            </a>
                            <button
                                v-else-if="desktopDownload"
                                type="button"
                                disabled
                                class="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium text-slate-400"
                                :title="desktopDownload.reason ?? undefined"
                            >
                                <Download class="h-4 w-4" />
                                {{ desktopDownload.label }}
                            </button>
                            <a
                                v-for="item in rightNavItems"
                                :key="item.title"
                                :href="toUrl(item.href)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                <component :is="item.icon" class="h-4 w-4" />
                                {{ item.title }}
                            </a>
                        </nav>
                    </SheetContent>
                </Sheet>
            </div>

            <!-- Logo -->
            <Link
                :href="dashboard()"
                class="flex min-w-0 shrink-0 items-center gap-2 pr-1"
            >
                <img
                    v-if="clinicLogoUrl"
                    :src="clinicLogoUrl"
                    alt=""
                    class="size-9 rounded-xl object-contain"
                />
                <Stethoscope v-else class="h-7 w-7 text-[#d89d16]" />
                <span
                    class="hidden max-w-40 truncate text-lg font-bold tracking-tight whitespace-nowrap text-slate-800 sm:inline xl:hidden 2xl:inline dark:text-white"
                >
                    {{ clinicName }}
                </span>
            </Link>

            <!-- Desktop primary nav -->
            <nav
                class="ml-1 hidden min-w-0 flex-1 items-center gap-1 overflow-hidden xl:flex"
            >
                <Link
                    v-for="item in mainNavItems"
                    :key="item.title"
                    :href="item.href"
                    class="flex h-10 min-w-0 shrink items-center gap-2 rounded-xl border px-2.5 text-sm font-semibold whitespace-nowrap transition-all 2xl:px-3"
                    :class="
                        isCurrentUrl(item.href)
                            ? 'border-blue-400 bg-blue-50 text-blue-600 shadow-sm dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300'
                            : 'border-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-300 dark:hover:border-slate-700 dark:hover:bg-slate-800'
                    "
                >
                    <component :is="item.icon" class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ item.title }}</span>
                </Link>

                <!-- Configuration dropdown -->
                <DropdownMenu v-if="canManageConfiguration">
                    <DropdownMenuTrigger :as-child="true">
                        <button
                            type="button"
                            class="flex h-10 shrink-0 items-center gap-2 rounded-xl border px-2.5 text-sm font-semibold whitespace-nowrap transition-all 2xl:px-3"
                            :class="
                                isConfigurationActive
                                    ? 'border-blue-400 bg-blue-50 text-blue-600 shadow-sm dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300'
                                    : 'border-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900 data-[state=open]:border-blue-300 data-[state=open]:bg-blue-50 data-[state=open]:text-blue-600 dark:text-slate-300 dark:hover:border-slate-700 dark:hover:bg-slate-800'
                            "
                        >
                            <Settings class="h-4 w-4 shrink-0" />
                            <span class="hidden 2xl:inline"
                                >Configuration</span
                            >
                            <ChevronDown class="h-4 w-4 shrink-0" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-64 p-2">
                        <div
                            v-for="navGroup in visibleConfigurationNav"
                            :key="navGroup.label"
                            class="mb-1 last:mb-0"
                        >
                            <p
                                class="px-2 py-1 text-xs font-semibold tracking-wide text-slate-400 uppercase"
                            >
                                {{ navGroup.label }}
                            </p>
                            <Link
                                v-for="link in navGroup.links"
                                :key="link.href"
                                :href="link.href"
                                class="flex items-center rounded-md px-2.5 py-2 text-sm font-medium transition-colors"
                                :class="
                                    isCurrentUrl(link.href)
                                        ? 'bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400'
                                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                                "
                            >
                                {{ link.title }}
                            </Link>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>
            </nav>

            <!-- Right cluster -->
            <div class="ml-auto flex shrink-0 items-center gap-2">
                <TooltipProvider :delay-duration="0">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                v-if="
                                    desktopDownload?.available &&
                                    desktopDownload.url
                                "
                                as-child
                                class="hidden h-10 shrink-0 rounded-full bg-slate-900 px-3 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200 xl:inline-flex 2xl:px-4"
                            >
                                <a :href="desktopDownload.url">
                                    <Download class="h-4 w-4 shrink-0" />
                                    <span class="hidden 2xl:inline">
                                        App desktop
                                    </span>
                                </a>
                            </Button>
                            <Button
                                v-else-if="desktopDownload"
                                disabled
                                class="hidden h-10 shrink-0 cursor-not-allowed rounded-full px-3 xl:inline-flex 2xl:px-4"
                                variant="outline"
                            >
                                <Download class="h-4 w-4 shrink-0" />
                                <span class="hidden 2xl:inline">
                                    App desktop
                                </span>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>
                                {{
                                    desktopDownload?.available
                                        ? desktopDownload.label
                                        : desktopDownload?.reason
                                }}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <!-- Icon actions -->
                <div
                    class="hidden items-center gap-1 rounded-full border border-slate-200 bg-slate-50/80 p-1 dark:border-slate-800 dark:bg-slate-900/80 xl:flex"
                >
                    <TooltipProvider
                        v-if="canConfigureAppointments"
                        :delay-duration="0"
                    >
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    as-child
                                    class="h-8 w-8 rounded-full text-slate-500 hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <Link href="/app/appointments/configure">
                                        <span class="sr-only"
                                            >Configuration des rendez-vous</span
                                        >
                                        <CalendarCog class="h-5 w-5" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Configuration des rendez-vous</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>

                    <template v-for="item in rightNavItems" :key="item.title">
                        <TooltipProvider :delay-duration="0">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        as-child
                                        class="h-8 w-8 rounded-full text-slate-500 hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                                    >
                                        <a
                                            :href="toUrl(item.href)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <span class="sr-only">{{
                                                item.title
                                            }}</span>
                                            <component
                                                :is="item.icon"
                                                class="h-5 w-5"
                                            />
                                        </a>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{{ item.title }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </template>
                </div>

                <!-- Online state -->
                <div
                    class="hidden items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors sm:flex"
                    :class="
                        isOnline
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                    "
                    :title="
                        isOnline
                            ? 'En ligne'
                            : 'Hors ligne · les modifications locales restent sur cet appareil'
                    "
                >
                    <Cloud v-if="isOnline" class="h-4 w-4 shrink-0" />
                    <CloudOff v-else class="h-4 w-4 shrink-0" />
                    <span class="hidden 2xl:inline">{{
                        isOnline ? 'En ligne' : 'Hors ligne'
                    }}</span>
                </div>

                <!-- Session lock countdown based on real user activity -->
                <div
                    class="hidden items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold whitespace-nowrap tabular-nums transition-colors sm:flex"
                    :class="
                        isSessionExpiringSoon
                            ? 'bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400'
                            : 'bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400'
                    "
                    role="timer"
                    :title="`Verrouillage automatique après inactivité · ${formattedSessionTime} restante`"
                >
                    <Clock class="h-4 w-4 shrink-0" />
                    <span>{{ formattedSessionTime }}</span>
                </div>

                <!-- User -->
                <DropdownMenu v-if="auth.user">
                    <DropdownMenuTrigger :as-child="true">
                        <button
                            type="button"
                            class="flex min-w-0 items-center gap-2 rounded-full p-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        >
                            <Avatar class="h-9 w-9">
                                <AvatarImage
                                    v-if="auth.user.avatar"
                                    :src="auth.user.avatar"
                                    :alt="auth.user.name"
                                />
                                <AvatarFallback
                                    class="bg-blue-600 text-sm font-semibold text-white"
                                >
                                    {{ getInitials(auth.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                class="hidden max-w-36 text-left leading-tight 2xl:block"
                            >
                                <span
                                    class="block truncate text-sm font-semibold whitespace-nowrap text-slate-800 dark:text-white"
                                >
                                    {{ auth.user.name }}
                                </span>
                                <span
                                    class="block truncate text-xs whitespace-nowrap text-slate-500 dark:text-slate-400"
                                >
                                    {{ userRole }}
                                </span>
                            </span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!-- Breadcrumbs -->
        <div
            v-if="props.breadcrumbs.length > 1"
            class="flex w-full border-t border-slate-200 dark:border-slate-800"
        >
            <div
                class="flex h-12 w-full items-center justify-start px-4 text-slate-500 lg:px-6"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </header>
</template>
