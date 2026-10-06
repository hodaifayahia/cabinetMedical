import { Extension, Node } from '@tiptap/core';
import type { Extensions } from '@tiptap/core';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import {
    Table,
    TableCell,
    TableHeader,
    TableRow,
} from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import {
    Color,
    FontFamily,
    FontSize,
    TextStyle,
} from '@tiptap/extension-text-style';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { EditorState, Transaction } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import StarterKit from '@tiptap/starter-kit';
import './richDocument.css';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        pageBreak: {
            /** Insert a manual page break (printed and exported to Word). */
            setPageBreak: () => ReturnType;
        };
        indent: {
            indent: () => ReturnType;
            outdent: () => ReturnType;
            /** Paragraph line spacing (unitless, e.g. '1.5'); null resets it. */
            setLineSpacing: (value: string | null) => ReturnType;
        };
    }
}

/** One indentation step, as in Word (≈ 1 cm). */
export const INDENT_STEP_PX = 40;
export const MAX_INDENT_LEVEL = 8;

export const isSafeLink = (value: string): boolean => {
    try {
        const url = new URL(value);

        if (url.protocol === 'mailto:') {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(url.pathname);
        }

        return (
            url.protocol === 'https:' &&
            !url.username &&
            !url.password &&
            (!url.port || url.port === '443')
        );
    } catch {
        return false;
    }
};

export const indentLevelFromMargin = (margin: string): number => {
    const match = /^(-?\d+(?:\.\d+)?)(px|pt|em|rem|cm|mm)?$/.exec(
        margin.trim(),
    );

    if (!match) {
        return 0;
    }

    const value = Number(match[1]);
    const px =
        match[2] === 'pt'
            ? value * (4 / 3)
            : match[2] === 'em' || match[2] === 'rem'
              ? value * 16
              : match[2] === 'cm'
                ? value * 37.8
                : match[2] === 'mm'
                  ? value * 3.78
                  : value;

    return Math.max(
        0,
        Math.min(MAX_INDENT_LEVEL, Math.round(px / INDENT_STEP_PX)),
    );
};

export const PageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: false,

    parseHTML() {
        return [{ tag: 'div.page-break' }];
    },

    renderHTML() {
        return ['div', { class: 'page-break' }];
    },

    addCommands() {
        return {
            setPageBreak:
                () =>
                ({ chain }) =>
                    chain()
                        .insertContent([
                            { type: this.name },
                            { type: 'paragraph' },
                        ])
                        .run(),
        };
    },

    addKeyboardShortcuts() {
        return {
            'Mod-Enter': () => this.editor.commands.setPageBreak(),
        };
    },
});

const INDENTABLE = ['paragraph', 'heading'];

export const LINE_SPACINGS = ['1', '1.15', '1.5', '2', '2.5', '3'];

export const Indent = Extension.create({
    name: 'indent',

    addGlobalAttributes() {
        return [
            {
                types: INDENTABLE,
                attributes: {
                    indent: {
                        default: 0,
                        parseHTML: (element: HTMLElement) =>
                            indentLevelFromMargin(element.style.marginLeft),
                        renderHTML: (attributes: Record<string, unknown>) => {
                            const level = Number(attributes.indent ?? 0);

                            return level > 0
                                ? {
                                      style: `margin-left: ${level * INDENT_STEP_PX}px`,
                                  }
                                : {};
                        },
                    },
                    lineHeight: {
                        default: null,
                        parseHTML: (element: HTMLElement) =>
                            LINE_SPACINGS.includes(element.style.lineHeight)
                                ? element.style.lineHeight
                                : null,
                        renderHTML: (attributes: Record<string, unknown>) =>
                            typeof attributes.lineHeight === 'string' &&
                            LINE_SPACINGS.includes(attributes.lineHeight)
                                ? {
                                      style: `line-height: ${attributes.lineHeight}`,
                                  }
                                : {},
                    },
                },
            },
        ];
    },

    addCommands() {
        const shift =
            (delta: number) =>
            ({
                tr,
                state,
                dispatch,
            }: {
                tr: Transaction;
                state: EditorState;
                dispatch?: unknown;
            }) => {
                const { from, to } = state.selection;
                let changed = false;

                state.doc.nodesBetween(from, to, (node, pos) => {
                    if (!INDENTABLE.includes(node.type.name)) {
                        return true;
                    }

                    const current = Number(node.attrs.indent ?? 0);
                    const next = Math.max(
                        0,
                        Math.min(MAX_INDENT_LEVEL, current + delta),
                    );

                    if (next !== current) {
                        tr.setNodeMarkup(pos, undefined, {
                            ...node.attrs,
                            indent: next,
                        });
                        changed = true;
                    }

                    return false;
                });

                if (changed && dispatch) {
                    (dispatch as (tr: unknown) => void)(tr);
                }

                return changed;
            };

        return {
            indent: () => shift(1),
            outdent: () => shift(-1),
            setLineSpacing:
                (value: string | null) =>
                ({ commands }) =>
                    INDENTABLE.map((type) =>
                        commands.updateAttributes(type, {
                            lineHeight:
                                value !== null && LINE_SPACINGS.includes(value)
                                    ? value
                                    : null,
                        }),
                    ).some(Boolean),
        };
    },

    addKeyboardShortcuts() {
        return {
            Tab: () => {
                if (this.editor.isActive('table')) {
                    return false;
                }

                if (this.editor.isActive('listItem')) {
                    return this.editor.commands.sinkListItem('listItem');
                }

                return this.editor.commands.indent();
            },
            'Shift-Tab': () => {
                if (this.editor.isActive('table')) {
                    return false;
                }

                if (this.editor.isActive('listItem')) {
                    return this.editor.commands.liftListItem('listItem');
                }

                return this.editor.commands.outdent();
            },
        };
    },
});

const TOKEN = /\{\{\s*[a-z0-9_.]+\s*\}\}/gi;

/** Highlights {{variables}} while editing; the stored HTML is unchanged. */
export const TokenHighlight = Extension.create({
    name: 'tokenHighlight',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: new PluginKey('tokenHighlight'),
                props: {
                    decorations(state) {
                        const decorations: Decoration[] = [];

                        state.doc.descendants((node, position) => {
                            if (!node.isText || !node.text) {
                                return;
                            }

                            for (const match of node.text.matchAll(TOKEN)) {
                                const start = position + (match.index ?? 0);
                                decorations.push(
                                    Decoration.inline(
                                        start,
                                        start + match[0].length,
                                        { class: 'rde-token' },
                                    ),
                                );
                            }
                        });

                        return DecorationSet.create(state.doc, decorations);
                    },
                },
            }),
        ];
    },
});

export const createDocumentExtensions = ({
    placeholder = 'Commencez à rédiger…',
}: { placeholder?: string } = {}): Extensions => [
    StarterKit.configure({
        heading: { levels: [1, 2, 3, 4] },
        codeBlock: false,
        code: false,
        link: {
            openOnClick: false,
            autolink: true,
            defaultProtocol: 'https',
            protocols: ['https', 'mailto'],
            isAllowedUri: (url: string) => isSafeLink(url),
        },
    }),
    TextStyle,
    Color,
    FontFamily,
    FontSize,
    Highlight.configure({ multicolor: true }),
    TextAlign.configure({
        types: ['heading', 'paragraph'],
        alignments: ['left', 'center', 'right', 'justify'],
    }),
    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,
    Image.configure({
        inline: true,
        allowBase64: true,
        resize: {
            enabled: true,
            alwaysPreserveAspectRatio: true,
            minWidth: 24,
            minHeight: 24,
        },
    }),
    Placeholder.configure({ placeholder }),
    CharacterCount,
    PageBreak,
    Indent,
    TokenHighlight,
];
