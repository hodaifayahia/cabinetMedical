<script setup lang="ts">
import {
    Activity,
    BadgeCheck,
    Crosshair,
    FileUp,
    HeartPulse,
    MessageSquare,
    Ruler,
    ScanLine,
    Send,
    ShieldAlert,
    Sparkles,
    SquareDashedMousePointer,
    Trash2,
    TriangleAlert,
    Undo2,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AiActionButton from '@/components/ai/AiActionButton.vue';
import AiCreditsPill from '@/components/ai/AiCreditsPill.vue';
import AiNotice from '@/components/ai/AiNotice.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { AiFailure, EcgRecord } from '@/lib/ai';
import { aiCost, creditsLabel, formatAiText, runAi } from '@/lib/ai';
import type { Region, StripMeasurement } from '@/lib/ecgDigitizer';
import { detectGrid, measureStrip, pxToMs } from '@/lib/ecgDigitizer';
import {
    deleteJson,
    getJson,
    isValidationError,
    postFormData,
    putJson,
} from '@/lib/http';

const props = defineProps<{
    consultationId: number;
    canEdit: boolean;
}>();

const emit = defineEmits<{ 'use-in-visit': [text: string] }>();

type Tool = 'select' | 'caliper' | 'calibrate' | null;

const ecgs = ref<EcgRecord[]>([]);
const selectedId = ref<number | null>(null);
const loading = ref(true);
const selected = computed(
    () => ecgs.value.find((ecg) => ecg.id === selectedId.value) ?? null,
);

const replace = (ecg: EcgRecord) => {
    const index = ecgs.value.findIndex((item) => item.id === ecg.id);

    if (index >= 0) {
        ecgs.value.splice(index, 1, ecg);
    } else {
        ecgs.value.unshift(ecg);
    }
};

onMounted(async () => {
    try {
        const result = await getJson<{ ecgs: EcgRecord[] }>(
            `/app/consultations/${props.consultationId}/ecgs`,
        );
        ecgs.value = result.ecgs;
        selectedId.value = result.ecgs[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
});

// --- Upload -------------------------------------------------------------------

const fileInput = ref<HTMLInputElement | null>(null);
const pendingFile = ref<File | null>(null);
const uploadTitle = ref('ECG');
const uploadDate = ref(new Date().toISOString().slice(0, 10));
const uploading = ref(false);
const uploadError = ref<string | null>(null);

const chooseFile = (event: Event) => {
    pendingFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    uploadError.value = null;
};

/** Phone photos are huge; the AI accepts 4 MB. Keep ~2400 px, JPEG. */
const shrink = async (file: File): Promise<Blob> => {
    if (file.size < 2.5 * 1024 * 1024) {
        return file;
    }

    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas
        .getContext('2d')
        ?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve) =>
        canvas.toBlob((blob) => resolve(blob ?? file), 'image/jpeg', 0.92),
    );
};

const upload = async () => {
    if (!pendingFile.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = null;

    try {
        const blob = await shrink(pendingFile.value);
        const data = new FormData();
        const name = pendingFile.value.name.replace(/\.[^.]+$/, '') + '.jpg';
        data.append(
            'file',
            blob,
            blob === pendingFile.value ? pendingFile.value.name : name,
        );
        data.append('title', uploadTitle.value || 'ECG');
        data.append('recorded_at', uploadDate.value);

        const result = await postFormData<{ ecg: EcgRecord }>(
            `/app/consultations/${props.consultationId}/ecgs`,
            data,
        );
        replace(result.ecg);
        selectedId.value = result.ecg.id;
        pendingFile.value = null;
        uploadTitle.value = 'ECG';

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    } catch (error) {
        uploadError.value = isValidationError(error)
            ? (Object.values(error.errors)[0]?.[0] ??
              'Fichier refusé : importez une image JPG ou PNG.')
            : 'Import impossible. Réessayez.';
    } finally {
        uploading.value = false;
    }
};

const removeEcg = async (ecg: EcgRecord) => {
    if (!window.confirm(`Supprimer « ${ecg.title} » ?`)) {
        return;
    }

    await deleteJson(`/app/ecgs/${ecg.id}`);
    ecgs.value = ecgs.value.filter((item) => item.id !== ecg.id);
    selectedId.value = ecgs.value[0]?.id ?? null;
};

// --- Viewer & measurement -----------------------------------------------------

const canvas = ref<HTMLCanvasElement | null>(null);
const image = ref<HTMLImageElement | null>(null);
const imageReady = ref(false);
const tool = ref<Tool>(null);
const region = ref<Region | null>(null);
const dragStart = ref<{ x: number; y: number } | null>(null);
const clicks = ref<{ x: number; y: number }[]>([]);
const paperSpeed = ref(25);
const manualPxPerMm = ref<number | null>(null);
const calibrationMm = ref(5);
const caliperLabel = ref('QT');
const strip = ref<StripMeasurement | null>(null);
const measuring = ref(false);
const measureMessage = ref<string | null>(null);

const pixels = (): ImageData | null => {
    const img = image.value;

    if (!img || !imageReady.value) {
        return null;
    }

    const off = document.createElement('canvas');
    off.width = img.naturalWidth;
    off.height = img.naturalHeight;
    const context = off.getContext('2d', { willReadFrequently: true });
    context?.drawImage(img, 0, 0);

    return context?.getImageData(0, 0, off.width, off.height) ?? null;
};

const scale = (): number => {
    const img = image.value;

    return img && canvas.value ? canvas.value.width / img.naturalWidth : 1;
};

const pxPerMm = computed(
    () =>
        manualPxPerMm.value ??
        strip.value?.pxPerMm ??
        selected.value?.measurements?.px_per_mm ??
        null,
);

const draw = () => {
    const img = image.value;
    const c = canvas.value;

    if (!img || !c || !imageReady.value) {
        return;
    }

    const width = c.parentElement?.clientWidth ?? img.naturalWidth;
    const s = Math.min(1, width / img.naturalWidth);
    c.width = Math.round(img.naturalWidth * s);
    c.height = Math.round(img.naturalHeight * s);
    const g = c.getContext('2d');

    if (!g) {
        return;
    }

    g.drawImage(img, 0, 0, c.width, c.height);

    if (region.value) {
        g.save();
        g.fillStyle = 'rgba(0, 96, 100, 0.08)';
        g.strokeStyle = '#006064';
        g.setLineDash([6, 4]);
        g.lineWidth = 2;
        const r = region.value;
        g.fillRect(r.x * s, r.y * s, r.w * s, r.h * s);
        g.strokeRect(r.x * s, r.y * s, r.w * s, r.h * s);
        g.restore();
    }

    const peaks = strip.value?.peaksX ?? [];
    const top = (region.value?.y ?? 0) * s;
    const bottom = region.value
        ? (region.value.y + region.value.h) * s
        : c.height;

    g.save();
    g.strokeStyle = 'rgba(37, 99, 235, 0.85)';
    g.fillStyle = 'rgba(37, 99, 235, 0.95)';
    g.font = '600 11px system-ui, sans-serif';
    g.lineWidth = 1.5;

    peaks.forEach((x, index) => {
        g.beginPath();
        g.moveTo(x * s, top);
        g.lineTo(x * s, bottom);
        g.stroke();

        const next = peaks[index + 1];
        const rr = strip.value?.rrMs[index];

        if (next !== undefined && rr !== undefined) {
            g.fillText(`${rr}`, ((x + next) / 2) * s - 10, top + 13);
        }
    });
    g.restore();

    const calipers = selected.value?.measurements?.calipers ?? [];

    if (calipers.length) {
        g.save();
        g.fillStyle = 'rgba(15, 23, 42, 0.85)';
        g.font = '600 12px system-ui, sans-serif';
        calipers.forEach((caliper, index) => {
            g.fillText(
                `${caliper.label} ${caliper.ms} ms`,
                8,
                c.height - 8 - index * 16,
            );
        });
        g.restore();
    }

    if (clicks.value.length) {
        g.save();
        g.strokeStyle = tool.value === 'calibrate' ? '#b45309' : '#be123c';
        g.fillStyle = g.strokeStyle;
        g.lineWidth = 2;
        clicks.value.forEach((point) => {
            g.beginPath();
            g.moveTo(point.x * s, point.y * s - 14);
            g.lineTo(point.x * s, point.y * s + 14);
            g.stroke();
        });
        g.restore();
    }
};

const loadImage = () => {
    imageReady.value = false;
    strip.value = null;
    region.value = null;
    clicks.value = [];
    manualPxPerMm.value = null;
    measureMessage.value = null;

    if (!selected.value) {
        return;
    }

    const img = new Image();
    img.onload = async () => {
        image.value = img;
        imageReady.value = true;
        await nextTick();
        draw();
    };
    img.src = selected.value.file_url;
};

watch(selectedId, loadImage);
watch(
    () => selected.value?.file_url,
    (url, old) => url !== old && loadImage(),
);
watch(
    [region, strip, clicks, () => selected.value?.measurements],
    () => draw(),
    {
        deep: true,
    },
);

const toImagePoint = (event: PointerEvent): { x: number; y: number } => {
    const c = canvas.value as HTMLCanvasElement;
    const rect = c.getBoundingClientRect();
    const s = scale();

    return {
        x: ((event.clientX - rect.left) * (c.width / rect.width)) / s,
        y: ((event.clientY - rect.top) * (c.height / rect.height)) / s,
    };
};

const onPointerDown = (event: PointerEvent) => {
    if (!props.canEdit || !tool.value) {
        return;
    }

    const point = toImagePoint(event);

    if (tool.value === 'select') {
        dragStart.value = point;
        region.value = { x: point.x, y: point.y, w: 1, h: 1 };

        return;
    }

    clicks.value = [...clicks.value, point].slice(-2);

    if (clicks.value.length === 2) {
        void finishTwoClickTool();
    }
};

const onPointerMove = (event: PointerEvent) => {
    if (tool.value !== 'select' || !dragStart.value) {
        return;
    }

    const point = toImagePoint(event);
    const start = dragStart.value;
    region.value = {
        x: Math.min(start.x, point.x),
        y: Math.min(start.y, point.y),
        w: Math.abs(point.x - start.x),
        h: Math.abs(point.y - start.y),
    };
};

const onPointerUp = () => {
    if (tool.value === 'select' && dragStart.value) {
        dragStart.value = null;

        if (region.value && (region.value.w < 20 || region.value.h < 10)) {
            region.value = null;
        } else {
            tool.value = null;
            void measure();
        }
    }
};

const finishTwoClickTool = async () => {
    const [a, b] = clicks.value;
    const dx = Math.abs(b.x - a.x);

    if (tool.value === 'calibrate') {
        if (dx > 2 && calibrationMm.value > 0) {
            manualPxPerMm.value = dx / calibrationMm.value;
            measureMessage.value = `Échelle calibrée : 1 mm = ${manualPxPerMm.value.toFixed(1)} px.`;
            await measure();
        }
    } else if (tool.value === 'caliper') {
        const data = pxPerMm.value ? null : pixels();
        const scaleValue =
            pxPerMm.value ?? (data ? detectGrid(data)?.period : null) ?? null;

        if (!scaleValue) {
            measureMessage.value =
                'Échelle inconnue : mesurez le rythme ou calibrez avant d’utiliser l’étrier.';
        } else {
            const ms = pxToMs(dx, scaleValue, paperSpeed.value);
            const calipers = [
                ...(selected.value?.measurements?.calipers ?? []).filter(
                    (caliper) =>
                        caliper.label !== caliperLabel.value ||
                        caliperLabel.value === 'AUTRE',
                ),
                { label: caliperLabel.value, ms },
            ];
            await saveMeasurements(calipers);
            measureMessage.value = `${caliperLabel.value} = ${ms} ms enregistré.`;
        }
    }

    window.setTimeout(() => {
        clicks.value = [];
    }, 600);
};

const measure = async () => {
    const data = pixels();

    if (!data) {
        return;
    }

    measuring.value = true;
    await nextTick();

    try {
        strip.value = measureStrip(data, region.value, {
            pxPerMm: manualPxPerMm.value,
            paperSpeed: paperSpeed.value,
        });
        measureMessage.value = strip.value.problem;

        if (strip.value.rrMs.length > 0) {
            await saveMeasurements(
                selected.value?.measurements?.calipers ?? [],
            );
        }
    } finally {
        measuring.value = false;
    }
};

const saveMeasurements = async (calipers: { label: string; ms: number }[]) => {
    if (!selected.value) {
        return;
    }

    const current = selected.value.measurements;
    const result = await putJson<{ ecg: EcgRecord }>(
        `/app/ecgs/${selected.value.id}/measurements`,
        {
            source: manualPxPerMm.value ? 'manual' : 'auto',
            paper_speed_mm_s: paperSpeed.value,
            px_per_mm: pxPerMm.value,
            duration_s: strip.value?.durationS ?? current?.duration_s ?? null,
            rr_ms: strip.value?.rrMs ?? current?.rr_ms ?? [],
            calipers,
        },
    );
    replace(result.ecg);
};

const clearCalipers = () => saveMeasurements([]);

// --- AI reading & chat ----------------------------------------------------------

const analyzing = ref(false);
const aiFailure = ref<AiFailure | null>(null);
const question = ref('');
const asking = ref(false);
const chatBox = ref<HTMLElement | null>(null);

const analyze = async () => {
    if (!selected.value) {
        return;
    }

    analyzing.value = true;
    aiFailure.value = null;

    try {
        const result = await runAi<{ ecg: EcgRecord }>(
            `/app/ai/ecgs/${selected.value.id}/analysis`,
        );
        replace(result.ecg);
    } catch (error) {
        aiFailure.value = error as AiFailure;
    } finally {
        analyzing.value = false;
    }
};

const quickQuestions = [
    'Est-ce une fibrillation atriale ?',
    'Y a-t-il des signes d’ischémie ou de nécrose ?',
    'Le QT est-il allongé ?',
    'Y a-t-il un trouble de conduction ?',
    'Quelle conduite à tenir en pratique ?',
];

const ask = async (text?: string) => {
    const message = (text ?? question.value).trim();

    if (!selected.value || !message || asking.value) {
        return;
    }

    asking.value = true;
    aiFailure.value = null;
    question.value = '';

    try {
        const result = await runAi<{ ecg: EcgRecord }>(
            `/app/ai/ecgs/${selected.value.id}/chat`,
            { message },
        );
        replace(result.ecg);
        await nextTick();
        chatBox.value?.scrollTo({
            top: chatBox.value.scrollHeight,
            behavior: 'smooth',
        });
    } catch (error) {
        aiFailure.value = error as AiFailure;
        question.value = message;
    } finally {
        asking.value = false;
    }
};

// --- Doctor conclusion ------------------------------------------------------------

const conclusion = ref('');
const saving = ref(false);
const conclusionError = ref<string | null>(null);

watch(
    selected,
    (ecg) => {
        conclusion.value = ecg?.doctor_conclusion ?? '';
        conclusionError.value = null;
    },
    { immediate: true },
);

const draftFromReading = () => {
    const ecg = selected.value;

    if (!ecg) {
        return;
    }

    const m = ecg.measurements;
    const a = ecg.analysis;
    const lines = [
        m?.heart_rate_bpm
            ? `Fréquence ${m.heart_rate_bpm}/min${m.rr_cv_percent !== null ? `, variabilité RR ${m.rr_cv_percent} %` : ''}.`
            : null,
        ...(m?.calipers ?? []).map((c) => `${c.label} ${c.ms} ms.`),
        m?.qtc_ms ? `QTc (Bazett) ${m.qtc_ms} ms.` : null,
        a?.rhythm ? `Rythme : ${a.rhythm}.` : null,
        a?.axis ? `Axe : ${a.axis}.` : null,
        a?.st_t ? `Repolarisation : ${a.st_t}.` : null,
        a?.primary_statement ? `Conclusion : ${a.primary_statement}` : null,
    ].filter(Boolean);

    conclusion.value = lines.join('\n');
};

const saveConclusion = async (validate: boolean) => {
    if (!selected.value) {
        return;
    }

    saving.value = true;
    conclusionError.value = null;

    try {
        const result = await putJson<{ ecg: EcgRecord }>(
            `/app/ecgs/${selected.value.id}/conclusion`,
            { doctor_conclusion: conclusion.value, validate },
        );
        replace(result.ecg);
    } catch (error) {
        conclusionError.value = isValidationError(error)
            ? (Object.values(error.errors)[0]?.[0] ?? 'Enregistrement refusé.')
            : 'Enregistrement impossible.';
    } finally {
        saving.value = false;
    }
};

const useInVisit = () => {
    const ecg = selected.value;

    if (ecg?.doctor_conclusion) {
        emit(
            'use-in-visit',
            `ECG du ${displayDate(ecg.recorded_at)} : ${ecg.doctor_conclusion}`,
        );
    }
};

// --- Display helpers ----------------------------------------------------------------

const displayDate = (date: string | null): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
};

const flagTone = (level: string): string =>
    level === 'critical'
        ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100'
        : level === 'warning'
          ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100'
          : 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100';

const urgencyTone = (urgency: string | null): string =>
    urgency === 'critique'
        ? 'bg-red-600 text-white'
        : urgency === 'anormal'
          ? 'bg-amber-500 text-white'
          : urgency === 'normal'
            ? 'bg-emerald-600 text-white'
            : 'bg-muted text-muted-foreground';

const toolButton = (value: Tool): string =>
    tool.value === value
        ? 'border-brand bg-brand-soft text-brand dark:bg-brand-deep/40 dark:text-brand-mint'
        : 'border-sidebar-border/70 bg-background text-foreground hover:bg-muted dark:border-sidebar-border';

const selectTool = (value: Tool) => {
    tool.value = tool.value === value ? null : value;
    clicks.value = [];
};

const readingRows = computed(() => {
    const a = selected.value?.analysis;

    if (!a) {
        return [];
    }

    return [
        { label: 'Rythme', value: a.rhythm },
        { label: 'Régularité (IA)', value: a.regularity },
        {
            label: 'Fréquence lue',
            value: a.rate_bpm ? `${a.rate_bpm}/min` : '',
        },
        { label: 'Ondes P', value: a.p_waves },
        { label: 'PR', value: a.pr_ms ? `${a.pr_ms} ms` : '' },
        { label: 'QRS', value: a.qrs_ms ? `${a.qrs_ms} ms` : '' },
        { label: 'QT', value: a.qt_ms ? `${a.qt_ms} ms` : '' },
        { label: 'Axe', value: a.axis },
        { label: 'ST-T', value: a.st_t },
        { label: 'Dérivations', value: a.leads_visible },
    ].filter((row) => row.value);
});
</script>

<template>
    <div
        class="grid min-h-0 gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]"
        data-testid="ecg-panel"
    >
        <!-- ECG list & upload -->
        <aside class="med-panel flex min-h-0 flex-col gap-3 p-3">
            <div class="flex items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <HeartPulse class="size-4 text-rose-600" /> ECG du patient
                </p>
                <AiCreditsPill />
            </div>

            <template v-if="canEdit">
                <input
                    ref="fileInput"
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp"
                    @change="chooseFile"
                />
                <button
                    v-if="!pendingFile"
                    type="button"
                    class="flex flex-col items-center gap-1 rounded-xl border border-dashed border-sidebar-border/80 bg-muted/20 p-4 text-center transition hover:border-brand hover:bg-brand-soft/30"
                    @click="fileInput?.click()"
                >
                    <FileUp class="size-5 text-brand" />
                    <span class="text-sm font-semibold">Importer un ECG</span>
                    <span class="text-[11px] text-muted-foreground">
                        Photo ou scan (JPG, PNG). Bien à plat, net et éclairé.
                    </span>
                </button>
                <div
                    v-else
                    class="space-y-2 rounded-xl border border-brand/40 bg-brand-soft/30 p-3"
                >
                    <p class="truncate text-xs font-medium">
                        {{ pendingFile.name }}
                    </p>
                    <Input
                        v-model="uploadTitle"
                        placeholder="Titre (ex. ECG de repos)"
                    />
                    <Input v-model="uploadDate" type="date" />
                    <p v-if="uploadError" class="text-xs text-destructive">
                        {{ uploadError }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="pendingFile = null"
                        >
                            Annuler
                        </Button>
                        <Button size="sm" :disabled="uploading" @click="upload">
                            <Spinner v-if="uploading" class="size-3.5" />
                            Importer
                        </Button>
                    </div>
                </div>
            </template>

            <div class="min-h-0 flex-1 space-y-1.5 overflow-y-auto">
                <p v-if="loading" class="p-3 text-xs text-muted-foreground">
                    Chargement…
                </p>
                <p
                    v-else-if="!ecgs.length"
                    class="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground"
                >
                    Aucun ECG pour ce patient.
                </p>
                <button
                    v-for="ecg in ecgs"
                    :key="ecg.id"
                    type="button"
                    class="flex w-full items-start gap-2 rounded-lg border p-2.5 text-left transition"
                    :class="
                        ecg.id === selectedId
                            ? 'border-brand bg-brand-soft/60 dark:bg-brand-deep/30'
                            : 'border-sidebar-border/70 hover:bg-muted/40 dark:border-sidebar-border'
                    "
                    @click="selectedId = ecg.id"
                >
                    <span
                        class="mt-1 size-2 shrink-0 rounded-full"
                        :class="
                            ecg.urgency === 'critique'
                                ? 'bg-red-500'
                                : ecg.urgency === 'anormal'
                                  ? 'bg-amber-500'
                                  : ecg.urgency === 'normal'
                                    ? 'bg-emerald-500'
                                    : 'bg-slate-300'
                        "
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{
                            ecg.title
                        }}</span>
                        <span class="block text-[11px] text-muted-foreground">
                            {{ displayDate(ecg.recorded_at) }} ·
                            {{
                                ecg.status === 'validated'
                                    ? 'Validé'
                                    : 'À valider'
                            }}
                        </span>
                    </span>
                    <BadgeCheck
                        v-if="ecg.status === 'validated'"
                        class="size-4 shrink-0 text-emerald-600"
                    />
                </button>
            </div>
        </aside>

        <div
            v-if="!selected"
            class="med-panel flex min-h-64 items-center justify-center p-8 text-center"
        >
            <div>
                <HeartPulse class="mx-auto size-8 text-muted-foreground" />
                <p class="mt-2 text-sm font-medium">
                    Importez un ECG pour commencer
                </p>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Le logiciel mesure la fréquence et la régularité sur le
                    tracé, l’IA propose une lecture, et vous validez la
                    conclusion.
                </p>
            </div>
        </div>

        <div v-else class="flex min-w-0 flex-col gap-4">
            <!-- Viewer -->
            <section class="med-panel space-y-3 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ selected.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ displayDate(selected.recorded_at) }}
                            <template v-if="selected.validated_by">
                                · validé par {{ selected.validated_by }}
                            </template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            v-if="selected.urgency"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="urgencyTone(selected.urgency)"
                        >
                            {{
                                selected.urgency === 'critique'
                                    ? 'À voir en urgence'
                                    : selected.urgency === 'anormal'
                                      ? 'Anormal'
                                      : 'Normal'
                            }}
                        </span>
                        <Button
                            v-if="canEdit"
                            size="icon-sm"
                            variant="ghost"
                            aria-label="Supprimer l’ECG"
                            @click="removeEcg(selected)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </div>

                <div
                    v-if="canEdit"
                    class="flex flex-wrap items-center gap-1.5 text-xs"
                >
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 font-medium transition"
                        :class="toolButton('select')"
                        title="Tracez un rectangle autour d’une seule dérivation longue (DII)"
                        @click="selectTool('select')"
                    >
                        <SquareDashedMousePointer class="size-3.5" /> Choisir la
                        dérivation
                    </button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8"
                        :disabled="measuring"
                        @click="measure"
                    >
                        <Spinner v-if="measuring" class="size-3.5" />
                        <Activity v-else class="size-3.5" />
                        Mesurer le rythme
                    </Button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 font-medium transition"
                        :class="toolButton('caliper')"
                        title="Cliquez au début puis à la fin de l’intervalle"
                        @click="selectTool('caliper')"
                    >
                        <Ruler class="size-3.5" /> Étrier
                    </button>
                    <select
                        v-if="tool === 'caliper'"
                        v-model="caliperLabel"
                        class="h-8 rounded-lg border border-input bg-background px-2"
                        aria-label="Intervalle mesuré"
                    >
                        <option value="PR">PR</option>
                        <option value="QRS">QRS</option>
                        <option value="QT">QT</option>
                        <option value="RR">RR</option>
                        <option value="AUTRE">Autre</option>
                    </select>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 font-medium transition"
                        :class="toolButton('calibrate')"
                        title="Cliquez sur deux lignes du quadrillage séparées d’une distance connue"
                        @click="selectTool('calibrate')"
                    >
                        <Crosshair class="size-3.5" /> Calibrer
                    </button>
                    <label
                        v-if="tool === 'calibrate'"
                        class="inline-flex items-center gap-1"
                    >
                        <input
                            v-model.number="calibrationMm"
                            type="number"
                            min="1"
                            max="250"
                            class="h-8 w-16 rounded-lg border border-input bg-background px-2"
                        />
                        mm
                    </label>
                    <select
                        v-model.number="paperSpeed"
                        class="h-8 rounded-lg border border-input bg-background px-2"
                        aria-label="Vitesse du papier"
                    >
                        <option :value="25">25 mm/s</option>
                        <option :value="50">50 mm/s</option>
                    </select>
                    <Button
                        v-if="selected.measurements?.calipers.length"
                        size="sm"
                        variant="ghost"
                        class="h-8"
                        @click="clearCalipers"
                    >
                        <Undo2 class="size-3.5" /> Effacer les étriers
                    </Button>
                </div>

                <p
                    v-if="tool"
                    class="rounded-lg bg-brand-soft/50 px-3 py-2 text-xs text-brand dark:bg-brand-deep/30 dark:text-brand-mint"
                >
                    <template v-if="tool === 'select'">
                        Tracez un rectangle autour d’une seule dérivation longue
                        (idéalement DII en bas du tracé).
                    </template>
                    <template v-else-if="tool === 'caliper'">
                        Cliquez au début puis à la fin de l’intervalle
                        {{ caliperLabel }}.
                    </template>
                    <template v-else>
                        Cliquez sur deux lignes du quadrillage distantes de
                        {{ calibrationMm }} mm (1 grand carreau = 5 mm).
                    </template>
                </p>

                <div class="overflow-hidden rounded-lg border bg-white">
                    <canvas
                        ref="canvas"
                        class="block w-full touch-none"
                        :class="tool ? 'cursor-crosshair' : ''"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointerleave="onPointerUp"
                    />
                    <p
                        v-if="!imageReady"
                        class="p-8 text-center text-xs text-muted-foreground"
                    >
                        Chargement du tracé…
                    </p>
                </div>

                <p
                    v-if="measureMessage"
                    class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
                >
                    <TriangleAlert class="mt-px size-3.5 shrink-0" />
                    {{ measureMessage }}
                </p>
            </section>

            <div class="grid gap-4 2xl:grid-cols-2">
                <!-- Measurements & safety flags -->
                <section class="med-panel space-y-3 p-4">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <ScanLine class="size-4 text-brand" /> Mesures du
                        logiciel
                    </p>
                    <div
                        v-if="selected.measurements?.beat_count"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                    >
                        <div class="rounded-lg bg-muted/40 p-2.5">
                            <p class="text-[11px] text-muted-foreground">
                                Fréquence
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ selected.measurements.heart_rate_bpm ?? '—'
                                }}<span class="text-xs font-medium">/min</span>
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-2.5">
                            <p class="text-[11px] text-muted-foreground">
                                Variabilité RR
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ selected.measurements.rr_cv_percent ?? '—'
                                }}<span class="text-xs font-medium"> %</span>
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-2.5">
                            <p class="text-[11px] text-muted-foreground">
                                RR min – max
                            </p>
                            <p class="text-sm font-bold tabular-nums">
                                {{ selected.measurements.rr_min_ms }}–{{
                                    selected.measurements.rr_max_ms
                                }}
                                ms
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-2.5">
                            <p class="text-[11px] text-muted-foreground">
                                QRS détectés
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ selected.measurements.beat_count }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Choisissez une dérivation longue puis « Mesurer le
                        rythme » : les QRS détectés s’affichent en bleu sur le
                        tracé, avec chaque RR en ms.
                    </p>
                    <div
                        v-if="
                            selected.measurements?.calipers.length ||
                            selected.measurements?.qtc_ms
                        "
                        class="flex flex-wrap gap-1.5 text-xs"
                    >
                        <span
                            v-for="caliper in selected.measurements?.calipers"
                            :key="caliper.label + caliper.ms"
                            class="rounded-full bg-muted px-2 py-0.5 font-medium"
                        >
                            {{ caliper.label }} {{ caliper.ms }} ms
                        </span>
                        <span
                            v-if="selected.measurements?.qtc_ms"
                            class="rounded-full bg-muted px-2 py-0.5 font-medium"
                        >
                            QTc {{ selected.measurements.qtc_ms }} ms (Bazett)
                        </span>
                    </div>

                    <div v-if="selected.flags.length" class="space-y-1.5">
                        <p
                            v-for="flag in selected.flags"
                            :key="flag.code + flag.message"
                            class="flex items-start gap-2 rounded-lg border px-3 py-2 text-xs"
                            :class="flagTone(flag.level)"
                        >
                            <ShieldAlert class="mt-px size-3.5 shrink-0" />
                            {{ flag.message }}
                        </p>
                    </div>
                </section>

                <!-- AI reading -->
                <section class="med-panel space-y-3 p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <p
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <Sparkles class="size-4 text-brand" /> Lecture de
                            l’IA
                        </p>
                        <AiActionButton
                            v-if="canEdit"
                            feature="ecg_analysis"
                            :label="
                                selected.analysis ? 'Relire' : 'Lire avec l’IA'
                            "
                            :loading="analyzing"
                            @click="analyze"
                        />
                    </div>
                    <p
                        v-if="
                            !selected.measurements?.beat_count &&
                            !selected.analysis
                        "
                        class="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
                    >
                        Conseil : mesurez d’abord le rythme. L’IA s’appuie sur
                        ces mesures, bien plus fiables que sa propre lecture de
                        l’image.
                    </p>
                    <AiNotice :failure="aiFailure" @close="aiFailure = null" />

                    <template v-if="selected.analysis">
                        <p
                            class="rounded-lg bg-muted/40 p-3 text-sm font-medium"
                        >
                            {{
                                selected.analysis.primary_statement ||
                                'Lecture sans conclusion.'
                            }}
                        </p>
                        <div class="flex flex-wrap gap-1.5 text-[11px]">
                            <span class="rounded-full bg-muted px-2 py-0.5">
                                Confiance de l’IA :
                                {{ selected.analysis.confidence }}
                            </span>
                            <span class="rounded-full bg-muted px-2 py-0.5">
                                Qualité du tracé :
                                {{ selected.analysis.quality.rating }}
                            </span>
                        </div>
                        <dl class="divide-y rounded-lg border text-xs">
                            <div
                                v-for="row in readingRows"
                                :key="row.label"
                                class="grid grid-cols-[7rem_1fr] gap-2 px-3 py-1.5"
                            >
                                <dt class="text-muted-foreground">
                                    {{ row.label }}
                                </dt>
                                <dd>{{ row.value }}</dd>
                            </div>
                        </dl>
                        <ul
                            v-if="
                                selected.analysis.secondary_statements.length ||
                                selected.analysis.other_findings.length
                            "
                            class="list-disc space-y-0.5 pl-5 text-xs"
                        >
                            <li
                                v-for="item in [
                                    ...selected.analysis.secondary_statements,
                                    ...selected.analysis.other_findings,
                                ]"
                                :key="item"
                            >
                                {{ item }}
                            </li>
                        </ul>
                        <ul
                            v-if="selected.analysis.recommendations.length"
                            class="space-y-0.5 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="item in selected.analysis
                                    .recommendations"
                                :key="item"
                            >
                                → {{ item }}
                            </li>
                        </ul>
                    </template>
                    <p class="text-[11px] leading-4 text-muted-foreground">
                        Les modèles d’IA généralistes lisent mal les ECG sur
                        image : cette lecture est une aide et ne remplace jamais
                        votre interprétation. Les mesures du logiciel font foi.
                    </p>
                </section>
            </div>

            <!-- Chat -->
            <section class="med-panel space-y-3 p-4">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <MessageSquare class="size-4 text-brand" /> Discuter de ce
                    tracé
                    <span class="text-[11px] font-normal text-muted-foreground">
                        {{ creditsLabel(aiCost('ecg_chat')) }} par question
                    </span>
                </p>
                <div
                    v-if="selected.conversation.length"
                    ref="chatBox"
                    class="max-h-80 space-y-2 overflow-y-auto"
                >
                    <div
                        v-for="(turn, index) in selected.conversation"
                        :key="index"
                        class="flex"
                        :class="
                            turn.role === 'user'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <!-- formatAiText escapes everything before allowing bold. -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <p
                            class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line"
                            :class="
                                turn.role === 'user'
                                    ? 'rounded-br-sm bg-brand text-white'
                                    : 'rounded-bl-sm bg-muted text-foreground'
                            "
                            v-html="formatAiText(turn.content)"
                        />
                    </div>
                </div>
                <div v-if="canEdit" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="item in quickQuestions"
                        :key="item"
                        type="button"
                        class="rounded-full border px-2.5 py-1 text-xs transition hover:border-brand hover:bg-brand-soft/40 disabled:opacity-50"
                        :disabled="asking"
                        @click="ask(item)"
                    >
                        {{ item }}
                    </button>
                </div>
                <form v-if="canEdit" class="flex gap-2" @submit.prevent="ask()">
                    <Input
                        v-model="question"
                        placeholder="Posez une question sur ce tracé…"
                        :disabled="asking"
                        maxlength="1000"
                    />
                    <Button
                        type="submit"
                        :disabled="asking || !question.trim()"
                    >
                        <Spinner v-if="asking" class="size-4" />
                        <Send v-else class="size-4" />
                    </Button>
                </form>
            </section>

            <!-- Doctor conclusion -->
            <section class="med-panel space-y-3 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <BadgeCheck class="size-4 text-emerald-600" /> Votre
                        conclusion
                    </p>
                    <Button
                        v-if="
                            canEdit &&
                            (selected.measurements?.beat_count ||
                                selected.analysis)
                        "
                        size="sm"
                        variant="ghost"
                        class="h-7 text-xs"
                        @click="draftFromReading"
                    >
                        Pré-remplir depuis les mesures et la lecture
                    </Button>
                </div>
                <Textarea
                    v-model="conclusion"
                    rows="4"
                    :disabled="!canEdit"
                    placeholder="Votre interprétation, qui sera enregistrée dans le dossier…"
                />
                <InputError :message="conclusionError ?? undefined" />
                <div
                    v-if="canEdit"
                    class="flex flex-wrap items-center justify-end gap-2"
                >
                    <Button
                        v-if="selected.status === 'validated'"
                        size="sm"
                        variant="outline"
                        @click="useInVisit"
                    >
                        Ajouter à l’examen de la visite
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="saving"
                        @click="saveConclusion(false)"
                    >
                        Enregistrer
                    </Button>
                    <Button
                        size="sm"
                        :disabled="saving || !conclusion.trim()"
                        @click="saveConclusion(true)"
                    >
                        <BadgeCheck class="size-4" />
                        {{
                            selected.status === 'validated'
                                ? 'Valider à nouveau'
                                : 'Valider et signer'
                        }}
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
