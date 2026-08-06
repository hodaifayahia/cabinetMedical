<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    FileText,
    ImageUp,
    LockKeyhole,
    Save,
    Stethoscope,
    Trash2,
    Wifi,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ConfigurationCapability } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

type ClinicIdentity = {
    doctor_name: string;
    specialty: string;
    professional_identifier: string;
    clinic_name: string;
    phone: string;
    email: string;
    city: string;
    address: string;
    footer_line: string;
    logo_url: string | null;
};

const props = defineProps<{
    identity: ClinicIdentity;
    specialtySuggestions: string[];
    customBrandingCapability: ConfigurationCapability;
    permissions: {
        can_correct_specialty: boolean;
        sensitive_actions_confirmed: boolean;
    };
}>();

const logoInput = ref<HTMLInputElement | null>(null);
const logoPreview = ref<string | null>(props.identity.logo_url);

const form = useForm({
    doctor_name: props.identity.doctor_name,
    professional_identifier: props.identity.professional_identifier,
    clinic_name: props.identity.clinic_name,
    phone: props.identity.phone,
    email: props.identity.email,
    city: props.identity.city,
    address: props.identity.address,
    footer_line: props.identity.footer_line,
    logo: null as File | null,
});

const specialtyCorrectionForm = useForm({
    specialty: props.identity.specialty,
    confirmation: false,
});

const footerPreview = computed(() => {
    const address = [form.address.trim(), form.city.trim()]
        .filter(Boolean)
        .join(', ');

    return [
        form.phone.trim() ? `Tél. ${form.phone.trim()}` : '',
        form.email.trim() ? `E-mail ${form.email.trim()}` : '',
        address ? `Adresse : ${address}` : '',
        form.footer_line.trim(),
    ]
        .filter(Boolean)
        .join(' | ');
});

const selectLogo = () => {
    if (!props.customBrandingCapability.available) {
        return;
    }

    logoInput.value?.click();
};

const onLogoSelected = (event: Event) => {
    if (!props.customBrandingCapability.available) {
        return;
    }

    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    form.logo = file;

    if (file !== null) {
        logoPreview.value = URL.createObjectURL(file);
    }
};

const submit = () => {
    form.post('/app/configuration/identity', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
        },
    });
};

const removeLogo = () => {
    if (!props.customBrandingCapability.available) {
        return;
    }

    if (!window.confirm('Supprimer le logo des documents imprimés ?')) {
        return;
    }

    router.delete('/app/configuration/identity/logo', {
        preserveScroll: true,
        onSuccess: () => {
            logoPreview.value = null;
            form.logo = null;
        },
    });
};

const correctSpecialty = () => {
    specialtyCorrectionForm.patch('/app/configuration/identity/specialty', {
        preserveScroll: true,
        onSuccess: () => {
            specialtyCorrectionForm.confirmation = false;
        },
    });
};
</script>

<template>
    <Head title="Cabinet et documents" />

    <div class="med-page">
        <ConfigurationTabs />

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Cabinet & documents"
                description="Configurez l’identité utilisée sur les ordonnances, bilans, courriers et documents cliniques."
            />
            <Button
                type="submit"
                form="clinic-identity-form"
                :disabled="form.processing"
            >
                <Save class="size-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>

        <form
            id="clinic-identity-form"
            class="space-y-6"
            @submit.prevent="submit"
        >
            <section class="med-panel p-6">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"
                    >
                        <FileText class="size-5" />
                    </span>
                    <div>
                        <h2
                            class="text-lg font-bold text-slate-900 dark:text-white"
                        >
                            En-tête et pied de page
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Ces informations sont appliquées aux documents pris
                            en charge. Les anciennes impressions sont migrées
                            progressivement.
                        </p>
                    </div>
                </div>

                <p
                    v-if="customBrandingCapability.reason"
                    class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                >
                    {{ customBrandingCapability.reason }}
                </p>

                <div class="mt-6 grid gap-6 lg:grid-cols-[180px_minmax(0,1fr)]">
                    <div>
                        <Label>Logo du cabinet</Label>
                        <button
                            type="button"
                            class="mt-2 flex aspect-square w-32 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-blue-300 bg-blue-50/60 transition hover:border-blue-500 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-blue-800 dark:bg-blue-950/20"
                            :disabled="!customBrandingCapability.available"
                            @click="selectLogo"
                        >
                            <img
                                v-if="logoPreview"
                                :src="logoPreview"
                                alt="Aperçu du logo du cabinet"
                                class="h-full w-full object-contain p-2"
                            />
                            <span
                                v-else
                                class="flex flex-col items-center gap-2 text-xs font-semibold text-blue-600"
                            >
                                <ImageUp class="size-7" />
                                Choisir un logo
                            </span>
                        </button>
                        <input
                            ref="logoInput"
                            class="sr-only"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            :disabled="!customBrandingCapability.available"
                            @change="onLogoSelected"
                        />
                        <div class="mt-2 flex flex-col gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="!customBrandingCapability.available"
                                @click="selectLogo"
                            >
                                <ImageUp class="size-4" /> Choisir
                            </Button>
                            <Button
                                v-if="logoPreview"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700"
                                :disabled="!customBrandingCapability.available"
                                @click="removeLogo"
                            >
                                <Trash2 class="size-4" /> Supprimer
                            </Button>
                        </div>
                        <InputError class="mt-2" :message="form.errors.logo" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="doctor-name">Nom du médecin</Label>
                            <Input
                                id="doctor-name"
                                v-model="form.doctor_name"
                            />
                            <InputError :message="form.errors.doctor_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="specialty">Spécialité</Label>
                            <div class="relative">
                                <Input
                                    id="specialty"
                                    :model-value="
                                        identity.specialty || 'Non configurée'
                                    "
                                    class="pr-10"
                                    disabled
                                />
                                <LockKeyhole
                                    class="absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="identifier"
                                >N° d’ordre / identifiant professionnel</Label
                            >
                            <Input
                                id="identifier"
                                v-model="form.professional_identifier"
                            />
                            <InputError
                                :message="form.errors.professional_identifier"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="clinic-name">Nom du cabinet</Label>
                            <Input
                                id="clinic-name"
                                v-model="form.clinic_name"
                                required
                            />
                            <InputError :message="form.errors.clinic_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="phone">Téléphone</Label>
                            <Input id="phone" v-model="form.phone" type="tel" />
                            <InputError :message="form.errors.phone" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="city">Ville</Label>
                            <Input id="city" v-model="form.city" />
                            <InputError :message="form.errors.city" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="address">Adresse complète</Label>
                            <Input id="address" v-model="form.address" />
                            <InputError :message="form.errors.address" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2 xl:col-span-3">
                            <Label for="footer-line"
                                >Ligne supplémentaire du pied de page</Label
                            >
                            <Input
                                id="footer-line"
                                v-model="form.footer_line"
                                placeholder="Bâtiment, étage, repère…"
                                :disabled="!customBrandingCapability.available"
                            />
                            <InputError :message="form.errors.footer_line" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="med-panel p-6">
                <h2 class="font-bold text-slate-900 dark:text-white">
                    Aperçu du pied de page
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Les coordonnées sont assemblées automatiquement à partir des
                    champs ci-dessus.
                </p>
                <div
                    class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
                >
                    {{
                        footerPreview ||
                        'Ajoutez des coordonnées pour prévisualiser le pied de page.'
                    }}
                </div>
            </section>

            <section
                class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-6 dark:border-emerald-900 dark:bg-emerald-950/25"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <span
                        class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white text-emerald-600 shadow-sm dark:bg-slate-900"
                    >
                        <Stethoscope class="size-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-bold text-slate-900 dark:text-white">
                            Spécialité médicale
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            La spécialité est verrouillée afin de préserver la
                            cohérence des dossiers et des modèles.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <strong
                            class="text-sm text-emerald-800 dark:text-emerald-300"
                        >
                            {{ identity.specialty || 'Non configurée' }}
                        </strong>
                        <span
                            class="rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold text-white uppercase"
                        >
                            Verrouillée
                        </span>
                    </div>
                </div>
            </section>
        </form>

        <section
            v-if="permissions.can_correct_specialty"
            class="rounded-2xl border border-amber-200 bg-amber-50/70 p-6 dark:border-amber-900 dark:bg-amber-950/25"
        >
            <div class="flex items-start gap-3">
                <LockKeyhole
                    class="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-300"
                />
                <div class="min-w-0 flex-1">
                    <h2 class="font-bold text-slate-900 dark:text-white">
                        Correction administrative de la spécialité
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Réservée au propriétaire pour corriger une erreur de
                        configuration. La nouvelle valeur sera appliquée aux
                        documents et consignée dans le journal d’audit.
                    </p>

                    <Link
                        v-if="!permissions.sensitive_actions_confirmed"
                        href="/app/configuration/identity/specialty/confirm"
                        class="mt-4 inline-flex h-10 items-center justify-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white transition hover:bg-amber-800"
                    >
                        Confirmer mon mot de passe
                    </Link>

                    <form
                        v-else
                        class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end"
                        @submit.prevent="correctSpecialty"
                    >
                        <div class="grid gap-2">
                            <Label for="specialty-correction"
                                >Spécialité corrigée</Label
                            >
                            <Input
                                id="specialty-correction"
                                v-model="specialtyCorrectionForm.specialty"
                                list="specialty-correction-suggestions"
                                required
                                autocomplete="organization-title"
                            />
                            <datalist id="specialty-correction-suggestions">
                                <option
                                    v-for="specialty in specialtySuggestions"
                                    :key="specialty"
                                    :value="specialty"
                                />
                            </datalist>
                            <InputError
                                :message="
                                    specialtyCorrectionForm.errors.specialty
                                "
                            />
                            <label
                                class="mt-1 flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300"
                            >
                                <input
                                    v-model="
                                        specialtyCorrectionForm.confirmation
                                    "
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded border-slate-300"
                                    required
                                />
                                <span>
                                    Je confirme qu’il s’agit d’une correction
                                    administrative volontaire.
                                </span>
                            </label>
                            <InputError
                                :message="
                                    specialtyCorrectionForm.errors.confirmation
                                "
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            class="border-amber-400 text-amber-800 hover:bg-amber-100 dark:text-amber-200"
                            :disabled="
                                specialtyCorrectionForm.processing ||
                                !specialtyCorrectionForm.confirmation
                            "
                        >
                            <Save class="size-4" />
                            Corriger la spécialité
                        </Button>
                    </form>
                </div>
            </div>
        </section>

        <Link
            href="/app/configuration/connectivity-backup"
            class="flex flex-col gap-4 rounded-2xl border border-blue-200 bg-blue-50/70 p-6 transition hover:border-blue-400 hover:bg-blue-50 sm:flex-row sm:items-center dark:border-blue-900 dark:bg-blue-950/25"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white text-blue-600 shadow-sm dark:bg-slate-900"
            >
                <Wifi class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
                <strong class="block text-slate-900 dark:text-white">
                    QR, connexion et sauvegardes
                </strong>
                <span class="mt-1 block text-sm text-muted-foreground">
                    Configurez les transferts depuis un téléphone, le réseau
                    local, Google Drive et les politiques de sauvegarde.
                </span>
            </span>
            <span
                class="text-sm font-semibold text-blue-700 dark:text-blue-300"
            >
                Ouvrir →
            </span>
        </Link>
    </div>
</template>
