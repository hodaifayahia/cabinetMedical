<script setup lang="ts">
import { computed } from 'vue';
import { LANDING_LOCALES, type LandingLocale, translations } from './translations';

const props = defineProps<{
    locale: LandingLocale;
    label: string;
}>();

const emit = defineEmits<{
    (event: 'update:locale', value: LandingLocale): void;
}>();

const options = computed(() =>
    LANDING_LOCALES.map((code) => ({
        code,
        short: translations[code].localeShort,
        full: translations[code].localeLabel,
    })),
);

function select(code: LandingLocale): void {
    if (code !== props.locale) {
        emit('update:locale', code);
    }
}
</script>

<template>
    <div
        role="group"
        :aria-label="label"
        class="inline-flex items-center gap-0.5 rounded-full border border-border bg-white/80 p-0.5 text-sm shadow-sm backdrop-blur"
    >
        <button
            v-for="option in options"
            :key="option.code"
            type="button"
            :aria-pressed="option.code === locale"
            :aria-label="option.full"
            :title="option.full"
            class="min-w-9 rounded-full px-3 py-1.5 font-semibold transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                option.code === locale
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
            "
            @click="select(option.code)"
        >
            {{ option.short }}
        </button>
    </div>
</template>
