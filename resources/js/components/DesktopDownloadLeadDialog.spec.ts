import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

import { lastCreatedForm, resetCreatedForms } from '@/test/fakeInertiaForm';
import DesktopDownloadLeadDialog from './DesktopDownloadLeadDialog.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { fakeUseForm } = await import('@/test/fakeInertiaForm');

    return { useForm: fakeUseForm };
});

type LeadForm = {
    name: string;
    email: string;
    phone: string;
    cabinet_name: string;
    specialization: string;
    website: string;
};

const defaultProps = {
    open: true,
    available: true,
    action: '/desktop-download/leads',
    label: 'Télécharger pour Windows',
    reason: null as string | null,
};

const mounted: { unmount: () => void }[] = [];

const render = async (props: Partial<typeof defaultProps> = {}) => {
    const wrapper = mount(DesktopDownloadLeadDialog, {
        props: { ...defaultProps, ...props },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
};

const query = <T extends Element = HTMLElement>(selector: string) =>
    document.body.querySelector<T & Element>(selector);

const form = () => lastCreatedForm<LeadForm>();

const leadForm = () =>
    query<HTMLFormElement>('[data-testid="desktop-download-lead-form"]');
const submitButton = () =>
    query<HTMLButtonElement>('[data-testid="desktop-download-submit"]')!;

const submit = async () => {
    leadForm()!.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
};

describe('DesktopDownloadLeadDialog', () => {
    beforeEach(() => {
        resetCreatedForms();
    });

    afterEach(() => {
        // Unmount while the dialog portal is still attached; the global
        // setup clears document.body after this hook.
        for (const wrapper of mounted.splice(0)) {
            wrapper.unmount();
        }
    });

    it('renders nothing while closed', async () => {
        await render({ open: false });

        expect(
            query('[data-testid="desktop-download-lead-dialog"]'),
        ).toBeNull();
    });

    it('renders a titled dialog when open', async () => {
        await render();

        const dialog = query('[data-testid="desktop-download-lead-dialog"]')!;

        expect(dialog).not.toBeNull();
        expect(dialog.getAttribute('role')).toBe('dialog');
        expect(dialog.textContent).toContain('Télécharger Drclick Desktop');
        expect(dialog.getAttribute('lang')).toBe('fr');
    });

    it('collects the professional details with appropriate autocomplete hints', async () => {
        await render();

        const expectations: Record<string, [string, string]> = {
            'download-name': ['text', 'name'],
            'download-phone': ['tel', 'tel'],
            'download-email': ['email', 'email'],
            'download-cabinet': ['text', 'organization'],
            'download-specialization': ['text', 'organization-title'],
        };

        for (const [id, [type, autocomplete]] of Object.entries(expectations)) {
            const input = query<HTMLInputElement>(`#${id}`)!;

            expect(input, id).not.toBeNull();
            expect(input.type, id).toBe(type);
            expect(input.getAttribute('autocomplete'), id).toBe(autocomplete);
            expect(input.required, id).toBe(true);
            expect(query(`label[for="${id}"]`), id).not.toBeNull();
        }
    });

    it('keeps the honeypot field out of reach of people', async () => {
        await render();

        const honeypot = query<HTMLInputElement>('#download-website')!;

        expect(honeypot.getAttribute('tabindex')).toBe('-1');
        expect(honeypot.getAttribute('autocomplete')).toBe('off');
        expect(honeypot.closest('[aria-hidden="true"]')).not.toBeNull();
        expect(honeypot.required).toBe(false);
    });

    it('binds typed values to the form', async () => {
        await render();

        const name = query<HTMLInputElement>('#download-name')!;
        name.value = 'Dr Nadia Benali';
        name.dispatchEvent(new Event('input'));
        const email = query<HTMLInputElement>('#download-email')!;
        email.value = 'nadia@cabinet.dz';
        email.dispatchEvent(new Event('input'));
        await nextTick();

        expect(form().name).toBe('Dr Nadia Benali');
        expect(form().email).toBe('nadia@cabinet.dz');
    });

    it('shows the download label on the submit button', async () => {
        await render();

        expect(submitButton().textContent).toContain(
            'Télécharger pour Windows',
        );
        expect(submitButton().disabled).toBe(false);
    });

    it('posts the lead to the configured action', async () => {
        await render();

        await submit();

        expect(form().post).toHaveBeenCalledTimes(1);
        expect(form().post).toHaveBeenCalledWith('/desktop-download/leads', {
            preserveScroll: true,
        });
    });

    it('does not post twice while a submission is running', async () => {
        await render();
        form().processing = true;
        await nextTick();

        await submit();

        expect(form().post).not.toHaveBeenCalled();
        expect(submitButton().disabled).toBe(true);
        expect(submitButton().textContent).toContain('Préparation…');
    });

    it('shows field errors and marks the inputs invalid', async () => {
        await render();
        form().setError('email', 'Adresse e-mail invalide.');
        form().setError('phone', 'Numéro requis.');
        await nextTick();

        expect(query('#download-email-error')?.textContent).toContain(
            'Adresse e-mail invalide.',
        );
        expect(query('#download-email')?.getAttribute('aria-invalid')).toBe(
            'true',
        );
        expect(query('#download-phone-error')?.textContent).toContain(
            'Numéro requis.',
        );
        expect(query('#download-name')?.getAttribute('aria-invalid')).toBe(
            'false',
        );
    });

    it('clears validation errors when the dialog closes', async () => {
        const wrapper = await render();
        form().setError('email', 'Adresse e-mail invalide.');

        await wrapper.setProps({ open: false });

        expect(form().errors).toEqual({});
    });

    it('keeps errors when it is reopened rather than closed', async () => {
        const wrapper = await render({ open: false });
        form().setError('email', 'Adresse e-mail invalide.');

        await wrapper.setProps({ open: true });

        expect(form().errors.email).toBe('Adresse e-mail invalide.');
    });

    it('asks the parent to close when the close button is pressed', async () => {
        const wrapper = await render();

        query<HTMLButtonElement>('[data-slot="dialog-close"]')!.click();
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    describe('when the installer is unavailable', () => {
        it('explains the server reason instead of showing the form', async () => {
            await render({
                available: false,
                reason: 'La nouvelle version est en cours de signature.',
            });

            expect(leadForm()).toBeNull();
            expect(document.body.textContent).toContain(
                'Téléchargement momentanément indisponible',
            );
            expect(document.body.textContent).toContain(
                'La nouvelle version est en cours de signature.',
            );
        });

        it('falls back to a default explanation without a reason', async () => {
            await render({ available: false, reason: null });

            expect(document.body.textContent).toContain(
                'Le programme d’installation sera disponible prochainement.',
            );
        });

        it('never posts even if a submit is forced', async () => {
            const wrapper = await render();
            await wrapper.setProps({ available: false });

            const vm = wrapper.vm as unknown as { submit: () => void };

            expect(typeof vm.submit).toBe('function');
            vm.submit();

            expect(form().post).not.toHaveBeenCalled();
        });
    });
});
