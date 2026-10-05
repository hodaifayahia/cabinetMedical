import { isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

import { hasCompletedDesktopOnboarding } from '@/lib/desktopOnboarding';
import { readDesktopPinEnrollment } from '@/lib/desktopPin';
import { lastCreatedForm, resetCreatedForms } from '@/test/fakeInertiaForm';
import DesktopPinEnrollment from './DesktopPinEnrollment.vue';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

const page = reactive({
    props: {
        auth: {
            user: null as null | {
                id: number;
                name: string;
                can: { enrollDesktopPin: boolean };
            },
        },
    },
});

vi.mock('@inertiajs/vue3', async () => {
    const { fakeUseForm } = await import('@/test/fakeInertiaForm');

    return {
        usePage: () => page,
        useForm: fakeUseForm,
    };
});

vi.mock('@/routes', () => ({
    login: () => ({ url: '/login', method: 'get' }),
    logout: () => ({ url: '/logout', method: 'post' }),
}));

const mockedIsTauri = vi.mocked(isTauri);
const ENROLLMENT_KEY = 'drclickdz.desktop-pin.enrollment.v1';

type PinForm = {
    device_token: string;
    pin: string;
    pin_confirmation: string;
    device_name: string;
};

const user = (id = 7, canEnroll = true) => ({
    id,
    name: 'Dr Amel Benali',
    can: { enrollDesktopPin: canEnroll },
});

const query = <T extends Element = HTMLElement>(selector: string) =>
    document.body.querySelector<T & Element>(selector);

const overlay = () => query('[data-test="desktop-pin-enrollment-overlay"]');
const pinInput = () => query<HTMLInputElement>('#desktop-pin')!;
const confirmationInput = () =>
    query<HTMLInputElement>('#desktop-pin-confirmation')!;
const deviceNameInput = () => query<HTMLInputElement>('#desktop-device-name')!;
const submitButton = () =>
    query<HTMLButtonElement>('[data-test="desktop-pin-enroll-submit"]')!;
const localAlert = () =>
    query(
        '[data-test="desktop-pin-enrollment-form"] div.rounded-2xl[role="alert"]',
    );

const type = async (input: HTMLInputElement, value: string) => {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
};

const submit = async () => {
    query('[data-test="desktop-pin-enrollment-form"]')!.dispatchEvent(
        new Event('submit', { cancelable: true }),
    );
    await flushPromises();
};

const render = async () => {
    const wrapper = mount(DesktopPinEnrollment, {
        global: { stubs: { AuthBackLink: true } },
    });
    await flushPromises();

    return wrapper;
};

const form = () => lastCreatedForm<PinForm>();

const postOptions = () =>
    form().post.mock.calls.at(-1)?.[1] as {
        preserveScroll: boolean;
        onSuccess: () => void;
        onError: () => Promise<void>;
    };

describe('DesktopPinEnrollment', () => {
    beforeEach(() => {
        window.localStorage.clear();
        resetCreatedForms();
        mockedIsTauri.mockReturnValue(true);
        page.props.auth.user = user();
        vi.spyOn(navigator, 'platform', 'get').mockReturnValue('Win32');
    });

    describe('when it appears', () => {
        it('never appears in the hosted browser', async () => {
            mockedIsTauri.mockReturnValue(false);
            await render();

            expect(overlay()).toBeNull();
        });

        it('does not appear for a guest', async () => {
            page.props.auth.user = null;
            await render();

            expect(overlay()).toBeNull();
        });

        it('does not appear for an account that may not enroll a PIN', async () => {
            page.props.auth.user = user(7, false);
            await render();

            expect(overlay()).toBeNull();
        });

        it('does not appear when this installation is already enrolled for the user', async () => {
            window.localStorage.setItem(
                ENROLLMENT_KEY,
                JSON.stringify({
                    version: 1,
                    deviceToken: 'ab'.repeat(32),
                    deviceName: 'Poste accueil',
                    userId: 7,
                    userName: 'Dr Amel Benali',
                }),
            );
            await render();

            expect(overlay()).toBeNull();
        });

        it('appears when the installation is enrolled for another account', async () => {
            window.localStorage.setItem(
                ENROLLMENT_KEY,
                JSON.stringify({
                    version: 1,
                    deviceToken: 'ab'.repeat(32),
                    deviceName: 'Poste accueil',
                    userId: 99,
                    userName: 'Autre',
                }),
            );
            await render();

            expect(overlay()).not.toBeNull();
        });

        it('renders an accessible modal dialog in the document body', async () => {
            await render();

            const dialog = query('[role="dialog"]')!;

            expect(dialog.getAttribute('aria-modal')).toBe('true');
            expect(
                query(`#${dialog.getAttribute('aria-labelledby')}`)
                    ?.textContent,
            ).toContain('Créez votre code PIN');
            expect(
                query(`#${dialog.getAttribute('aria-describedby')}`),
            ).not.toBeNull();
        });

        it('pre-fills a device name from the platform and a fresh device token', async () => {
            await render();

            expect(deviceNameInput().value).toBe('Poste Drclick · Win32');
            expect(form().device_token).toMatch(/^[a-f0-9]{64}$/);
            expect(
                query<HTMLInputElement>('input[name="device_token"]')!.value,
            ).toBe(form().device_token);
        });

        it('focuses the PIN field when it opens', async () => {
            await render();

            expect(document.activeElement).toBe(pinInput());
        });

        it('configures both PIN fields as masked four-digit numeric inputs', async () => {
            await render();

            for (const input of [pinInput(), confirmationInput()]) {
                expect(input.type).toBe('password');
                expect(input.getAttribute('inputmode')).toBe('numeric');
                expect(input.getAttribute('maxlength')).toBe('4');
                expect(input.getAttribute('autocomplete')).toBe('new-password');
            }
        });

        it('offers a way back to the login page', async () => {
            const wrapper = await render();

            expect(
                wrapper.findComponent({ name: 'AuthBackLink' }).exists(),
            ).toBe(true);
        });
    });

    describe('input handling', () => {
        it('keeps only the first four digits typed into the PIN', async () => {
            await render();

            await type(pinInput(), '12ab3456');

            expect(form().pin).toBe('1234');
            expect(pinInput().value).toBe('1234');
        });

        it('normalizes the confirmation the same way', async () => {
            await render();

            await type(confirmationInput(), ' 9-8-7-6 ');

            expect(form().pin_confirmation).toBe('9876');
        });

        it('clears a PIN error as soon as the user edits the field', async () => {
            await render();
            await submit();
            expect(form().errors.pin).toBeDefined();

            await type(pinInput(), '1');

            expect(form().errors.pin).toBeUndefined();
        });
    });

    describe('validation', () => {
        it('requires exactly four digits', async () => {
            await render();
            await type(pinInput(), '12');
            await submit();

            expect(form().errors.pin).toBe('Saisissez exactement 4 chiffres.');
            expect(form().post).not.toHaveBeenCalled();
            expect(document.activeElement).toBe(pinInput());
        });

        it('requires the confirmation to match', async () => {
            await render();
            await type(pinInput(), '1234');
            await type(confirmationInput(), '4321');
            await submit();

            expect(form().errors.pin_confirmation).toBe(
                'La confirmation ne correspond pas au code PIN.',
            );
            expect(form().post).not.toHaveBeenCalled();
        });

        it('requires a non-blank device name', async () => {
            await render();
            await type(pinInput(), '1234');
            await type(confirmationInput(), '1234');
            await type(deviceNameInput(), '    ');
            await submit();

            expect(form().errors.device_name).toBe(
                'Donnez un nom à cet appareil.',
            );
            expect(form().post).not.toHaveBeenCalled();
        });

        it('closes when the account loses the enrollment permission', async () => {
            await render();
            expect(overlay()).not.toBeNull();

            page.props.auth.user = user(7, false);
            await nextTick();

            expect(overlay()).toBeNull();
            expect(form().post).not.toHaveBeenCalled();
        });
    });

    describe('enrolling', () => {
        const fillValid = async (deviceName = '  Poste secrétariat  ') => {
            await type(pinInput(), '4821');
            await type(confirmationInput(), '4821');
            await type(deviceNameInput(), deviceName);
        };

        it('posts the trimmed device name to the enrollment endpoint', async () => {
            await render();
            await fillValid();
            await submit();

            expect(form().post).toHaveBeenCalledTimes(1);
            expect(form().post.mock.calls[0]?.[0]).toBe('/desktop/pin/enroll');
            expect(postOptions().preserveScroll).toBe(true);
            expect(form().device_name).toBe('Poste secrétariat');
            expect(form().pin).toBe('4821');
        });

        it('remembers this installation for the account after the server accepts it', async () => {
            await render();
            await fillValid();
            await submit();
            const token = form().device_token;

            postOptions().onSuccess();
            await nextTick();

            expect(readDesktopPinEnrollment()).toEqual({
                version: 1,
                deviceToken: token,
                deviceName: 'Poste secrétariat',
                userId: 7,
                userName: 'Dr Amel Benali',
            });
            expect(hasCompletedDesktopOnboarding()).toBe(true);
            expect(form().pin).toBe('');
            expect(form().pin_confirmation).toBe('');
            expect(overlay()).toBeNull();
        });

        it('never stores the PIN itself', async () => {
            await render();
            await fillValid();
            await submit();
            postOptions().onSuccess();

            const stored = Object.keys(window.localStorage)
                .map((key) => window.localStorage.getItem(key))
                .join(' ');

            expect(stored).not.toContain('4821');
        });

        it('explains a local storage failure and keeps the dialog open', async () => {
            await render();
            await fillValid();
            await submit();

            vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new DOMException('denied');
            });
            postOptions().onSuccess();
            await nextTick();

            expect(localAlert()?.textContent).toContain(
                'Drclick ne peut pas mémoriser cet appareil.',
            );
            expect(overlay()).not.toBeNull();
            expect(submitButton().disabled).toBe(true);
            expect(form().pin).toBe('');
        });

        it('returns focus to the PIN field when the server rejects the enrollment', async () => {
            await render();
            await fillValid();
            await submit();
            (document.activeElement as HTMLElement | null)?.blur();

            await postOptions().onError();

            expect(document.activeElement).toBe(pinInput());
        });

        it('shows a device token error returned by the server', async () => {
            await render();
            form().setError('device_token', 'Ce poste est déjà enregistré.');
            await nextTick();

            expect(localAlert()?.textContent).toContain(
                'Ce poste est déjà enregistré.',
            );
        });

        it('shows progress while the request is running', async () => {
            await render();
            form().processing = true;
            await nextTick();

            expect(submitButton().disabled).toBe(true);
            expect(submitButton().textContent).toContain(
                'Sécurisation en cours…',
            );
        });

        it('reuses the device token generated when the dialog opened', async () => {
            await render();
            const token = form().device_token;
            await fillValid();
            await submit();

            expect(form().device_token).toBe(token);
        });
    });

    describe('without cryptographic support', () => {
        it('explains the problem and disables the submit button', async () => {
            const rng = vi
                .spyOn(window.crypto, 'getRandomValues')
                .mockImplementation(() => {
                    throw new Error('no entropy');
                });
            await render();

            expect(localAlert()?.textContent).toContain(
                'La protection cryptographique de cet appareil est indisponible.',
            );
            expect(submitButton().disabled).toBe(true);
            expect(form().device_token).toBe('');

            rng.mockRestore();
        });

        it('recovers on submit once the generator works again', async () => {
            const rng = vi
                .spyOn(window.crypto, 'getRandomValues')
                .mockImplementation(() => {
                    throw new Error('no entropy');
                });
            await render();
            rng.mockRestore();

            await type(pinInput(), '1234');
            await type(confirmationInput(), '1234');
            await submit();

            expect(form().device_token).toMatch(/^[a-f0-9]{64}$/);
            expect(localAlert()).toBeNull();
            expect(form().post).toHaveBeenCalledTimes(1);
        });

        it('does not post when the generator still fails on submit', async () => {
            vi.spyOn(window.crypto, 'getRandomValues').mockImplementation(
                () => {
                    throw new Error('no entropy');
                },
            );
            await render();
            await type(pinInput(), '1234');
            await type(confirmationInput(), '1234');
            await submit();

            expect(form().post).not.toHaveBeenCalled();
            expect(localAlert()).not.toBeNull();
        });
    });
});
