<script setup lang="ts">
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    BetweenHorizontalStart,
    BetweenVerticalStart,
    Bold,
    Columns3,
    Highlighter,
    ImagePlus,
    IndentDecrease,
    IndentIncrease,
    Italic,
    Link2,
    List,
    ListOrdered,
    Maximize2,
    Minimize2,
    Minus,
    Palette,
    Redo2,
    RemoveFormatting,
    Rows3,
    SeparatorHorizontal,
    Strikethrough,
    Table2,
    TableCellsMerge,
    TableCellsSplit,
    Trash2,
    Underline,
    Undo2,
} from '@lucide/vue';
import type { Editor } from '@tiptap/vue-3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { LINE_SPACINGS, isSafeLink } from './richDocumentExtensions';
import { insertImageFiles } from './useRichDocumentEditor';

export type DocumentPlaceholder = { token: string; label: string };

const props = withDefaults(
    defineProps<{
        editor: Editor | undefined;
        placeholders?: DocumentPlaceholder[];
        fullscreen?: boolean;
        showFullscreen?: boolean;
        disabled?: boolean;
    }>(),
    {
        placeholders: () => [],
        fullscreen: false,
        showFullscreen: true,
        disabled: false,
    },
);

const emit = defineEmits<{ toggleFullscreen: [] }>();

const FONT_FAMILIES = [
    'Times New Roman',
    'Arial',
    'Calibri',
    'Cambria',
    'Georgia',
    'Garamond',
    'Verdana',
    'Tahoma',
    'Courier New',
];

const FONT_SIZES = [8, 9, 10, 10.5, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36];

const imageInput = ref<HTMLInputElement | null>(null);
const textColor = ref('#c00000');
const highlightColor = ref('#fff2a8');

const chain = () => props.editor?.chain().focus();
const isActive = (name: string, attributes?: Record<string, unknown>) =>
    props.editor?.isActive(name, attributes) ?? false;

const inactive = computed(() => props.disabled || !props.editor);

const blockStyle = computed(() => {
    for (const level of [1, 2, 3, 4]) {
        if (isActive('heading', { level })) {
            return 'h' + level;
        }
    }

    return 'p';
});

const setBlockStyle = (value: string) => {
    if (value === 'p') {
        chain()?.setParagraph().run();
    } else {
        chain()
            ?.setHeading({ level: Number(value.slice(1)) as 1 | 2 | 3 | 4 })
            .run();
    }
};

const textStyle = computed(
    () =>
        (props.editor?.getAttributes('textStyle') ?? {}) as {
            fontFamily?: string;
            fontSize?: string;
        },
);

const currentFont = computed(() => {
    const family = (textStyle.value.fontFamily ?? '')
        .split(',')[0]
        ?.replaceAll(/["']/g, '')
        .trim();

    return family && FONT_FAMILIES.includes(family) ? family : '';
});

const currentSize = computed(() => {
    const match = /^(\d+(?:\.\d+)?)pt$/.exec(textStyle.value.fontSize ?? '');

    return match ? match[1] : '';
});

const setFont = (value: string) => {
    if (value === '') {
        chain()?.unsetFontFamily().run();
    } else {
        chain()
            ?.setFontFamily(value.includes(' ') ? `"${value}"` : value)
            .run();
    }
};

const setSize = (value: string) => {
    if (value === '') {
        chain()?.unsetFontSize().run();
    } else {
        chain()
            ?.setFontSize(value + 'pt')
            .run();
    }
};

const currentSpacing = computed(() => {
    const attributes = isActive('heading')
        ? props.editor?.getAttributes('heading')
        : props.editor?.getAttributes('paragraph');
    const value = String(attributes?.lineHeight ?? '');

    return LINE_SPACINGS.includes(value) ? value : '';
});

const setSpacing = (value: string) => {
    chain()
        ?.setLineSpacing(value === '' ? null : value)
        .run();
};

const applyColor = (value: string) => {
    textColor.value = value;
    chain()?.setColor(value).run();
};

const applyHighlight = (value: string) => {
    highlightColor.value = value;
    chain()?.setHighlight({ color: value }).run();
};

const indent = () => {
    if (isActive('listItem')) {
        chain()?.sinkListItem('listItem').run();
    } else {
        chain()?.indent().run();
    }
};

const outdent = () => {
    if (isActive('listItem')) {
        chain()?.liftListItem('listItem').run();
    } else {
        chain()?.outdent().run();
    }
};

const editLink = () => {
    const previous = String(props.editor?.getAttributes('link').href ?? '');
    const value = window.prompt(
        'Adresse du lien (https://… ou mailto:…). Laissez vide pour retirer le lien.',
        previous,
    );

    if (value === null) {
        return;
    }

    if (value.trim() === '') {
        chain()?.extendMarkRange('link').unsetLink().run();

        return;
    }

    if (!isSafeLink(value.trim())) {
        window.alert(
            'Lien refusé. Utilisez une adresse HTTPS sans identifiants ou une adresse e-mail (mailto:).',
        );

        return;
    }

    chain()?.extendMarkRange('link').setLink({ href: value.trim() }).run();
};

const pickImage = () => imageInput.value?.click();

const onImagePicked = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    await insertImageFiles(files, (src) => chain()?.setImage({ src }).run());
};

const insertVariable = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const token = select.value;
    select.value = '';

    if (token) {
        chain()?.insertContent(token).run();
    }
};

type Action = {
    label: string;
    icon: unknown;
    run: () => void;
    active?: () => boolean;
};

const markActions: Action[] = [
    {
        label: 'Gras (Ctrl+B)',
        icon: Bold,
        run: () => chain()?.toggleBold().run(),
        active: () => isActive('bold'),
    },
    {
        label: 'Italique (Ctrl+I)',
        icon: Italic,
        run: () => chain()?.toggleItalic().run(),
        active: () => isActive('italic'),
    },
    {
        label: 'Souligné (Ctrl+U)',
        icon: Underline,
        run: () => chain()?.toggleUnderline().run(),
        active: () => isActive('underline'),
    },
    {
        label: 'Barré',
        icon: Strikethrough,
        run: () => chain()?.toggleStrike().run(),
        active: () => isActive('strike'),
    },
];

const alignActions: Action[] = [
    {
        label: 'Aligner à gauche',
        icon: AlignLeft,
        run: () => chain()?.setTextAlign('left').run(),
        active: () => props.editor?.isActive({ textAlign: 'left' }) ?? false,
    },
    {
        label: 'Centrer',
        icon: AlignCenter,
        run: () => chain()?.setTextAlign('center').run(),
        active: () => props.editor?.isActive({ textAlign: 'center' }) ?? false,
    },
    {
        label: 'Aligner à droite',
        icon: AlignRight,
        run: () => chain()?.setTextAlign('right').run(),
        active: () => props.editor?.isActive({ textAlign: 'right' }) ?? false,
    },
    {
        label: 'Justifier',
        icon: AlignJustify,
        run: () => chain()?.setTextAlign('justify').run(),
        active: () => props.editor?.isActive({ textAlign: 'justify' }) ?? false,
    },
];

const listActions: Action[] = [
    {
        label: 'Liste à puces',
        icon: List,
        run: () => chain()?.toggleBulletList().run(),
        active: () => isActive('bulletList'),
    },
    {
        label: 'Liste numérotée',
        icon: ListOrdered,
        run: () => chain()?.toggleOrderedList().run(),
        active: () => isActive('orderedList'),
    },
    { label: 'Diminuer le retrait', icon: IndentDecrease, run: outdent },
    { label: 'Augmenter le retrait (Tab)', icon: IndentIncrease, run: indent },
];

const insertActions: Action[] = [
    {
        label: 'Insérer un tableau',
        icon: Table2,
        run: () =>
            chain()
                ?.insertTable({ rows: 3, cols: 3, withHeaderRow: false })
                .run(),
    },
    { label: 'Insérer une image', icon: ImagePlus, run: pickImage },
    {
        label: 'Lien',
        icon: Link2,
        run: editLink,
        active: () => isActive('link'),
    },
    {
        label: 'Ligne horizontale',
        icon: Minus,
        run: () => chain()?.setHorizontalRule().run(),
    },
    {
        label: 'Saut de page (Ctrl+Entrée)',
        icon: SeparatorHorizontal,
        run: () => chain()?.setPageBreak().run(),
    },
    {
        label: 'Effacer la mise en forme',
        icon: RemoveFormatting,
        run: () => chain()?.unsetAllMarks().clearNodes().run(),
    },
];

const tableActions: Action[] = [
    {
        label: 'Insérer une ligne au-dessus',
        icon: BetweenHorizontalStart,
        run: () => chain()?.addRowBefore().run(),
    },
    {
        label: 'Insérer une ligne en dessous',
        icon: Rows3,
        run: () => chain()?.addRowAfter().run(),
    },
    {
        label: 'Insérer une colonne à gauche',
        icon: BetweenVerticalStart,
        run: () => chain()?.addColumnBefore().run(),
    },
    {
        label: 'Insérer une colonne à droite',
        icon: Columns3,
        run: () => chain()?.addColumnAfter().run(),
    },
    {
        label: 'Fusionner les cellules',
        icon: TableCellsMerge,
        run: () => chain()?.mergeCells().run(),
    },
    {
        label: 'Fractionner la cellule',
        icon: TableCellsSplit,
        run: () => chain()?.splitCell().run(),
    },
];

const groupedPlaceholders = computed(() => {
    const groups = new Map<string, DocumentPlaceholder[]>();
    const groupOf = (token: string): string => {
        if (token.startsWith('{{patient.')) {
            return 'Patient';
        }

        if (token.startsWith('{{consultation.')) {
            return 'Consultation';
        }

        if (token.startsWith('{{doctor.')) {
            return 'Médecin';
        }

        if (token.startsWith('{{cabinet.')) {
            return 'Cabinet';
        }

        return 'Document';
    };

    for (const placeholder of props.placeholders) {
        const group = groupOf(placeholder.token);
        groups.set(group, [...(groups.get(group) ?? []), placeholder]);
    }

    return [...groups.entries()];
});
</script>

<template>
    <div
        class="rde-toolbar flex flex-col gap-1 border-b border-sidebar-border/70 bg-slate-50/90 px-2 py-1.5 dark:border-sidebar-border dark:bg-slate-950/40"
        role="toolbar"
        aria-label="Mise en forme du document"
    >
        <div class="flex flex-wrap items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                title="Annuler (Ctrl+Z)"
                aria-label="Annuler"
                :disabled="inactive || !editor?.can().undo()"
                @mousedown.prevent
                @click="chain()?.undo().run()"
            >
                <Undo2 class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                title="Rétablir (Ctrl+Y)"
                aria-label="Rétablir"
                :disabled="inactive || !editor?.can().redo()"
                @mousedown.prevent
                @click="chain()?.redo().run()"
            >
                <Redo2 class="size-4" />
            </Button>

            <span class="mx-1 h-5 w-px bg-border" />

            <select
                :value="blockStyle"
                :disabled="inactive"
                class="h-8 w-32 rounded-md border border-input bg-background px-2 text-xs"
                title="Style de paragraphe"
                aria-label="Style de paragraphe"
                @change="
                    setBlockStyle(($event.target as HTMLSelectElement).value)
                "
            >
                <option value="p">Normal</option>
                <option value="h1">Titre 1</option>
                <option value="h2">Titre 2</option>
                <option value="h3">Titre 3</option>
                <option value="h4">Titre 4</option>
            </select>
            <select
                :value="currentFont"
                :disabled="inactive"
                class="h-8 w-36 rounded-md border border-input bg-background px-2 text-xs"
                title="Police"
                aria-label="Police"
                @change="setFont(($event.target as HTMLSelectElement).value)"
            >
                <option value="">Police par défaut</option>
                <option
                    v-for="family in FONT_FAMILIES"
                    :key="family"
                    :value="family"
                >
                    {{ family }}
                </option>
            </select>
            <select
                :value="currentSize"
                :disabled="inactive"
                class="h-8 w-[4.5rem] rounded-md border border-input bg-background px-2 text-xs"
                title="Taille de police (pt)"
                aria-label="Taille de police"
                @change="setSize(($event.target as HTMLSelectElement).value)"
            >
                <option value="">Taille</option>
                <option
                    v-for="size in FONT_SIZES"
                    :key="size"
                    :value="String(size)"
                >
                    {{ size }}
                </option>
            </select>

            <span class="mx-1 h-5 w-px bg-border" />

            <Button
                v-for="action in markActions"
                :key="action.label"
                variant="ghost"
                size="icon"
                class="size-8"
                :class="action.active?.() ? 'bg-brand-soft text-brand' : ''"
                :title="action.label"
                :aria-label="action.label"
                :aria-pressed="action.active?.() ?? false"
                :disabled="inactive"
                @mousedown.prevent
                @click="action.run()"
            >
                <component :is="action.icon" class="size-4" />
            </Button>
            <label
                class="relative flex size-8 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                title="Couleur du texte"
            >
                <Palette class="size-4" :style="{ color: textColor }" />
                <input
                    class="absolute inset-0 cursor-pointer opacity-0"
                    type="color"
                    aria-label="Couleur du texte"
                    :value="textColor"
                    :disabled="inactive"
                    @change="
                        applyColor(($event.target as HTMLInputElement).value)
                    "
                />
            </label>
            <label
                class="relative flex size-8 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                title="Surlignage"
            >
                <Highlighter class="size-4" />
                <input
                    class="absolute inset-0 cursor-pointer opacity-0"
                    type="color"
                    aria-label="Couleur de surlignage"
                    :value="highlightColor"
                    :disabled="inactive"
                    @change="
                        applyHighlight(
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>

            <span class="mx-1 h-5 w-px bg-border" />

            <Button
                v-for="action in alignActions"
                :key="action.label"
                variant="ghost"
                size="icon"
                class="size-8"
                :class="action.active?.() ? 'bg-brand-soft text-brand' : ''"
                :title="action.label"
                :aria-label="action.label"
                :aria-pressed="action.active?.() ?? false"
                :disabled="inactive"
                @mousedown.prevent
                @click="action.run()"
            >
                <component :is="action.icon" class="size-4" />
            </Button>

            <div class="ml-auto flex items-center gap-1">
                <slot name="actions" />
                <Button
                    v-if="showFullscreen"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-8 gap-1.5 px-2.5 text-xs"
                    :title="
                        fullscreen
                            ? 'Quitter le plein écran (Échap)'
                            : 'Plein écran'
                    "
                    :aria-pressed="fullscreen"
                    @click="emit('toggleFullscreen')"
                >
                    <Minimize2 v-if="fullscreen" class="size-3.5" />
                    <Maximize2 v-else class="size-3.5" />
                    {{ fullscreen ? 'Quitter le plein écran' : 'Plein écran' }}
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <Button
                v-for="action in listActions"
                :key="action.label"
                variant="ghost"
                size="icon"
                class="size-8"
                :class="action.active?.() ? 'bg-brand-soft text-brand' : ''"
                :title="action.label"
                :aria-label="action.label"
                :disabled="inactive"
                @mousedown.prevent
                @click="action.run()"
            >
                <component :is="action.icon" class="size-4" />
            </Button>
            <select
                :value="currentSpacing"
                :disabled="inactive"
                class="h-8 w-28 rounded-md border border-input bg-background px-2 text-xs"
                title="Interligne"
                aria-label="Interligne"
                @change="setSpacing(($event.target as HTMLSelectElement).value)"
            >
                <option value="">Interligne</option>
                <option
                    v-for="spacing in LINE_SPACINGS"
                    :key="spacing"
                    :value="spacing"
                >
                    {{ spacing.replace('.', ',') }}
                </option>
            </select>

            <span class="mx-1 h-5 w-px bg-border" />

            <Button
                v-for="action in insertActions"
                :key="action.label"
                variant="ghost"
                size="icon"
                class="size-8"
                :class="action.active?.() ? 'bg-brand-soft text-brand' : ''"
                :title="action.label"
                :aria-label="action.label"
                :disabled="inactive"
                @mousedown.prevent
                @click="action.run()"
            >
                <component :is="action.icon" class="size-4" />
            </Button>
            <input
                ref="imageInput"
                type="file"
                class="hidden"
                accept="image/png,image/jpeg,image/gif,image/webp"
                @change="onImagePicked"
            />

            <template v-if="placeholders.length > 0">
                <span class="mx-1 h-5 w-px bg-border" />
                <select
                    class="h-8 max-w-56 rounded-md border border-input bg-background px-2 text-xs"
                    title="Insérer une variable (remplacée automatiquement dans la consultation)"
                    aria-label="Insérer une variable"
                    :disabled="inactive"
                    @change="insertVariable"
                >
                    <option value="">Insérer une variable…</option>
                    <optgroup
                        v-for="[group, items] in groupedPlaceholders"
                        :key="group"
                        :label="group"
                    >
                        <option
                            v-for="placeholder in items"
                            :key="placeholder.token"
                            :value="placeholder.token"
                        >
                            {{ placeholder.label }}
                        </option>
                    </optgroup>
                </select>
            </template>

            <template v-if="isActive('table')">
                <span class="mx-1 h-5 w-px bg-border" />
                <span class="text-[11px] font-medium text-muted-foreground">
                    Tableau :
                </span>
                <Button
                    v-for="action in tableActions"
                    :key="action.label"
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :title="action.label"
                    :aria-label="action.label"
                    :disabled="inactive"
                    @mousedown.prevent
                    @click="action.run()"
                >
                    <component :is="action.icon" class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 px-2 text-xs"
                    title="Supprimer la ligne"
                    :disabled="inactive"
                    @mousedown.prevent
                    @click="chain()?.deleteRow().run()"
                >
                    − Ligne
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 px-2 text-xs"
                    title="Supprimer la colonne"
                    :disabled="inactive"
                    @mousedown.prevent
                    @click="chain()?.deleteColumn().run()"
                >
                    − Colonne
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 px-2 text-xs"
                    title="Ligne d’en-tête"
                    :disabled="inactive"
                    @mousedown.prevent
                    @click="chain()?.toggleHeaderRow().run()"
                >
                    En-tête
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8 text-destructive"
                    title="Supprimer le tableau"
                    aria-label="Supprimer le tableau"
                    :disabled="inactive"
                    @mousedown.prevent
                    @click="chain()?.deleteTable().run()"
                >
                    <Trash2 class="size-4" />
                </Button>
            </template>
        </div>
    </div>
</template>
