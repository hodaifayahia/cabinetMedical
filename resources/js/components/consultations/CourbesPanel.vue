<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Save, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Measurement = {
    id: number;
    measured_at: string | null;
    weight_kg: string | number | null;
    height_cm: string | number | null;
    bmi: string | number | null;
    waist_cm: string | number | null;
    head_cm: string | number | null;
    notes: string | null;
};

const props = defineProps<{
    consultationId: number;
    measurements: Measurement[];
    canEdit: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    measured_at: today,
    weight_kg: '',
    height_cm: '',
    waist_cm: '',
    head_cm: '',
    notes: '',
});

const bmi = computed(() => {
    const w = parseFloat(form.weight_kg);
    const h = parseFloat(form.height_cm);

    if (w > 0 && h > 0) {
        const m = h / 100;

        return (w / (m * m)).toFixed(1);
    }

    return '--';
});

const cell = (value: string | number | null): string =>
    value === null || value === undefined || value === '' ? '—' : String(value);

const weightSeries = computed(() =>
    props.measurements
        .filter(
            (m) => m.weight_kg !== null && m.weight_kg !== '' && m.measured_at,
        )
        .map((m) => ({
            date: m.measured_at as string,
            value: Number(m.weight_kg),
        }))
        .sort((a, b) => (a.date < b.date ? -1 : 1)),
);

const chartPoints = computed(() => {
    const series = weightSeries.value;

    if (series.length < 2) {
        return '';
    }

    const values = series.map((p) => p.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;
    const width = 600;
    const height = 140;
    const pad = 12;

    return series
        .map((p, i) => {
            const x = pad + (i / (series.length - 1)) * (width - 2 * pad);
            const y =
                height - pad - ((p.value - min) / range) * (height - 2 * pad);

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});

const submit = () => {
    form.post(`/app/consultations/${props.consultationId}/measurements`, {
        preserveScroll: true,
        onSuccess: () =>
            form.reset(
                'weight_kg',
                'height_cm',
                'waist_cm',
                'head_cm',
                'notes',
            ),
    });
};

const remove = (id: number) => {
    router.delete(
        `/app/consultations/${props.consultationId}/measurements/${id}`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
            <div
                class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
            >
                <h3 class="text-base font-semibold text-foreground">
                    Nouvelle mesure
                </h3>
                <p class="text-sm text-muted-foreground">
                    Ajoutez une mesure ponctuelle.
                </p>

                <form class="mt-4 space-y-3" @submit.prevent="submit">
                    <div class="grid gap-1.5">
                        <Label for="m-date">Date</Label>
                        <Input
                            id="m-date"
                            v-model="form.measured_at"
                            type="date"
                            :disabled="!canEdit"
                            required
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="m-weight">Poids (kg)</Label>
                            <Input
                                id="m-weight"
                                v-model="form.weight_kg"
                                type="number"
                                step="any"
                                min="0"
                                :disabled="!canEdit"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="m-height">Taille (cm)</Label>
                            <Input
                                id="m-height"
                                v-model="form.height_cm"
                                type="number"
                                step="any"
                                min="0"
                                :disabled="!canEdit"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>IMC calculé</Label>
                            <Input :model-value="bmi" disabled />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="m-waist">Tour taille (cm)</Label>
                            <Input
                                id="m-waist"
                                v-model="form.waist_cm"
                                type="number"
                                step="any"
                                min="0"
                                :disabled="!canEdit"
                            />
                        </div>
                        <div class="col-span-2 grid gap-1.5">
                            <Label for="m-head">Périmètre crânien (cm)</Label>
                            <Input
                                id="m-head"
                                v-model="form.head_cm"
                                type="number"
                                step="any"
                                min="0"
                                :disabled="!canEdit"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="m-notes">Notes</Label>
                        <Textarea
                            id="m-notes"
                            v-model="form.notes"
                            rows="2"
                            :disabled="!canEdit"
                        />
                    </div>
                    <Button
                        v-if="canEdit"
                        type="submit"
                        class="w-full"
                        :disabled="form.processing"
                    >
                        <Save class="size-4" /> Enregistrer
                    </Button>
                </form>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
            >
                <h3 class="text-base font-semibold text-foreground">
                    Historique
                </h3>
                <div
                    class="mt-3 overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <table
                        class="min-w-full divide-y divide-sidebar-border/70 text-sm dark:divide-sidebar-border"
                    >
                        <thead
                            class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Poids</th>
                                <th class="px-3 py-2 font-medium">Taille</th>
                                <th class="px-3 py-2 font-medium">IMC</th>
                                <th class="px-3 py-2 font-medium">
                                    Tour taille
                                </th>
                                <th class="px-3 py-2 font-medium">PC</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                        >
                            <tr v-if="measurements.length === 0">
                                <td
                                    class="px-3 py-6 text-center text-muted-foreground"
                                    colspan="7"
                                >
                                    Aucune mesure.
                                </td>
                            </tr>
                            <tr v-for="m in measurements" :key="m.id">
                                <td
                                    class="px-3 py-2 font-medium text-foreground"
                                >
                                    {{ m.measured_at }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ cell(m.weight_kg) }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ cell(m.height_cm) }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ cell(m.bmi) }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ cell(m.waist_cm) }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ cell(m.head_cm) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Button
                                        v-if="canEdit"
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Supprimer la mesure"
                                        title="Supprimer la mesure"
                                        @click="remove(m.id)"
                                    >
                                        <Trash2
                                            class="size-4 text-destructive"
                                        />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
        >
            <h3 class="text-base font-semibold text-foreground">
                Évolution du poids
            </h3>
            <div v-if="chartPoints" class="mt-3">
                <svg viewBox="0 0 600 140" class="h-40 w-full">
                    <polyline
                        :points="chartPoints"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="text-primary"
                    />
                </svg>
            </div>
            <p v-else class="mt-3 text-sm text-muted-foreground">
                Ajoutez au moins deux mesures de poids pour afficher la courbe.
            </p>
        </div>
    </div>
</template>
