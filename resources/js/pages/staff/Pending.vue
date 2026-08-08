<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
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

    <div class="space-y-6 p-4">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold">Membres en attente</h1>
            <p class="text-sm text-muted-foreground">
                Approuvez ou rejetez les demandes d'accès à votre cabinet.
                Sièges utilisés : {{ props.seats.used }} /
                {{ props.seats.max }}.
            </p>
        </header>

        <p
            v-if="props.pending.length === 0"
            class="text-sm text-muted-foreground"
        >
            Aucune demande en attente.
        </p>

        <ul v-else class="space-y-4">
            <li
                v-for="member in props.pending"
                :key="member.id"
                class="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="space-y-1">
                    <p class="font-medium">{{ member.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ member.email }}
                    </p>
                    <Badge variant="secondary">En attente</Badge>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <div class="grid gap-1">
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
    </div>
</template>
