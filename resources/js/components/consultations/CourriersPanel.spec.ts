import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { ClinicalDocumentTemplate } from '@/types/clinicalDocuments';
import CourriersPanel from './CourriersPanel.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));

const template = (
    overrides: Partial<ClinicalDocumentTemplate>,
): ClinicalDocumentTemplate => ({
    source: 'built_in',
    key: 'custom-1',
    category: 'courrier',
    group: 'Mes modèles',
    title: 'Courrier du cabinet',
    description: null,
    body: '',
    default_paper_size: 'A4',
    ...overrides,
});

const mountPanel = (templates: ClinicalDocumentTemplate[]) =>
    mount(CourriersPanel, {
        attachTo: document.body,
        props: {
            consultationId: 7,
            templates,
            documents: [],
            patient: {
                full_name: 'Amine Bensalem',
                date_of_birth: '1980-04-12',
                allergies: 'Pénicilline <forte>',
                antecedents_medical: 'HTA',
                antecedents_surgical: 'Appendicectomie',
                antecedents_family: 'Père diabétique',
            },
            consultation: {
                motif: 'Douleur',
                examens: null,
                diagnostic: 'Angor',
                traitement: 'Aspirine',
                notes: null,
            },
            cabinet: {
                doctor_name: 'Dr Exemple',
                specialty: 'Cardiologie',
                order_number: '123',
                clinic_name: 'Cabinet du Parc',
                phone: '',
                email: '',
                address: '',
                city: '',
                footer: '',
                logo_url: null,
            },
            canEdit: true,
        },
    });

describe('CourriersPanel', () => {
    it('fills every configured variable in a rich template', async () => {
        const wrapper = mountPanel([
            template({
                body_format: 'html',
                body:
                    '<h2 style="text-align: center">{{cabinet.name}}</h2>' +
                    '<p>Allergies : <strong>{{patient.allergies}}</strong></p>' +
                    '<p>{{patient.antecedents_medical}} / {{patient.antecedents_surgical}} / {{patient.antecedents_family}}</p>' +
                    '<p>Né(e) le {{patient.date_of_birth}}</p>',
            }),
        ]);
        await flushPromises();

        const content = wrapper.find('.courrier-content');
        expect(content.find('h2').text()).toBe('Cabinet du Parc');
        expect(content.find('h2').attributes('style')).toContain(
            'text-align: center',
        );
        expect(content.find('strong').text()).toBe('Pénicilline <forte>');
        expect(content.text()).toContain(
            'HTA / Appendicectomie / Père diabétique',
        );
        expect(content.text()).toContain('Né(e) le 12/04/1980');
        expect(content.html()).not.toContain('{{');
    });

    it('still renders legacy line-based templates', async () => {
        const wrapper = mountPanel([
            template({
                body: '## Antécédents familiaux\n{{patient.antecedents_family}}',
            }),
        ]);
        await flushPromises();

        const content = wrapper.find('.courrier-content');
        expect(content.find('h3').text()).toBe('Antécédents familiaux');
        expect(content.text()).toContain('Père diabétique');
    });

    it('lets the doctor edit the courrier in full screen', async () => {
        const wrapper = mountPanel([template({ body: 'Texte' })]);
        await flushPromises();

        const button = wrapper
            .findAll('button')
            .find((candidate) => candidate.text().includes('Plein écran'));
        expect(button).toBeDefined();
        await button!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-clinical-print-page]').exists()).toBe(true);
        expect(wrapper.find('section.fixed').exists()).toBe(true);
    });
});
