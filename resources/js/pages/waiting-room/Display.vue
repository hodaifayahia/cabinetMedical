<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Maximize } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type Row = { ticket: number | null; name: string; time: string | null };

defineProps<{
    clinic: string;
    board: {
        current: Row[];
        waiting: Row[];
        upcoming: Row[];
        done: number;
    };
}>();

const clock = ref('');
const dateLabel = ref('');

const tick = () => {
    const now = new Date();
    clock.value = new Intl.DateTimeFormat('fr-DZ', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(now);
    dateLabel.value = new Intl.DateTimeFormat('fr-DZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(now);
};

let clockTimer: number | undefined;
let refreshTimer: number | undefined;

onMounted(() => {
    tick();
    clockTimer = window.setInterval(tick, 1000);
    // The board follows the reception desk every 15 seconds.
    refreshTimer = window.setInterval(() => {
        router.reload({ only: ['board'] });
    }, 15000);
});

onBeforeUnmount(() => {
    window.clearInterval(clockTimer);
    window.clearInterval(refreshTimer);
});

const fullscreen = () => {
    void document.documentElement.requestFullscreen?.();
};
</script>

<template>
    <Head title="Salle d’attente" />

    <main
        class="flex min-h-screen flex-col bg-[#062f33] p-8 text-white select-none"
    >
        <header class="flex items-start justify-between gap-6">
            <div>
                <p class="text-3xl font-bold tracking-tight">{{ clinic }}</p>
                <p class="mt-1 text-lg text-white/70 capitalize">
                    {{ dateLabel }}
                </p>
            </div>
            <div class="flex items-start gap-4">
                <p class="text-6xl font-bold tabular-nums">{{ clock }}</p>
                <div class="flex flex-col gap-2 opacity-40 hover:opacity-100">
                    <button
                        type="button"
                        class="rounded-lg border border-white/30 p-2"
                        aria-label="Plein écran"
                        title="Plein écran"
                        @click="fullscreen"
                    >
                        <Maximize class="size-5" />
                    </button>
                    <Link
                        href="/app/consultations"
                        class="rounded-lg border border-white/30 p-2"
                        aria-label="Quitter l’écran de salle d’attente"
                        title="Quitter"
                    >
                        <ArrowLeft class="size-5" />
                    </Link>
                </div>
            </div>
        </header>

        <section class="mt-10 grid flex-1 gap-8 lg:grid-cols-[1.2fr_1fr]">
            <div
                class="flex flex-col justify-center rounded-3xl bg-white/10 p-10"
            >
                <p
                    class="text-2xl font-semibold tracking-wide text-[#8af8b9] uppercase"
                >
                    En consultation
                </p>
                <template v-if="board.current.length">
                    <div
                        v-for="row in board.current"
                        :key="`c${row.ticket}`"
                        class="mt-6"
                    >
                        <p
                            class="text-[9rem] leading-none font-black tabular-nums"
                        >
                            {{ row.ticket ?? '—' }}
                        </p>
                        <p class="mt-4 text-5xl font-semibold">
                            {{ row.name }}
                        </p>
                    </div>
                </template>
                <p v-else class="mt-6 text-4xl text-white/60">
                    Le médecin va bientôt appeler le patient suivant.
                </p>
            </div>

            <div class="flex flex-col gap-6">
                <div class="rounded-3xl bg-white/5 p-8">
                    <p
                        class="text-xl font-semibold tracking-wide text-white/70 uppercase"
                    >
                        En attente ({{ board.waiting.length }})
                    </p>
                    <ol v-if="board.waiting.length" class="mt-4 space-y-3">
                        <li
                            v-for="(row, index) in board.waiting.slice(0, 6)"
                            :key="`w${row.ticket}`"
                            class="flex items-center gap-5 rounded-2xl px-4 py-3"
                            :class="
                                index === 0
                                    ? 'bg-[#8af8b9] text-[#062f33]'
                                    : 'bg-white/5'
                            "
                        >
                            <span
                                class="w-16 text-4xl font-black tabular-nums"
                                >{{ row.ticket }}</span
                            >
                            <span class="text-3xl font-semibold">{{
                                row.name
                            }}</span>
                            <span
                                v-if="index === 0"
                                class="ml-auto text-lg font-bold uppercase"
                                >Suivant</span
                            >
                        </li>
                    </ol>
                    <p v-else class="mt-4 text-2xl text-white/60">
                        Aucun patient en attente.
                    </p>
                </div>

                <div
                    v-if="board.upcoming.length"
                    class="rounded-3xl bg-white/5 p-8"
                >
                    <p
                        class="text-xl font-semibold tracking-wide text-white/70 uppercase"
                    >
                        Prochains rendez-vous
                    </p>
                    <ul class="mt-4 space-y-2">
                        <li
                            v-for="row in board.upcoming"
                            :key="`u${row.time}${row.name}`"
                            class="flex gap-5 text-2xl"
                        >
                            <span class="w-24 font-bold tabular-nums">{{
                                row.time ?? '—'
                            }}</span>
                            <span>{{ row.name }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <footer class="mt-8 text-center text-lg text-white/50">
            Merci de patienter, vous serez appelé par votre numéro ·
            {{ board.done }} patient(s) déjà reçu(s) aujourd’hui
        </footer>
    </main>
</template>
