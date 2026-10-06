<script setup lang="ts">
import { EditorContent } from '@tiptap/vue-3';
import { computed, ref, watch } from 'vue';
import RichDocumentToolbar from './RichDocumentToolbar.vue';
import type { DocumentPlaceholder } from './RichDocumentToolbar.vue';
import { useDocumentFullscreen } from './useDocumentFullscreen';
import { useRichDocumentEditor } from './useRichDocumentEditor';

/**
 * Word-like document editor: formatting toolbar, white A4/A5 page on a grey
 * desk, word count and a full-screen mode (Escape to leave). The value is
 * HTML; the server sanitizes it on save.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        paperSize?: 'A4' | 'A5';
        placeholders?: DocumentPlaceholder[];
        editable?: boolean;
        placeholder?: string;
        label?: string;
    }>(),
    {
        paperSize: 'A4',
        placeholders: () => [],
        editable: true,
        placeholder: 'Rédigez le document ici…',
        label: 'Contenu du document',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    fullscreenChange: [value: boolean];
}>();

const root = ref<HTMLElement | null>(null);
const { isFullscreen, toggle, exit } = useDocumentFullscreen(root);

const { editor } = useRichDocumentEditor({
    content: () => props.modelValue,
    editable: () => props.editable,
    placeholder: props.placeholder,
    onUpdate: (html) => emit('update:modelValue', html),
});

watch(isFullscreen, (value) => emit('fullscreenChange', value));

const words = computed(() => {
    // Reading the (reactive) state re-runs this on every transaction.
    void editor.value?.state;
    const storage = editor.value?.storage as
        | { characterCount?: { words: () => number; characters: () => number } }
        | undefined;

    return {
        words: storage?.characterCount?.words() ?? 0,
        characters: storage?.characterCount?.characters() ?? 0,
    };
});

const focusEnd = () => {
    if (props.editable) {
        editor.value?.commands.focus('end');
    }
};

defineExpose({ editor, exitFullscreen: exit });
</script>

<template>
    <div
        ref="root"
        class="rde-root flex min-h-0 flex-col overflow-hidden bg-background"
        :class="
            isFullscreen
                ? 'fixed inset-0 z-[200] h-dvh w-screen'
                : 'rounded-xl border border-sidebar-border/70 dark:border-sidebar-border'
        "
        data-rich-document-editor
    >
        <RichDocumentToolbar
            :editor="editor"
            :placeholders="placeholders"
            :fullscreen="isFullscreen"
            :disabled="!editable"
            @toggle-fullscreen="toggle"
        >
            <template #actions>
                <slot name="actions" />
            </template>
        </RichDocumentToolbar>

        <div
            class="rde-canvas min-h-0 flex-1 overflow-auto px-3 py-6 sm:px-8"
            @click.self="focusEnd"
        >
            <div
                class="rde-paper"
                :data-paper="paperSize"
                :aria-label="label"
                @click.self="focusEnd"
            >
                <EditorContent :editor="editor" />
            </div>
        </div>

        <div
            class="flex items-center justify-between gap-3 border-t border-sidebar-border/70 bg-slate-50/90 px-3 py-1 text-[11px] text-muted-foreground dark:border-sidebar-border dark:bg-slate-950/40"
        >
            <span>
                {{ words.words }} mot{{ words.words === 1 ? '' : 's' }} ·
                {{ words.characters }} caractère{{
                    words.characters === 1 ? '' : 's'
                }}
            </span>
            <span>
                Page {{ paperSize }}
                <template v-if="isFullscreen">
                    · Échap pour quitter le plein écran</template
                >
            </span>
        </div>
    </div>
</template>
