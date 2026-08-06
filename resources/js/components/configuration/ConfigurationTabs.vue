<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { configurationNavForPermissions } from '@/lib/configurationNav';

const { isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();
const visibleConfigurationNav = computed(() =>
    configurationNavForPermissions(page.props.auth.user?.permissions ?? []),
);

const activeGroupIndex = computed(() => {
    const index = visibleConfigurationNav.value.findIndex((group) =>
        group.links.some((link) => isCurrentOrParentUrl(link.href)),
    );

    return index >= 0 ? index : 0;
});

const activeGroup = computed(
    () =>
        visibleConfigurationNav.value[activeGroupIndex.value] ?? {
            label: '',
            links: [],
        },
);
</script>

<template>
    <div class="space-y-2">
        <nav
            class="flex flex-wrap items-center gap-3 border-b border-sidebar-border/70 pb-1"
        >
            <Link
                v-for="(group, index) in visibleConfigurationNav"
                :key="group.label"
                :href="group.links[0].href"
                class="relative px-1 py-1.5 text-sm font-medium transition"
                :class="
                    index === activeGroupIndex
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                {{ group.label }}
                <span
                    v-if="index === activeGroupIndex"
                    class="absolute inset-x-0 -bottom-1 block h-0.5 rounded bg-primary"
                />
            </Link>
        </nav>

        <nav
            class="flex flex-wrap items-center gap-1 rounded-xl bg-[#4c82b7] p-1.5 shadow-sm"
        >
            <Link
                v-for="link in activeGroup.links"
                :key="link.href"
                :href="link.href"
                class="rounded-lg px-3 py-2 text-sm font-semibold transition"
                :class="
                    isCurrentOrParentUrl(link.href)
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-white/85 hover:bg-white/10 hover:text-white'
                "
            >
                {{ link.title }}
            </Link>
        </nav>
    </div>
</template>
