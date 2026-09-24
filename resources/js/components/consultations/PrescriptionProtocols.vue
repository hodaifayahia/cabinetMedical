<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { BookmarkPlus, Library, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type ProtocolItem = {
    medication: string;
    dosage: string;
    duration: string;
    instructions: string;
};

export type PrescriptionProtocol = {
    id: string;
    name: string;
    items: ProtocolItem[];
    notes: string | null;
    uses: number;
};

const props = defineProps<{
    protocols: PrescriptionProtocol[];
    currentItems: ProtocolItem[];
    currentNotes: string;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    apply: [items: ProtocolItem[], notes: string | null];
}>();

const open = ref(false);
const search = ref('');

const filledItems = computed(() =>
    props.currentItems.filter((item) => item.medication.trim() !== ''),
);

const form = useForm({
    name: '',
    notes: '',
    items: [] as ProtocolItem[],
});

const save = () => {
    form.items = filledItems.value;
    form.notes = props.currentNotes;
    form.post('/app/prescription-protocols', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('name'),
    });
};

const apply = (protocol: PrescriptionProtocol) => {
    emit(
        'apply',
        protocol.items.map((item) => ({ ...item })),
        protocol.notes,
    );
    router.post(
        `/app/prescription-protocols/${protocol.id}/used`,
        {},
        { preserveScroll: true, preserveState: true },
    );
    open.value = false;
};

const remove = (protocol: PrescriptionProtocol) => {
    router.delete(`/app/prescription-protocols/${protocol.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
};

const visible = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('fr');

    return term === ''
        ? props.protocols
        : props.protocols.filter(
              (protocol) =>
                  protocol.name.toLocaleLowerCase('fr').includes(term) ||
                  protocol.items.some((item) =>
                      item.medication.toLocaleLowerCase('fr').includes(term),
                  ),
          );
});
</script>

<template>
    <Button
        v-if="canEdit"
        type="button"
        variant="outline"
        size="sm"
        @click="open = true"
    >
        <Library class="size-4" />
        Protocoles
        <span
            v-if="protocols.length"
            class="rounded-full bg-muted px-1.5 text-[11px] tabular-nums"
            >{{ protocols.length }}</span
        >
    </Button>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Protocoles d’ordonnance</DialogTitle>
                <DialogDescription>
                    Chargez une ordonnance type en un clic. Elle reste
                    modifiable et les allergies du patient sont vérifiées à
                    l’enregistrement.
                </DialogDescription>
            </DialogHeader>

            <form
                v-if="filledItems.length"
                class="grid gap-2 rounded-xl border bg-muted/30 p-3"
                @submit.prevent="save"
            >
                <p class="text-sm font-semibold">
                    Enregistrer l’ordonnance en cours ({{ filledItems.length }}
                    médicament(s)) comme protocole
                </p>
                <div class="flex gap-2">
                    <Input
                        v-model="form.name"
                        placeholder="Ex. Angine adulte, HTA – initiation…"
                        aria-label="Nom du protocole"
                    />
                    <Button type="submit" :disabled="form.processing">
                        <BookmarkPlus class="size-4" />
                        Enregistrer
                    </Button>
                </div>
                <InputError :message="form.errors.name ?? form.errors.items" />
            </form>

            <Input
                v-if="protocols.length > 5"
                v-model="search"
                placeholder="Rechercher un protocole ou un médicament…"
                aria-label="Rechercher un protocole"
            />

            <ul v-if="visible.length" class="divide-y rounded-xl border">
                <li
                    v-for="protocol in visible"
                    :key="protocol.id"
                    class="flex items-start justify-between gap-3 p-3"
                >
                    <div class="min-w-0">
                        <p class="font-semibold">{{ protocol.name }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{
                                protocol.items
                                    .map((item) => item.medication)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button
                            type="button"
                            size="sm"
                            @click="apply(protocol)"
                        >
                            Utiliser
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            :aria-label="`Supprimer le protocole ${protocol.name}`"
                            @click="remove(protocol)"
                        >
                            <Trash2 class="size-4 text-rose-600" />
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Aucun protocole pour l’instant. Rédigez une ordonnance, puis
                enregistrez-la ici pour la réutiliser.
            </p>
        </DialogContent>
    </Dialog>
</template>
