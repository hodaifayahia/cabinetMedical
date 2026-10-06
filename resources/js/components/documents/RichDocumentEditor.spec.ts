import type { Editor } from '@tiptap/vue-3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import RichDocumentEditor from './RichDocumentEditor.vue';
import { indentLevelFromMargin, isSafeLink } from './richDocumentExtensions';

const mountEditor = async (modelValue = '<p>Bonjour</p>') => {
    const wrapper = mount(RichDocumentEditor, {
        attachTo: document.body,
        props: {
            modelValue,
            paperSize: 'A5' as const,
            placeholders: [
                {
                    token: '{{patient.full_name}}',
                    label: 'Nom complet du patient',
                },
                { token: '{{cabinet.name}}', label: 'Nom du cabinet' },
            ],
            'onUpdate:modelValue': (value: string) =>
                wrapper.setProps({ modelValue: value }),
        },
    });
    await flushPromises();

    const editor = (wrapper.vm as unknown as { editor: Editor | undefined })
        .editor as Editor;

    return { wrapper, editor };
};

describe('RichDocumentEditor', () => {
    it('renders the document on an A4/A5 page with a Word-like toolbar', async () => {
        const { wrapper } = await mountEditor();

        expect(wrapper.find('.rde-paper').attributes('data-paper')).toBe('A5');
        expect(wrapper.find('.rde-content').text()).toContain('Bonjour');
        expect(
            wrapper.find('[aria-label="Style de paragraphe"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[aria-label="Police"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Taille de police"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[aria-label="Insérer un tableau"]').exists()).toBe(
            true,
        );
        expect(
            wrapper.find('[aria-label="Insérer une variable"]').exists(),
        ).toBe(true);
        expect(wrapper.text()).toContain('1 mot');
    });

    it('serialises Word-like formatting to HTML the server keeps', async () => {
        const { wrapper, editor } = await mountEditor('<p>Texte</p>');

        editor.commands.selectAll();
        editor.commands.setFontSize('14pt');
        editor.commands.setColor('#c00000');
        editor.commands.setTextAlign('justify');
        editor.commands.indent();
        editor.commands.setLineSpacing('1.5');
        await nextTick();

        const html = wrapper.props('modelValue') as string;
        expect(html).toContain('font-size: 14pt');
        expect(html).toMatch(/color: (#c00000|rgb\(192, 0, 0\))/);
        expect(html).toContain('text-align: justify');
        expect(html).toContain('margin-left: 40px');
        expect(html).toContain('line-height: 1.5');

        editor.commands.setTextSelection(editor.state.doc.content.size - 1);
        editor.commands.setPageBreak();
        editor.commands.insertTable({ rows: 2, cols: 2, withHeaderRow: true });
        await nextTick();

        const withBlocks = wrapper.props('modelValue') as string;
        expect(withBlocks).toContain('<div class="page-break"></div>');
        expect(withBlocks).toContain('<table');
        expect(withBlocks).toContain('<th');
    });

    it('parses imported HTML: page breaks, indentation, highlights and images', async () => {
        const png =
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z9mAAAAAASUVORK5CYII=';
        const { editor } = await mountEditor(
            `<p style="margin-left: 80px">Retrait</p><div class="page-break"></div>` +
                `<p><mark data-color="#fff2a8" style="background-color: #fff2a8">clé</mark> <img src="${png}" alt="Logo"></p>`,
        );

        const html = editor.getHTML();
        expect(html).toContain('margin-left: 80px');
        expect(html).toContain('<div class="page-break"></div>');
        expect(html).toContain('<mark');
        expect(html).toContain(png);
    });

    it('inserts variables from the picker as {{tokens}}', async () => {
        const { wrapper } = await mountEditor('<p></p>');
        const select = wrapper.find<HTMLSelectElement>(
            '[aria-label="Insérer une variable"]',
        );

        await select.setValue('{{cabinet.name}}');
        await nextTick();

        expect(wrapper.props('modelValue')).toContain('{{cabinet.name}}');
        expect(select.element.value).toBe('');
        expect(wrapper.find('.rde-token').text()).toBe('{{cabinet.name}}');
    });

    it('updates when the bound value changes from outside', async () => {
        const { wrapper } = await mountEditor('<p>Avant</p>');

        await wrapper.setProps({ modelValue: '<h2>Après</h2>' });
        await nextTick();

        expect(wrapper.find('.rde-content h2').text()).toBe('Après');
    });

    it('enters and leaves full screen (Escape) without the Fullscreen API', async () => {
        const { wrapper } = await mountEditor();
        const root = wrapper.find('[data-rich-document-editor]');

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Plein écran'))!
            .trigger('click');
        await flushPromises();

        expect(root.classes()).toContain('fixed');
        expect(wrapper.emitted('fullscreenChange')?.at(-1)).toEqual([true]);
        expect(wrapper.text()).toContain('Échap pour quitter');

        const escape = new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        });
        let reachedDocument = false;
        const listener = () => {
            reachedDocument = true;
        };
        document.addEventListener('keydown', listener);
        window.dispatchEvent(escape);
        document.body.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        document.removeEventListener('keydown', listener);
        await flushPromises();

        expect(root.classes()).not.toContain('fixed');
        expect(wrapper.emitted('fullscreenChange')?.at(-1)).toEqual([false]);
        // The Escape that closed full screen must not reach a parent dialog.
        expect(reachedDocument).toBe(false);
    });
});

describe('editor helpers', () => {
    it('accepts only https and mailto links', () => {
        expect(isSafeLink('https://exemple.fr/page')).toBe(true);
        expect(isSafeLink('mailto:dr@exemple.fr')).toBe(true);
        expect(isSafeLink('javascript:alert(1)')).toBe(false);
        expect(isSafeLink('http://exemple.fr')).toBe(false);
        expect(isSafeLink('https://user:pass@exemple.fr')).toBe(false);
        expect(isSafeLink('pas une url')).toBe(false);
    });

    it('maps CSS margins to indentation levels', () => {
        expect(indentLevelFromMargin('40px')).toBe(1);
        expect(indentLevelFromMargin('30pt')).toBe(1);
        expect(indentLevelFromMargin('2.5em')).toBe(1);
        expect(indentLevelFromMargin('2cm')).toBe(2);
        expect(indentLevelFromMargin('9999px')).toBe(8);
        expect(indentLevelFromMargin('')).toBe(0);
        expect(indentLevelFromMargin('-40px')).toBe(0);
    });
});
