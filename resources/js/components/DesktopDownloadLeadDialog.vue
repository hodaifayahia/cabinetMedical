<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    Download,
    LockKeyhole,
    MonitorDown,
} from '@lucide/vue';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    open: boolean;
    available: boolean;
    action: string;
    label: string;
    reason: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const form = useForm({
    name: '',
    email: '',
    phone: '',
    cabinet_name: '',
    specialization: '',
    website: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            form.clearErrors();
        }
    },
);

function submit(): void {
    if (!props.available || form.processing) {
        return;
    }

    form.post(props.action, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogScrollContent
            dir="ltr"
            lang="fr"
            class="flex max-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col gap-0 overflow-hidden p-0 sm:max-h-[calc(100dvh-2rem)]"
            close-class="text-white/85 hover:bg-white/15 hover:text-white focus-visible:ring-white/70 focus-visible:ring-offset-brand-deep"
            data-testid="desktop-download-lead-dialog"
        >
            <div
                class="shrink-0 rounded-t-2xl bg-brand-deep px-5 py-5 text-white sm:px-7 sm:py-6"
            >
                <div class="flex min-w-0 items-start gap-4 pr-8">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25"
                    >
                        <MonitorDown class="size-6" aria-hidden="true" />
                    </span>
                    <DialogHeader class="min-w-0 flex-1 space-y-1.5 text-left">
                        <DialogTitle
                            class="text-xl leading-tight font-black text-white sm:text-2xl"
                        >
                            Télécharger Drclick Desktop
                        </DialogTitle>
                        <DialogDescription
                            class="text-sm leading-5 text-brand-soft/85 sm:text-base sm:leading-6"
                        >
                            Quelques informations professionnelles suffisent
                            pour lancer le téléchargement sécurisé.
                        </DialogDescription>
                    </DialogHeader>
                </div>
            </div>

            <form
                v-if="available"
                class="flex min-h-0 flex-1 flex-col"
                data-testid="desktop-download-lead-form"
                @submit.prevent="submit"
            >
                <div
                    class="min-h-0 flex-1 space-y-5 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 sm:px-7 sm:py-6"
                    data-testid="desktop-download-scroll-region"
                >
                    <div
                        class="grid gap-2 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 text-sm text-emerald-950 sm:grid-cols-3 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
                        aria-label="Informations sur l’installation"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <CheckCircle2
                                class="size-4 shrink-0 text-emerald-600"
                                aria-hidden="true"
                            />
                            Windows 10/11
                        </span>
                        <span class="flex min-w-0 items-center gap-2">
                            <CheckCircle2
                                class="size-4 shrink-0 text-emerald-600"
                                aria-hidden="true"
                            />
                            Installation guidée
                        </span>
                        <span class="flex min-w-0 items-center gap-2">
                            <CheckCircle2
                                class="size-4 shrink-0 text-emerald-600"
                                aria-hidden="true"
                            />
                            Connexion sécurisée
                        </span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="download-name" class="font-semibold">
                                Nom complet
                            </Label>
                            <Input
                                id="download-name"
                                v-model="form.name"
                                type="text"
                                autocomplete="name"
                                placeholder="Dr Nadia Benali"
                                required
                                :aria-invalid="Boolean(form.errors.name)"
                                aria-describedby="download-name-error"
                                class="h-11"
                            />
                            <InputError
                                id="download-name-error"
                                :message="form.errors.name"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="download-phone" class="font-semibold">
                                Téléphone
                            </Label>
                            <Input
                                id="download-phone"
                                v-model="form.phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                placeholder="0555 12 34 56"
                                required
                                :aria-invalid="Boolean(form.errors.phone)"
                                aria-describedby="download-phone-error"
                                class="h-11"
                            />
                            <InputError
                                id="download-phone-error"
                                :message="form.errors.phone"
                            />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="download-email" class="font-semibold">
                                Adresse e-mail
                            </Label>
                            <Input
                                id="download-email"
                                v-model="form.email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                placeholder="docteur@cabinet.dz"
                                required
                                :aria-invalid="Boolean(form.errors.email)"
                                aria-describedby="download-email-error"
                                class="h-11"
                            />
                            <InputError
                                id="download-email-error"
                                :message="form.errors.email"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="download-cabinet" class="font-semibold">
                                Nom du cabinet
                            </Label>
                            <div class="relative">
                                <Building2
                                    class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"
                                    aria-hidden="true"
                                />
                                <Input
                                    id="download-cabinet"
                                    v-model="form.cabinet_name"
                                    type="text"
                                    autocomplete="organization"
                                    placeholder="Cabinet El Amal"
                                    required
                                    :aria-invalid="
                                        Boolean(form.errors.cabinet_name)
                                    "
                                    aria-describedby="download-cabinet-error"
                                    class="h-11 ps-10"
                                />
                            </div>
                            <InputError
                                id="download-cabinet-error"
                                :message="form.errors.cabinet_name"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                for="download-specialization"
                                class="font-semibold"
                            >
                                Spécialité
                            </Label>
                            <Input
                                id="download-specialization"
                                v-model="form.specialization"
                                type="text"
                                autocomplete="organization-title"
                                placeholder="Médecine générale"
                                required
                                :aria-invalid="
                                    Boolean(form.errors.specialization)
                                "
                                aria-describedby="download-specialization-error"
                                class="h-11"
                            />
                            <InputError
                                id="download-specialization-error"
                                :message="form.errors.specialization"
                            />
                        </div>
                    </div>

                    <div class="sr-only" aria-hidden="true">
                        <Label for="download-website">Site web</Label>
                        <Input
                            id="download-website"
                            v-model="form.website"
                            type="text"
                            autocomplete="off"
                            tabindex="-1"
                        />
                    </div>

                    <div
                        class="flex gap-3 rounded-xl bg-slate-50 p-4 text-xs leading-5 text-slate-600 dark:bg-slate-900 dark:text-slate-300"
                    >
                        <LockKeyhole
                            class="mt-0.5 size-4 shrink-0 text-brand"
                            aria-hidden="true"
                        />
                        <p class="min-w-0">
                            Ces coordonnées servent à identifier votre demande
                            et à vous accompagner. Le lien généré est personnel,
                            temporaire et valable uniquement dans cette session.
                        </p>
                    </div>
                </div>

                <div
                    class="shrink-0 border-t border-border bg-background px-5 py-4 sm:px-7"
                >
                    <Button
                        type="submit"
                        size="lg"
                        class="h-12 w-full bg-brand text-base text-white shadow-lg shadow-brand-deep/15 hover:bg-brand-deep"
                        :disabled="form.processing"
                        data-testid="desktop-download-submit"
                    >
                        <Spinner v-if="form.processing" />
                        <Download v-else class="size-5" aria-hidden="true" />
                        {{ form.processing ? 'Préparation…' : label }}
                    </Button>
                </div>
            </form>

            <div
                v-else
                class="min-h-0 overflow-y-auto px-6 py-10 text-center sm:px-8"
            >
                <span
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700"
                >
                    <Download class="size-6" aria-hidden="true" />
                </span>
                <h3
                    class="mt-5 text-lg font-bold text-slate-950 dark:text-white"
                >
                    Téléchargement momentanément indisponible
                </h3>
                <p
                    class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300"
                >
                    {{
                        reason ??
                        'Le programme d’installation sera disponible prochainement.'
                    }}
                </p>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
