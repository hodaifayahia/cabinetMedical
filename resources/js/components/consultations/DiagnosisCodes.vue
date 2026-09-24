<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';

type Code = { code: string; label: string };

const props = defineProps<{
    consultationId: number;
    codes: Code[];
    canEdit: boolean;
}>();

const selected = ref<Code[]>([...props.codes]);
const query = ref('');
const results = ref<Code[]>([]);
const open = ref(false);
let timer: number | undefined;
let controller: AbortController | undefined;

watch(
    () => props.codes,
    (codes) => {
        selected.value = [...codes];
    },
);

const search = async (term: string) => {
    controller?.abort();

    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    controller = new AbortController();

    try {
        const response = await fetch(
            `/app/cim10?q=${encodeURIComponent(term.trim())}`,
            {
                // Marked as AJAX so Laravel does not remember it as the
                // « previous page » that back() redirects to.
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            },
        );
        const payload = (await response.json()) as { results: Code[] };
        results.value = payload.results.filter(
            (result) =>
                !selected.value.some((item) => item.code === result.code),
        );
        open.value = true;
    } catch {
        // Aborted by a newer keystroke, or offline: keep the last results.
    }
};

watch(query, (term) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => search(term), 200);
});

onBeforeUnmount(() => {
    window.clearTimeout(timer);
    controller?.abort();
});

const save = () => {
    router.put(
        `/app/consultations/${props.consultationId}/diagnoses`,
        { codes: selected.value.map((item) => item.code) },
        { preserveScroll: true, preserveState: true },
    );
};

const add = (code: Code) => {
    if (selected.value.some((item) => item.code === code.code)) {
        return;
    }

    selected.value.push(code);
    query.value = '';
    results.value = [];
    open.value = false;
    save();
};

const remove = (code: Code) => {
    selected.value = selected.value.filter((item) => item.code !== code.code);
    save();
};
</script>

<template>
    <div class="grid gap-1.5">
        <div v-if="selected.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="item in selected"
                :key="item.code"
                class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200"
            >
                <strong class="font-mono">{{ item.code }}</strong>
                {{ item.label }}
                <button
                    v-if="canEdit"
                    type="button"
                    class="rounded-full hover:bg-black/10"
                    :aria-label="`Retirer ${item.code}`"
                    @click="remove(item)"
                >
                    <X class="size-3" />
                </button>
            </span>
        </div>
        <div v-if="canEdit" class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="query"
                class="h-9 pl-9 text-sm"
                placeholder="Coder le diagnostic (CIM-10) : angine, J03, diabète…"
                aria-label="Rechercher un code CIM-10"
                @focus="open = results.length > 0"
                @keydown.esc="open = false"
            />
            <ul
                v-if="open && results.length"
                class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border bg-popover p-1 shadow-lg"
            >
                <li v-for="result in results" :key="result.code">
                    <button
                        type="button"
                        class="flex w-full items-start gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-accent"
                        @mousedown.prevent="add(result)"
                    >
                        <span
                            class="w-14 shrink-0 font-mono text-xs font-bold text-amber-700 dark:text-amber-300"
                            >{{ result.code }}</span
                        >
                        <span>{{ result.label }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
