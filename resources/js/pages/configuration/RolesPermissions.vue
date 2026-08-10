<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    LoaderCircle,
    LockKeyhole,
    RotateCcw,
    Save,
    ShieldCheck,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Permission = {
    name: string;
    label: string;
};

type PermissionGroup = {
    key: string;
    label: string;
    permissions: Permission[];
};

type Role = {
    name: string;
    label: string;
    permissions: string[];
    permission_count: number;
    locked: boolean;
    customized: boolean;
};

type CabinetUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_owner: boolean;
    can_assign: boolean;
};

type AssignableRole = {
    name: string;
    label: string;
};

const props = defineProps<{
    permissionGroups: PermissionGroup[];
    roles: Role[];
    users: CabinetUser[];
    assignableRoles: AssignableRole[];
}>();

const form = useForm({
    roles: props.roles
        .filter((role) => !role.locked)
        .map((role) => ({
            name: role.name,
            permissions: [...role.permissions],
        })),
});

const assigningUserId = ref<number | null>(null);
const assignableRoleNames = computed(
    () => new Set(props.assignableRoles.map((role) => role.name)),
);

const editableRole = (roleName: string) =>
    form.roles.find((role) => role.name === roleName);

const permissionEnabled = (role: Role, permissionName: string) =>
    role.locked
        ? role.permissions.includes(permissionName)
        : (editableRole(role.name)?.permissions.includes(permissionName) ??
          false);

const permissionCount = (role: Role) =>
    role.locked
        ? role.permission_count
        : (editableRole(role.name)?.permissions.length ?? 0);

const togglePermission = (
    role: Role,
    permissionName: string,
    checked: boolean,
) => {
    if (role.locked) {
        return;
    }

    const editable = editableRole(role.name);

    if (!editable) {
        return;
    }

    editable.permissions = checked
        ? [...new Set([...editable.permissions, permissionName])]
        : editable.permissions.filter(
              (permission) => permission !== permissionName,
          );
};

const saveMatrix = () => {
    form.put('/app/configuration/roles-permissions', {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
};

const assignRole = (user: CabinetUser, roleName: string) => {
    if (
        assigningUserId.value !== null ||
        !user.can_assign ||
        !assignableRoleNames.value.has(roleName) ||
        user.role === roleName
    ) {
        return;
    }

    assigningUserId.value = user.id;
    router.put(
        `/app/configuration/roles-permissions/users/${user.id}`,
        { role: roleName },
        {
            preserveScroll: true,
            onFinish: () => {
                assigningUserId.value = null;
            },
        },
    );
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Configuration', href: '/app/configuration' },
            {
                title: 'Rôles & permissions',
                href: '/app/configuration/roles-permissions',
            },
        ],
    },
});
</script>

<template>
    <Head title="Rôles & permissions" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel overflow-hidden">
            <div
                class="flex flex-col gap-5 border-b border-border px-5 py-6 sm:px-6 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-3xl">
                    <Heading
                        title="Rôles & permissions"
                        description="Choisissez précisément les autorisations accordées à chaque fonction du cabinet."
                    />
                    <p class="mt-3 text-sm text-muted-foreground">
                        Les changements s’appliquent uniquement à ce cabinet et
                        prennent effet pour tous les utilisateurs du rôle.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing || !form.isDirty"
                        @click="form.reset()"
                    >
                        <RotateCcw class="size-4" />
                        Annuler
                    </Button>
                    <Button
                        type="button"
                        :disabled="form.processing || !form.isDirty"
                        @click="saveMatrix"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <Save v-else class="size-4" />
                        Enregistrer les permissions
                    </Button>
                </div>
            </div>

            <div
                v-if="Object.keys(form.errors).length"
                class="mx-5 mt-5 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive sm:mx-6"
                role="alert"
            >
                La matrice n’a pas pu être enregistrée. Vérifiez les choix puis
                réessayez.
            </div>

            <div class="overflow-x-auto px-5 py-6 sm:px-6">
                <table
                    class="w-full min-w-[1080px] border-separate border-spacing-0 overflow-hidden rounded-xl border border-border text-sm"
                >
                    <thead>
                        <tr class="bg-muted/45">
                            <th
                                scope="col"
                                class="sticky left-0 z-20 min-w-[260px] border-r border-b border-border bg-muted px-4 py-4 text-left font-semibold"
                            >
                                Autorisation
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.name"
                                scope="col"
                                class="min-w-[145px] border-b border-border px-3 py-3 text-center align-top"
                            >
                                <div
                                    class="flex items-center justify-center gap-1.5 font-semibold"
                                >
                                    <LockKeyhole
                                        v-if="role.locked"
                                        class="size-3.5 text-muted-foreground"
                                        aria-label="Rôle protégé"
                                    />
                                    <span>{{ role.label }}</span>
                                </div>
                                <Badge
                                    variant="secondary"
                                    class="mt-2 font-normal"
                                >
                                    {{ permissionCount(role) }} actives
                                </Badge>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            v-for="group in permissionGroups"
                            :key="group.key"
                        >
                            <tr>
                                <th
                                    :colspan="roles.length + 1"
                                    class="border-b border-border bg-brand-soft px-4 py-2.5 text-left text-xs font-bold tracking-wide text-brand uppercase"
                                >
                                    {{ group.label }}
                                </th>
                            </tr>
                            <tr
                                v-for="permission in group.permissions"
                                :key="permission.name"
                                class="group/permission hover:bg-muted/20"
                            >
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 border-r border-b border-border bg-background px-4 py-3 text-left group-hover/permission:bg-muted/20"
                                >
                                    <span class="block font-medium">
                                        {{ permission.label }}
                                    </span>
                                    <span
                                        class="mt-0.5 block font-mono text-[11px] font-normal text-muted-foreground"
                                    >
                                        {{ permission.name }}
                                    </span>
                                </th>
                                <td
                                    v-for="role in roles"
                                    :key="`${permission.name}-${role.name}`"
                                    class="border-b border-border px-3 py-3 text-center"
                                >
                                    <label
                                        class="inline-flex items-center justify-center"
                                        :class="
                                            role.locked
                                                ? 'cursor-not-allowed'
                                                : 'cursor-pointer'
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            class="peer sr-only"
                                            :checked="
                                                permissionEnabled(
                                                    role,
                                                    permission.name,
                                                )
                                            "
                                            :disabled="
                                                role.locked || form.processing
                                            "
                                            :aria-label="`${permission.label} — ${role.label}`"
                                            @change="
                                                togglePermission(
                                                    role,
                                                    permission.name,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        <span
                                            class="flex size-7 items-center justify-center rounded-lg border border-input bg-background text-transparent shadow-xs transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-disabled:opacity-65"
                                        >
                                            <Check class="size-4" />
                                        </span>
                                    </label>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div
                class="flex items-start gap-3 border-t border-border bg-muted/20 px-5 py-4 text-sm text-muted-foreground sm:px-6"
            >
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand" />
                <p>
                    Le rôle Super administrateur est protégé. Ses autorisations
                    globales ne peuvent pas être modifiées depuis un cabinet.
                </p>
            </div>
        </section>

        <section class="med-panel overflow-hidden">
            <div class="border-b border-border px-5 py-6 sm:px-6">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand"
                    >
                        <UsersRound class="size-5" />
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold">
                            Rôle de chaque utilisateur
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Activez une seule fonction par utilisateur. Les
                            autorisations de la matrice ci-dessus seront
                            appliquées immédiatement.
                        </p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto px-5 py-6 sm:px-6">
                <table
                    class="w-full min-w-[880px] border-separate border-spacing-0 overflow-hidden rounded-xl border border-border text-sm"
                >
                    <thead>
                        <tr class="bg-muted/45">
                            <th
                                scope="col"
                                class="sticky left-0 z-20 min-w-[240px] border-r border-b border-border bg-muted px-4 py-3 text-left font-semibold"
                            >
                                Utilisateur
                            </th>
                            <th
                                v-for="role in roles"
                                :key="`user-role-${role.name}`"
                                scope="col"
                                class="min-w-[140px] border-b border-border px-3 py-3 text-center font-semibold"
                            >
                                {{ role.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="hover:bg-muted/20"
                        >
                            <th
                                scope="row"
                                class="sticky left-0 z-10 border-r border-b border-border bg-background px-4 py-3 text-left"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold">
                                        {{ user.name }}
                                    </span>
                                    <Badge
                                        v-if="user.is_owner"
                                        variant="secondary"
                                        class="text-[10px]"
                                    >
                                        Propriétaire
                                    </Badge>
                                </span>
                                <span
                                    class="mt-0.5 block text-xs font-normal text-muted-foreground"
                                >
                                    {{ user.email }}
                                </span>
                            </th>
                            <td
                                v-for="role in roles"
                                :key="`${user.id}-${role.name}`"
                                class="border-b border-border px-3 py-3 text-center"
                            >
                                <button
                                    type="button"
                                    class="inline-flex size-8 items-center justify-center rounded-full border transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-45"
                                    :class="
                                        user.role === role.name
                                            ? 'border-brand bg-brand text-white'
                                            : 'border-input bg-background text-transparent hover:border-brand/60'
                                    "
                                    :disabled="
                                        assigningUserId !== null ||
                                        !user.can_assign ||
                                        !assignableRoleNames.has(role.name)
                                    "
                                    :aria-label="`Attribuer le rôle ${role.label} à ${user.name}`"
                                    :aria-pressed="user.role === role.name"
                                    @click="assignRole(user, role.name)"
                                >
                                    <LoaderCircle
                                        v-if="assigningUserId === user.id"
                                        class="size-4 animate-spin text-brand"
                                    />
                                    <Check v-else class="size-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td
                                :colspan="roles.length + 1"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                Aucun utilisateur actif dans ce cabinet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
