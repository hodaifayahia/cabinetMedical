<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { isTauri } from '@tauri-apps/api/core';
import { onMounted, ref } from 'vue';
import { LockKeyhole, LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

const page = usePage();
const logoutDestination = ref(logout());

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

const handleLock = () => {
    router.flushAll();
    router.post(
        '/session/lock',
        {
            intended: `${window.location.pathname}${window.location.search}`,
            session_instance_id: page.props.sessionLock?.instanceId ?? '',
        },
        { replace: true },
    );
};

defineProps<Props>();

onMounted(() => {
    if (isTauri()) {
        logoutDestination.value = logout({ query: { desktop: 1 } });
    }
});
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Paramètres
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="w-full cursor-pointer"
        data-session-lock-no-activity
        data-test="lock-session-button"
        @click="handleLock"
    >
        <LockKeyhole class="mr-2 h-4 w-4" />
        Verrouiller la session
    </DropdownMenuItem>
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logoutDestination"
            @click="handleLogout"
            as="button"
            data-session-lock-no-activity
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Se déconnecter
        </Link>
    </DropdownMenuItem>
</template>
