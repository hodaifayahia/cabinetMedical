<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Trash2,
    UserCog,
    UserPlus,
    Users,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { staffPaginationLabel, staffRoleLabel } from '@/pages/staff/display';
import type { ConfigurationCapability } from '@/types';

type StaffMember = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    roles: string[];
    cabinet: { id: number; name: string } | null;
    created_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type StaffPaginator = {
    data: StaffMember[];
    from: number | null;
    links: PaginationLink[];
    to: number | null;
    total: number;
};

const props = defineProps<{
    staff: StaffPaginator;
    filters: { search: string; role: string };
    roles: string[];
    cabinet: { id: number; name: string };
    currentUserId: number;
    multiUserCapability: ConfigurationCapability;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Utilisateurs', href: '/app/staff' }],
    },
});

const localFilters = reactive({ ...props.filters });
const showForm = ref(false);
const editing = ref<StaffMember | null>(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: '',
    assigned_to_cabinet: true,
});

const initials = (name: string): string =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || '?';

const applyFilters = () => {
    router.get(
        '/app/staff',
        {
            search: localFilters.search || undefined,
            role: localFilters.role || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const openCreate = () => {
    if (!props.multiUserCapability.available) {
        return;
    }

    editing.value = null;
    form.reset();
    form.clearErrors();
    form.assigned_to_cabinet = true;
    form.role = props.roles[0] ?? '';
    showForm.value = true;
};

const openEdit = (member: StaffMember) => {
    editing.value = member;
    form.name = member.name;
    form.email = member.email;
    form.password = '';
    form.password_confirmation = '';
    form.role = member.roles[0] ?? props.roles[0] ?? '';
    form.assigned_to_cabinet = member.cabinet !== null;
    form.clearErrors();
    showForm.value = true;
};

const saveUser = () => {
    if (editing.value) {
        form.put('/app/staff/' + editing.value.id, {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
            },
        });

        return;
    }

    form.post('/app/staff', {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
};

const removeUser = (member: StaffMember) => {
    if (
        !window.confirm(
            'Supprimer ' +
                member.name +
                ' ? Cet utilisateur ne pourra plus se connecter.',
        )
    ) {
        return;
    }

    router.delete('/app/staff/' + member.id, { preserveScroll: true });
};
</script>

<template>
    <Head title="Utilisateurs" />

    <div class="med-page">
        <PageHeader
            title="Utilisateurs"
            description="Ajoutez les utilisateurs du cabinet, attribuez leurs fonctions et contrôlez leur accès."
        >
            <template #actions>
                <Button
                    :disabled="!multiUserCapability.available"
                    @click="openCreate"
                >
                    <UserPlus class="size-4" />
                    Ajouter un utilisateur
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!multiUserCapability.available && multiUserCapability.reason"
            class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            {{ multiUserCapability.reason }}
        </div>

        <section class="grid gap-4 sm:grid-cols-3">
            <article class="med-panel p-5">
                <div class="flex items-center gap-3">
                    <span class="med-stat-icon">
                        <Users class="size-5" />
                    </span>
                    <div>
                        <p class="text-2xl font-bold tabular-nums">
                            {{ staff.total }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Utilisateurs du cabinet
                        </p>
                    </div>
                </div>
            </article>
            <article class="med-panel p-5">
                <div class="flex items-center gap-3">
                    <span class="med-stat-icon">
                        <Building2 class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-bold">
                            {{ cabinet.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Cabinet médical actif
                        </p>
                    </div>
                </div>
            </article>
            <article class="med-panel p-5">
                <div class="flex items-center gap-3">
                    <span class="med-stat-icon">
                        <ShieldCheck class="size-5" />
                    </span>
                    <div>
                        <p class="text-2xl font-bold tabular-nums">
                            {{ roles.length }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Fonctions disponibles
                        </p>
                    </div>
                </div>
            </article>
        </section>

        <section class="med-panel overflow-hidden">
            <div
                class="flex flex-col gap-3 border-b border-border bg-muted/25 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="relative w-full max-w-md">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="localFilters.search"
                        class="pl-9"
                        aria-label="Rechercher un utilisateur"
                        placeholder="Rechercher par nom ou adresse e-mail…"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <div class="flex gap-2">
                    <select
                        v-model="localFilters.role"
                        aria-label="Filtrer par fonction"
                        class="med-native-control min-w-44"
                        @change="applyFilters"
                    >
                        <option value="">Toutes les fonctions</option>
                        <option v-for="role in roles" :key="role" :value="role">
                            {{ staffRoleLabel(role) }}
                        </option>
                    </select>
                    <Button variant="outline" @click="applyFilters">
                        <Search class="size-4" />
                        Rechercher
                    </Button>
                </div>
            </div>

            <div class="med-table-wrap rounded-none border-x-0 border-b-0">
                <table class="med-table">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 font-medium">Utilisateur</th>
                            <th class="px-4 py-3 font-medium">E-mail</th>
                            <th class="px-4 py-3 font-medium">Fonction</th>
                            <th class="px-4 py-3 font-medium">Cabinet</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="member in staff.data"
                            :key="member.id"
                            class="bg-background transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <div class="flex min-w-44 items-center gap-3">
                                    <span
                                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-bold text-brand-foreground"
                                    >
                                        {{ initials(member.name) }}
                                    </span>
                                    <span>
                                        <span class="block font-semibold">{{
                                            member.name
                                        }}</span>
                                        <span
                                            v-if="member.id === currentUserId"
                                            class="text-xs font-medium text-brand"
                                        >
                                            Vous
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ member.email }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge
                                        v-for="role in member.roles"
                                        :key="role + '-' + member.id"
                                        variant="secondary"
                                    >
                                        {{ staffRoleLabel(role) }}
                                    </Badge>
                                    <span
                                        v-if="!member.roles.length"
                                        class="text-xs text-muted-foreground"
                                        >Aucune fonction</span
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <Badge
                                    v-if="member.cabinet"
                                    variant="secondary"
                                >
                                    <Building2 class="size-3" />
                                    {{ member.cabinet.name }}
                                </Badge>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >Non affecté</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        member.email_verified_at
                                            ? 'bg-brand-soft text-brand'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                                    "
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="
                                            member.email_verified_at
                                                ? 'bg-brand'
                                                : 'bg-amber-500'
                                        "
                                    />
                                    {{
                                        member.email_verified_at
                                            ? 'Actif'
                                            : 'En attente'
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Modifier l’utilisateur"
                                        title="Modifier l’utilisateur"
                                        @click="openEdit(member)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        v-if="member.id !== currentUserId"
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        aria-label="Supprimer l’utilisateur"
                                        title="Supprimer l’utilisateur"
                                        @click="removeUser(member)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!staff.data.length">
                            <td colspan="6">
                                <div class="med-empty">
                                    <Users class="med-empty-icon" />
                                    <p class="med-empty-title">
                                        Aucun utilisateur trouvé
                                    </p>
                                    <p class="med-empty-hint">
                                        Modifiez la recherche ou ajoutez un
                                        nouvel utilisateur au cabinet.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer
                class="flex flex-col gap-3 border-t border-border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Affichage de {{ staff.from ?? 0 }} à {{ staff.to ?? 0 }} sur
                    {{ staff.total }} utilisateurs
                </p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in staff.links" :key="link.label">
                        <Button
                            v-if="link.url"
                            as-child
                            size="sm"
                            :variant="link.active ? 'default' : 'outline'"
                        >
                            <Link :href="link.url" preserve-scroll>
                                <span
                                    v-html="staffPaginationLabel(link.label)"
                                />
                            </Link>
                        </Button>
                        <Button v-else size="sm" variant="outline" disabled>
                            <span v-html="staffPaginationLabel(link.label)" />
                        </Button>
                    </template>
                </div>
            </footer>
        </section>

        <button
            v-if="multiUserCapability.available"
            type="button"
            class="fixed right-5 bottom-5 z-30 flex size-14 items-center justify-center rounded-full bg-brand text-brand-foreground shadow-lg shadow-brand/25 transition hover:-translate-y-0.5 hover:bg-brand/90 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-offset-2 sm:hidden"
            aria-label="Ajouter un utilisateur"
            title="Ajouter un utilisateur"
            @click="openCreate"
        >
            <Plus class="size-7" />
        </button>
    </div>

    <Dialog v-model:open="showForm">
        <DialogContent class="max-h-[92vh] overflow-y-auto p-0 sm:max-w-2xl">
            <div class="bg-brand px-6 py-5 text-brand-foreground">
                <DialogHeader>
                    <DialogTitle
                        class="flex items-center gap-2 text-xl text-brand-foreground"
                    >
                        <UserCog class="size-5" />
                        {{
                            editing
                                ? 'Modifier l’utilisateur'
                                : 'Ajouter un utilisateur'
                        }}
                    </DialogTitle>
                    <DialogDescription class="text-brand-foreground/80">
                        Configurez le compte, sa fonction et son affectation au
                        cabinet médical.
                    </DialogDescription>
                </DialogHeader>
            </div>

            <form class="grid gap-5 p-6" @submit.prevent="saveUser">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="staff-name">Nom complet</Label>
                        <Input
                            id="staff-name"
                            v-model="form.name"
                            autocomplete="name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="staff-email">E-mail</Label>
                        <Input
                            id="staff-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Fonction</Label>
                        <Select
                            :model-value="form.role || undefined"
                            @update:model-value="
                                (value) => (form.role = value as string)
                            "
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Sélectionner une fonction"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in roles"
                                    :key="role"
                                    :value="role"
                                >
                                    {{ staffRoleLabel(role) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.role" />
                    </div>
                    <label
                        class="flex items-center justify-between rounded-xl border border-border bg-muted/25 p-4"
                    >
                        <span>
                            <span
                                class="flex items-center gap-1.5 text-sm font-semibold"
                            >
                                <Building2 class="size-4 text-brand" />
                                Affecter au cabinet
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                                >{{ cabinet.name }}</span
                            >
                        </span>
                        <input
                            v-model="form.assigned_to_cabinet"
                            type="checkbox"
                            class="size-5 accent-brand"
                        />
                    </label>
                    <div class="grid gap-2">
                        <Label for="staff-password">
                            Mot de passe
                            <span
                                v-if="editing"
                                class="font-normal text-muted-foreground"
                                >(laisser vide pour le conserver)</span
                            >
                        </Label>
                        <Input
                            id="staff-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="staff-password-confirmation"
                            >Confirmer le mot de passe</Label
                        >
                        <Input
                            id="staff-password-confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showForm = false"
                        >Annuler</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <UserPlus class="size-4" />
                        {{
                            editing
                                ? 'Enregistrer les modifications'
                                : 'Ajouter un utilisateur'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
