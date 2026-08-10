<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    Banknote,
    CalendarDays,
    CircleDollarSign,
    Download,
    FileText,
    HeartPulse,
    Pill,
    Stethoscope,
    User,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Consultations', href: '/app/consultations' }],
    },
});

type PrescriptionItem = {
    medication?: string;
    dosage?: string;
    duration?: string;
    instructions?: string;
};

type Prescription = {
    id: number;
    prescribed_at: string | null;
    prescribed_at_label: string | null;
    notes: string | null;
    items: PrescriptionItem[];
    document_id: number | null;
    document_title: string | null;
    download_url: string | null;
};

type ConsultationDocument = {
    id: number;
    category: string | null;
    title: string | null;
    original_filename: string | null;
    mime_type: string | null;
    file_size: number | null;
    created_at: string | null;
    is_uploaded: boolean;
    download_url: string | null;
};

const props = defineProps<{
    currency: string;
    canEdit: boolean;
    patient: {
        id: number;
        patient_number: string | null;
        full_name: string | null;
    };
    consultation: {
        id: number;
        status: string | null;
        consulted_at: string | null;
        consulted_at_label: string | null;
        completed_at: string | null;
        provider_name: string | null;
        motif: string | null;
        examens: string | null;
        diagnostic: string | null;
        traitement: string | null;
        notes: string | null;
        weight_kg: number | null;
        height_cm: number | null;
        temperature_c: number | null;
        blood_pressure: string | null;
        payment_amount: number | null;
        payment_method: string | null;
        payment_service: string | null;
        is_paid: boolean;
    };
    prescriptions: Prescription[];
    documents: ConsultationDocument[];
}>();

const statusLabels: Record<string, string> = {
    in_progress: 'En cours',
    completed: 'Terminée',
    draft: 'Brouillon',
};

const statusLabel = computed(() =>
    props.consultation.status
        ? (statusLabels[props.consultation.status] ??
          props.consultation.status.replace(/_/g, ' '))
        : '—',
);

const clinicalFields = computed(() =>
    [
        { label: 'Motif', value: props.consultation.motif },
        { label: 'Examens', value: props.consultation.examens },
        { label: 'Diagnostic', value: props.consultation.diagnostic },
        { label: 'Traitement', value: props.consultation.traitement },
        { label: 'Notes', value: props.consultation.notes },
    ].filter((field) => field.value !== null && field.value !== ''),
);

const measurements = computed(() =>
    [
        {
            label: 'Poids',
            value:
                props.consultation.weight_kg !== null
                    ? `${props.consultation.weight_kg} kg`
                    : null,
        },
        {
            label: 'Taille',
            value:
                props.consultation.height_cm !== null
                    ? `${props.consultation.height_cm} cm`
                    : null,
        },
        {
            label: 'Température',
            value:
                props.consultation.temperature_c !== null
                    ? `${props.consultation.temperature_c} °C`
                    : null,
        },
        {
            label: 'Tension',
            value: props.consultation.blood_pressure,
        },
    ].filter(
        (measurement) => measurement.value !== null && measurement.value !== '',
    ),
);

const generatedDocuments = computed(() =>
    props.documents.filter((document) => !document.is_uploaded),
);

const uploadedDocuments = computed(() =>
    props.documents.filter((document) => document.is_uploaded),
);

const formatAmount = (amount: number | null): string =>
    amount === null
        ? '—'
        : `${amount.toLocaleString('fr-FR', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })} ${props.currency}`;

const formatDate = (value: string | null): string =>
    value
        ? new Date(value).toLocaleDateString('fr-FR', {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
          })
        : '—';

const categoryLabels: Record<string, string> = {
    ordonnance: 'Ordonnance',
    certificat: 'Certificat',
    courrier: 'Courrier',
    bilan: 'Bilan',
};

const categoryLabel = (category: string | null): string =>
    category ? (categoryLabels[category] ?? category) : 'Document';
</script>

<template>
    <Head :title="`Consultation du ${consultation.consulted_at_label ?? ''}`" />

    <div class="med-page">
        <section class="med-panel overflow-hidden">
            <div
                class="border-b border-sidebar-border/70 p-6 dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div class="space-y-2">
                        <Heading
                            title="Détail de la consultation"
                            :description="`${patient.full_name ?? 'Patient'} · ${patient.patient_number ?? 'Dossier patient'}`"
                        />
                        <div
                            class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <CalendarDays class="size-4" />
                                {{ consultation.consulted_at_label ?? '—' }}
                            </span>
                            <span
                                v-if="consultation.provider_name"
                                class="inline-flex items-center gap-1.5"
                            >
                                <User class="size-4" />
                                {{ consultation.provider_name }}
                            </span>
                            <span
                                class="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                            >
                                {{ statusLabel }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="`/app/patients/${patient.id}/consultation-history`"
                            >
                                <ArrowLeft class="size-4" />
                                Historique
                            </Link>
                        </Button>
                        <Button
                            v-if="
                                canEdit && consultation.status === 'in_progress'
                            "
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="`/app/consultations/${consultation.id}`"
                            >
                                <Stethoscope class="size-4" />
                                Ouvrir la consultation
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 p-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <!-- Filled clinical fields -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Activity class="size-4 text-brand" />
                                Contenu clinique
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <p
                                v-if="clinicalFields.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Aucune donnée clinique n'a été renseignée.
                            </p>
                            <div
                                v-for="field in clinicalFields"
                                :key="field.label"
                                class="space-y-1"
                            >
                                <p
                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ field.label }}
                                </p>
                                <p
                                    class="text-sm whitespace-pre-line text-foreground"
                                >
                                    {{ field.value }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Prescriptions / ordonnances -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Pill class="size-4 text-brand" />
                                Ordonnances
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <p
                                v-if="prescriptions.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Aucune ordonnance générée pour cette
                                consultation.
                            </p>
                            <div
                                v-for="prescription in prescriptions"
                                :key="prescription.id"
                                class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                            >
                                <div
                                    class="mb-3 flex flex-wrap items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-sm font-medium text-foreground"
                                    >
                                        Prescrite le
                                        {{
                                            prescription.prescribed_at_label ??
                                            '—'
                                        }}
                                    </span>
                                    <Button
                                        v-if="prescription.download_url"
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <a
                                            :href="prescription.download_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Download class="size-4" />
                                            Télécharger
                                        </a>
                                    </Button>
                                </div>
                                <ul
                                    v-if="prescription.items.length"
                                    class="space-y-2"
                                >
                                    <li
                                        v-for="(
                                            item, index
                                        ) in prescription.items"
                                        :key="index"
                                        class="rounded-lg bg-muted/40 px-3 py-2 text-sm"
                                    >
                                        <span
                                            class="font-medium text-foreground"
                                            >{{ item.medication }}</span
                                        >
                                        <span
                                            v-if="item.dosage"
                                            class="text-muted-foreground"
                                        >
                                            — {{ item.dosage }}</span
                                        >
                                        <span
                                            v-if="item.duration"
                                            class="text-muted-foreground"
                                        >
                                            · {{ item.duration }}</span
                                        >
                                        <p
                                            v-if="item.instructions"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ item.instructions }}
                                        </p>
                                    </li>
                                </ul>
                                <p
                                    v-if="prescription.notes"
                                    class="mt-2 text-xs text-muted-foreground"
                                >
                                    {{ prescription.notes }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Generated documents (certificats, courriers, bilans) -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <FileText class="size-4 text-brand" />
                                Documents générés
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <p
                                v-if="
                                    generatedDocuments.length === 0 &&
                                    uploadedDocuments.length === 0
                                "
                                class="text-sm text-muted-foreground"
                            >
                                Aucun document généré pour cette consultation.
                            </p>
                            <div
                                v-for="document in generatedDocuments"
                                :key="document.id"
                                class="flex items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-medium text-foreground"
                                    >
                                        {{ document.title ?? 'Document' }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ categoryLabel(document.category) }}
                                        · {{ formatDate(document.created_at) }}
                                    </p>
                                </div>
                                <Button
                                    v-if="document.download_url"
                                    variant="outline"
                                    size="sm"
                                    as-child
                                >
                                    <a
                                        :href="document.download_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Download class="size-4" />
                                        Ouvrir
                                    </a>
                                </Button>
                            </div>

                            <template v-if="uploadedDocuments.length">
                                <p
                                    class="pt-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Fichiers importés
                                </p>
                                <div
                                    v-for="document in uploadedDocuments"
                                    :key="document.id"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-sm font-medium text-foreground"
                                        >
                                            {{
                                                document.title ??
                                                document.original_filename ??
                                                'Fichier'
                                            }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDate(document.created_at)
                                            }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="document.download_url"
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <a
                                            :href="document.download_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Download class="size-4" />
                                            Ouvrir
                                        </a>
                                    </Button>
                                </div>
                            </template>
                        </CardContent>
                    </Card>
                </div>

                <div class="space-y-6">
                    <!-- Measurements -->
                    <Card v-if="measurements.length">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <HeartPulse class="size-4 text-brand" />
                                Constantes
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid grid-cols-2 gap-3">
                                <div
                                    v-for="measurement in measurements"
                                    :key="measurement.label"
                                    class="rounded-lg bg-muted/40 px-3 py-2"
                                >
                                    <dt class="text-xs text-muted-foreground">
                                        {{ measurement.label }}
                                    </dt>
                                    <dd
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ measurement.value }}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <!-- Price / payment breakdown -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <CircleDollarSign class="size-4 text-brand" />
                                Règlement
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-muted-foreground"
                                    >Montant</span
                                >
                                <span
                                    class="text-lg font-semibold text-foreground"
                                >
                                    {{
                                        formatAmount(
                                            consultation.payment_amount,
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="consultation.payment_service"
                                class="flex items-center justify-between"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Prestation</span
                                >
                                <span class="text-sm text-foreground">{{
                                    consultation.payment_service
                                }}</span>
                            </div>
                            <div
                                v-if="consultation.payment_method"
                                class="flex items-center justify-between"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Banknote class="size-4" />
                                    Mode
                                </span>
                                <span class="text-sm text-foreground">{{
                                    consultation.payment_method
                                }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-muted-foreground"
                                    >Statut</span
                                >
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        consultation.is_paid
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                                    "
                                >
                                    {{
                                        consultation.is_paid ? 'Payé' : 'Impayé'
                                    }}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </section>
    </div>
</template>
