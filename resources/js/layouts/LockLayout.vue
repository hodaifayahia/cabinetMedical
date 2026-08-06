<script setup lang="ts">
import { LockKeyhole, ShieldCheck } from '@lucide/vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <main
        class="flex min-h-svh items-center justify-center bg-slate-100 px-5 py-8 dark:bg-slate-950"
    >
        <section class="w-full max-w-md" aria-labelledby="lock-title">
            <div
                class="mb-6 flex items-center justify-center gap-3"
                aria-hidden="true"
            >
                <div
                    class="flex size-11 items-center justify-center rounded-2xl bg-[#173f5d] text-white shadow-sm"
                >
                    <ShieldCheck class="size-6" />
                </div>
                <span
                    class="text-lg font-bold tracking-tight text-slate-800 dark:text-white"
                >
                    MediSmart
                </span>
            </div>

            <div
                class="rounded-3xl border border-white bg-white p-7 shadow-[0_18px_60px_rgba(28,60,79,0.12)] sm:p-10 dark:border-slate-800 dark:bg-slate-900"
            >
                <div
                    class="mb-6 flex size-12 items-center justify-center rounded-2xl bg-blue-50 text-[#173f5d] dark:bg-blue-950 dark:text-blue-200"
                    aria-hidden="true"
                >
                    <LockKeyhole class="size-6" />
                </div>
                <h1
                    id="lock-title"
                    class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white"
                >
                    {{ title }}
                </h1>
                <p
                    class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300"
                >
                    {{ description }}
                </p>

                <div class="mt-8">
                    <slot />
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-slate-500">
                Aucune donnée médicale n’est affichée pendant le verrouillage.
            </p>
        </section>
    </main>
</template>
