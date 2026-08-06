<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, FileType2, Mail, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CourrierDocumentEditor from '@/components/consultations/CourrierDocumentEditor.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type {
    ClinicalDocument,
    ClinicalDocumentTemplate,
    DocumentBranding,
} from '@/types/clinicalDocuments';

type MainTab = 'courriers' | 'certificats' | 'precedents';

const props = defineProps<{
    consultationId: number;
    templates: ClinicalDocumentTemplate[];
    documents: ClinicalDocument[];
    patient: {
        full_name: string;
        date_of_birth: string | null;
    };
    consultation: {
        motif: string | null;
        examens: string | null;
        diagnostic: string | null;
        traitement: string | null;
        notes: string | null;
    };
    cabinet: DocumentBranding;
    canEdit: boolean;
}>();

const mainTab = ref<MainTab>('courriers');
const search = ref('');
const selectedTemplateId = ref<string | null>(null);
const content = ref('');
const paperSize = ref<'A4' | 'A5'>('A4');
const documentDate = ref(new Date().toISOString().slice(0, 10));
const showDate = ref(true);
const titleBox = ref(true);
const notes = ref('');

const templateId = (template: ClinicalDocumentTemplate): string =>
    template.source + ':' + template.key;

const patientAge = computed(() => {
    if (!props.patient.date_of_birth) {
        return null;
    }

    const birth = new Date(String(props.patient.date_of_birth) + 'T00:00:00');
    const age = Math.floor(
        (Date.now() - birth.getTime()) / (365.25 * 24 * 3600 * 1000),
    );

    return age >= 0 ? age : null;
});

const displayDate = (date: string | null): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? day + '/' + month + '/' + year : date;
};

const escapeHtml = (value: string | null | undefined): string =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

const renderTemplate = (template: ClinicalDocumentTemplate): string => {
    const values: Record<string, string> = {
        'patient.full_name': props.patient.full_name,
        'patient.name': props.patient.full_name,
        'patient.date_of_birth': props.patient.date_of_birth ?? '',
        'patient.birth_date': props.patient.date_of_birth ?? '',
        'patient.age':
            patientAge.value === null ? '' : String(patientAge.value),
        'doctor.name': props.cabinet.doctor_name ?? '',
        doctor_name: props.cabinet.doctor_name ?? '',
        'doctor.specialty': props.cabinet.specialty ?? '',
        specialty: props.cabinet.specialty ?? '',
        'document.date': displayDate(documentDate.value),
        date: displayDate(documentDate.value),
        motif: props.consultation.motif ?? '',
        'consultation.motif': props.consultation.motif ?? '',
        examens: props.consultation.examens ?? '',
        'consultation.examens': props.consultation.examens ?? '',
        diagnostic: props.consultation.diagnostic ?? '',
        'consultation.diagnostic': props.consultation.diagnostic ?? '',
        traitement: props.consultation.traitement ?? '',
        'consultation.traitement': props.consultation.traitement ?? '',
        notes: props.consultation.notes ?? '',
        'consultation.notes': props.consultation.notes ?? '',
    };
    const source =
        template.body?.trim() ||
        '<p><strong>' +
            escapeHtml(template.title) +
            '</strong></p><p>Contenu du document à compléter.</p>';

    return source
        .replace(/\{\{([^}]+)\}\}/g, (_match, key: string) =>
            escapeHtml(values[key.trim()] ?? ''),
        )
        .replace(/^##\s+(.+)$/gm, '<h3>$1</h3>')
        .replace(/\n{2,}/g, '<br><br>')
        .replace(/\n/g, '<br>');
};

const availableTemplates = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const certificates = mainTab.value === 'certificats';

    return props.templates.filter((template) => {
        if (
            template.category !== 'courrier' &&
            template.category !== 'general'
        ) {
            return false;
        }

        const isCertificate = template.group
            .toLocaleLowerCase()
            .includes('certificat');

        if (certificates !== isCertificate) {
            return false;
        }

        const searchableText = (
            template.title +
            ' ' +
            template.group +
            ' ' +
            (template.description ?? '')
        ).toLocaleLowerCase();

        return query === '' || searchableText.includes(query);
    });
});

const groupedTemplates = computed(() => {
    const groups = new Map<string, ClinicalDocumentTemplate[]>();

    for (const template of availableTemplates.value) {
        const items = groups.get(template.group) ?? [];
        items.push(template);
        groups.set(template.group, items);
    }

    return [...groups.entries()].map(([label, items]) => ({ label, items }));
});

const selectedTemplate = computed(
    () =>
        props.templates.find(
            (template) => templateId(template) === selectedTemplateId.value,
        ) ?? null,
);

const savedDocuments = computed(() =>
    props.documents.filter((document) => document.category === 'courrier'),
);

const selectTemplate = (template: ClinicalDocumentTemplate) => {
    selectedTemplateId.value = templateId(template);
    paperSize.value = template.default_paper_size;
    content.value = renderTemplate(template);
};

watch(
    () => props.templates,
    (templates) => {
        const current = templates.find(
            (template) => templateId(template) === selectedTemplateId.value,
        );

        if (!current) {
            const first = templates.find(
                (template) =>
                    template.category === 'courrier' ||
                    template.category === 'general',
            );

            if (first) {
                selectTemplate(first);
            }
        }
    },
    { immediate: true },
);

const newCourrier = () => {
    selectedTemplateId.value = null;
    content.value = '';
    notes.value = '';
    search.value = '';
    mainTab.value = 'courriers';
};

const saveCourrier = () => {
    if (!props.canEdit || !selectedTemplate.value) {
        return;
    }

    const finalContent = notes.value.trim()
        ? content.value + '<hr><p>' + escapeHtml(notes.value.trim()) + '</p>'
        : content.value;

    router.post(
        '/app/consultations/' + props.consultationId + '/documents',
        {
            category: 'courrier',
            title: selectedTemplate.value.title,
            content: finalContent,
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <div
        class="grid min-h-full gap-4 xl:grid-cols-[minmax(330px,0.78fr)_minmax(600px,1.22fr)]"
    >
        <section
            class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border"
        >
            <div
                class="border-b border-sidebar-border/70 bg-slate-50/70 p-4 dark:border-sidebar-border dark:bg-slate-950/30"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="flex items-center gap-2 text-sm font-bold tracking-wide text-foreground uppercase"
                        >
                            <Mail class="size-4 text-cyan-700" />
                            Documents &amp; certificats
                        </p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">
                            Choisissez un modèle, puis modifiez le document
                            directement à droite.
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-200"
                    >
                        {{ savedDocuments.length }} enregistré{{
                            savedDocuments.length === 1 ? '' : 's'
                        }}
                    </span>
                </div>
            </div>

            <div
                class="flex border-b border-sidebar-border/70 px-3 pt-2 dark:border-sidebar-border"
            >
                <button
                    v-for="tab in [
                        { key: 'courriers', label: 'Courriers' },
                        { key: 'certificats', label: 'Certificats' },
                        { key: 'precedents', label: 'Documents précédents' },
                    ]"
                    :key="tab.key"
                    type="button"
                    class="relative flex-1 px-2 py-2 text-xs font-medium transition"
                    :class="
                        mainTab === tab.key
                            ? 'text-foreground after:absolute after:right-2 after:bottom-0 after:left-2 after:h-0.5 after:bg-cyan-600'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="mainTab = tab.key as MainTab"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div
                v-if="mainTab !== 'precedents'"
                class="min-h-0 flex-1 overflow-y-auto p-3"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        class="h-10 pl-9"
                        placeholder="Rechercher un courrier ou certificat…"
                        aria-label="Rechercher un courrier ou certificat"
                    />
                </div>

                <div class="mt-3 space-y-3">
                    <section
                        v-for="group in groupedTemplates"
                        :key="group.label"
                    >
                        <p
                            class="px-2 py-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ group.label }}
                        </p>
                        <button
                            v-for="template in group.items"
                            :key="templateId(template)"
                            type="button"
                            class="mb-1 flex w-full items-start gap-2 rounded-lg border px-3 py-3 text-left transition"
                            :class="
                                selectedTemplateId === templateId(template)
                                    ? 'border-primary bg-primary/5'
                                    : 'border-transparent hover:bg-muted'
                            "
                            @click="selectTemplate(template)"
                        >
                            <FileType2
                                class="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{
                                    template.title
                                }}</span>
                                <span
                                    v-if="template.description"
                                    class="mt-0.5 line-clamp-2 block text-[11px] text-muted-foreground"
                                >
                                    {{ template.description }}
                                </span>
                            </span>
                            <!-- <Badge
                                v-if="false"
                                variant="secondary"
                                class="px-1.5 py-0 text-[9px]"
                            >
                                MODÈLE
                            </Badge> -->
                        </button>
                    </section>
                </div>
                <p
                    v-if="groupedTemplates.length === 0"
                    class="rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun document correspondant.
                </p>
            </div>

            <div v-else class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                <div
                    v-for="document in savedDocuments"
                    :key="document.id"
                    class="flex items-center gap-3 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <FileText class="size-4 shrink-0 text-cyan-700" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">
                            {{ document.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ document.created_at ?? '—' }} ·
                            {{ document.paper_size ?? 'A4' }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="savedDocuments.length === 0"
                    class="rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun document précédent pour ce patient.
                </p>
            </div>

            <div
                class="border-t border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <Textarea
                    v-model="notes"
                    :disabled="!canEdit"
                    rows="3"
                    placeholder="Notes ou complément au document…"
                />
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <label class="grid gap-1 text-[11px] text-muted-foreground">
                        Date du document
                        <input
                            v-model="documentDate"
                            type="date"
                            class="h-9 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                            :disabled="!canEdit"
                        />
                    </label>
                    <label class="grid gap-1 text-[11px] text-muted-foreground">
                        Format
                        <select
                            v-model="paperSize"
                            class="h-9 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                            :disabled="!canEdit"
                        >
                            <option value="A4">A4</option>
                            <option value="A5">A5</option>
                        </select>
                    </label>
                </div>
                <label
                    class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <input
                        v-model="showDate"
                        type="checkbox"
                        class="size-3.5 accent-primary"
                        :disabled="!canEdit"
                    />
                    Afficher la date sur le document
                </label>
            </div>
        </section>

        <CourrierDocumentEditor
            :template="selectedTemplate"
            :paper-size="paperSize"
            :content="content"
            :patient-name="patient.full_name"
            :patient-age="patientAge"
            :prescribed-at="documentDate"
            :doctor-name="cabinet.doctor_name"
            :specialty="cabinet.specialty"
            :order-number="cabinet.order_number"
            :clinic-name="cabinet.clinic_name"
            :phone="cabinet.phone"
            :email="cabinet.email"
            :clinic-address="cabinet.address"
            :city="cabinet.city"
            :footer="cabinet.footer"
            :logo-url="cabinet.logo_url"
            :title-box="titleBox"
            :show-date="showDate"
            :can-edit="canEdit"
            @save="saveCourrier"
            @new-courrier="newCourrier"
            @toggle-title-box="titleBox = !titleBox"
            @update-content="content = $event"
        />
    </div>
</template>
