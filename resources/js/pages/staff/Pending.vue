<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Clock3, Users } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type PendingMember = {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
};

const props = defineProps<{
    pending: PendingMember[];
    roles: string[];
    seats: { used: number; max: number };
}>();

const selectedRole = reactive<Record<number, string>>({});

const approveForm = useForm({ role: '' });

function approve(member: PendingMember): void {
    approveForm.role = selectedRole[member.id] ?? '';
    approveForm.post(`/app/staff/pending/${member.id}/approve`, {
        preserveScroll: true,
    });
}

function reject(member: PendingMember): void {
    if (!window.confirm(`Rejeter la demande de ${member.name} ?`)) {
        return;
    }

    router.delete(`/app/staff/pending/${member.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Demandes en attente" />

    <div class="med-page">
        <Heading
            title="Membres en attente"
            :description="`Approuvez ou rejetez les demandes d’accès à votre cabinet. Sièges utilisés : ${props.seats.used} / ${props.seats.max}.`"
        />

        <section class="med-panel p-6">
            <div v-if="props.pending.length === 0" class="med-empty">
                <Clock3 class="med-empty-icon" />
                <p class="med-empty-title">Aucune demande en attente</p>
                <p class="med-empty-hint">
                    Les nouvelles demandes d’accès apparaîtront ici pour
                    validation.
                </p>
            </div>

            <ul v-else class="space-y-4">
                <li
                    v-for="member in props.pending"
                    :key="member.id"
                    class="flex flex-col gap-4 rounded-xl border border-border/70 bg-background p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand"
                        >
                            <Users class="size-5" />
                        </span>
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate font-semibold">
                                    {{ member.name }}
                                </p>
                                <Badge variant="secondary">En attente</Badge>
                            </div>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ member.email }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <div class="grid gap-1.5">
                            <Label :for="`role-${member.id}`" class="text-xs">
                                Rôle
                            </Label>
                            <Select v-model="selectedRole[member.id]">
                                <SelectTrigger
                                    :id="`role-${member.id}`"
                                    class="w-48"
                                >
                                    <SelectValue placeholder="Choisir un rôle" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="role in props.roles"
                                        :key="role"
                                        :value="role"
                                    >
                                        {{ role }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <Button
                            :disabled="!selectedRole[member.id]"
                            @click="approve(member)"
                        >
                            Approuver
                        </Button>
                        <Button variant="destructive" @click="reject(member)">
                            Rejeter
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
