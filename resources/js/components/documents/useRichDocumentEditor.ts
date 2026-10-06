import { useEditor } from '@tiptap/vue-3';
import { onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';
import { htmlIsEffectivelyEmpty } from '@/lib/documentTemplateBody';
import { compressImageBlob } from '@/lib/docxImport';
import { createDocumentExtensions } from './richDocumentExtensions';

type Options = {
    /** Current HTML; external changes are pushed into the editor. */
    content: () => string;
    editable?: () => boolean;
    placeholder?: string;
    onUpdate: (html: string) => void;
};

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

export const insertImageFiles = async (
    files: File[],
    insert: (src: string) => void,
): Promise<void> => {
    for (const file of files) {
        if (!IMAGE_TYPES.includes(file.type)) {
            toast.error(
                'Format d’image non pris en charge (PNG, JPEG, GIF ou WebP).',
            );

            continue;
        }

        const src = await compressImageBlob(file);

        if (src === null) {
            toast.error('Cette image n’a pas pu être insérée.');

            continue;
        }

        insert(src);
    }
};

/** The stored value: '' for a document without any visible content. */
const serialize = (html: string): string =>
    htmlIsEffectivelyEmpty(html) ? '' : html;

export const useRichDocumentEditor = ({
    content,
    editable = () => true,
    placeholder,
    onUpdate,
}: Options) => {
    const imageFiles = (list: FileList | null | undefined): File[] =>
        Array.from(list ?? []).filter((file) => file.type.startsWith('image/'));

    const editor = useEditor({
        content: content() || '',
        editable: editable(),
        // The strict CSP forbids runtime <style> elements; the base styles
        // ship in richDocument.css instead.
        injectCSS: false,
        extensions: createDocumentExtensions({ placeholder }),
        editorProps: {
            attributes: {
                class: 'rde-content',
                lang: 'fr',
                spellcheck: 'true',
            },
            handlePaste: (_view, event) => {
                const files = imageFiles(event.clipboardData?.files);

                if (files.length === 0) {
                    return false;
                }

                event.preventDefault();
                void insertImageFiles(files, (src) =>
                    editor.value?.chain().focus().setImage({ src }).run(),
                );

                return true;
            },
            handleDrop: (_view, event) => {
                const files = imageFiles(event.dataTransfer?.files);

                if (files.length === 0) {
                    return false;
                }

                event.preventDefault();
                void insertImageFiles(files, (src) =>
                    editor.value?.chain().focus().setImage({ src }).run(),
                );

                return true;
            },
        },
        onUpdate: ({ editor: instance }) => {
            onUpdate(serialize(instance.getHTML()));
        },
    });

    const sync = (value: string) => {
        const instance = editor.value;

        if (!instance) {
            return;
        }

        const current = serialize(instance.getHTML());

        if ((value || '') !== current) {
            instance.commands.setContent(value || '', { emitUpdate: false });
        }
    };

    watch(content, sync);

    watch(editable, (value) => {
        editor.value?.setEditable(value, false);
    });

    // useEditor creates the editor on mount (and destroys it on unmount);
    // catch up with any change made between setup and mount.
    onMounted(() => {
        sync(content());
        editor.value?.setEditable(editable(), false);
    });

    return { editor };
};
