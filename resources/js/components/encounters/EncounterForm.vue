<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { EncounterDetail } from '@/types/encounter';

const props = withDefaults(
    defineProps<{
        encounter: EncounterDetail;
        submitUrl: string;
        backUrl: string;
        signUrl: string;
        canSign?: boolean;
    }>(),
    {
        canSign: false,
    },
);

const sections = [
    {
        key: 'reason_for_visit',
        label: 'Motif de consultation',
        placeholder: 'Plainte principale et motif de la consultation…',
    },
    {
        key: 'clinical_examination',
        label: 'Examen clinique',
        placeholder: 'Constatations, paramètres vitaux et observations…',
    },
    {
        key: 'diagnosis_assessment',
        label: 'Diagnostic et évaluation',
        placeholder:
            'Diagnostic clinique, évaluation et diagnostics différentiels…',
    },
    {
        key: 'treatment_plan',
        label: 'Plan de traitement',
        placeholder: 'Prise en charge, prescriptions, orientations et suivi…',
    },
] as const;

const form = useForm({
    reason_for_visit: props.encounter.notes.reason_for_visit,
    clinical_examination: props.encounter.notes.clinical_examination,
    diagnosis_assessment: props.encounter.notes.diagnosis_assessment,
    treatment_plan: props.encounter.notes.treatment_plan,
    lock_version: props.encounter.lock_version,
});

const isDirty = ref(false);
const lastSaved = ref<string | null>(null);
const showSignDialog = ref(false);

// Keep the optimistic-lock version in sync when the server returns a fresh encounter.
watch(
    () => props.encounter.lock_version,
    (value) => {
        form.lock_version = value;
    },
);

const markDirty = () => {
    isDirty.value = true;
};

const save = () => {
    form.put(props.submitUrl, {
        preserveScroll: true,
        onSuccess: () => {
            isDirty.value = false;
            lastSaved.value = new Date().toLocaleTimeString('fr-FR');
        },
    });
};

const sign = () => {
    showSignDialog.value = false;
    router.post(props.signUrl, {}, { preserveScroll: true });
};

let autosaveTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    autosaveTimer = setInterval(() => {
        if (isDirty.value && !form.processing) {
            save();
        }
    }, 30000);
});

onBeforeUnmount(() => {
    if (autosaveTimer) {
        clearInterval(autosaveTimer);
    }
});
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-6 md:grid-cols-2">
            <div
                v-for="section in sections"
                :key="section.key"
                class="grid gap-2"
            >
                <Label :for="section.key">{{ section.label }}</Label>
                <Textarea
                    :id="section.key"
                    v-model="form[section.key]"
                    :placeholder="section.placeholder"
                    class="min-h-[200px]"
                    @input="markDirty"
                />
                <InputError :message="form.errors[section.key]" />
            </div>
        </div>

        <InputError :message="form.errors.lock_version" />

        <div
            class="flex flex-wrap items-center gap-3 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border"
        >
            <Button type="button" :disabled="form.processing" @click="save">
                {{
                    form.processing
                        ? 'Enregistrement…'
                        : 'Enregistrer les notes'
                }}
            </Button>

            <Dialog
                v-if="canSign && encounter.status !== 'signed'"
                v-model:open="showSignDialog"
            >
                <DialogTrigger as-child>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="isDirty"
                        >Signer la consultation</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Signer cette consultation ?</DialogTitle>
                        <DialogDescription>
                            Une fois signée, cette consultation est verrouillée
                            et ne peut plus être modifiée. Toute correction doit
                            faire l’objet d’un avenant signé.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Annuler</Button>
                        </DialogClose>
                        <Button @click="sign">Signer et verrouiller</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Button variant="outline" as-child>
                <Link :href="backUrl">Annuler</Link>
            </Button>

            <p
                v-if="isDirty"
                class="ml-auto text-sm text-amber-600 dark:text-amber-500"
            >
                Modifications non enregistrées
            </p>
            <p
                v-else-if="lastSaved"
                class="ml-auto text-sm text-muted-foreground"
            >
                Enregistré à {{ lastSaved }}
            </p>
        </div>

        <p
            v-if="canSign && encounter.status !== 'signed' && isDirty"
            class="text-sm text-muted-foreground"
        >
            Enregistrez vos notes avant de signer.
        </p>
    </div>
</template>
