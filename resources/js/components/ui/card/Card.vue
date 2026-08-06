<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-6 rounded-2xl border border-white/80 py-6 shadow-[0_4px_18px_rgba(38,70,91,0.07)] dark:border-slate-800',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
