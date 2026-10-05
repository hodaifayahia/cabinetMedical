import { invoke, isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

import { UPDATE_FIRST_CHECK_DELAY_MS } from '@/lib/desktopUpdateWatcher';
import DesktopUpdateBanner from './DesktopUpdateBanner.vue';

vi.mock('@tauri-apps/api/core', () => ({
    invoke: vi.fn(),
    isTauri: vi.fn(),
}));

const page = reactive({
    props: { desktopUpdateInstallAvailable: true } as Record<string, unknown>,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

const mockedInvoke = vi.mocked(invoke);
const mockedIsTauri = vi.mocked(isTauri);

const VERSION = '1.3.0';
const SUCCESS_MESSAGE =
    'La mise à jour vérifiée est installée; Drclick va redémarrer.';
const FALLBACK_MESSAGE = 'Le programme de mise à jour signé n’a pas répondu.';

const preparation = (targetVersion = VERSION) => ({
    authorization: {
        protocol: 'medismart-update-install-authorization',
        version: 1,
        target_version: targetVersion,
        backup_record_id: '57dca9dd-6c10-49c8-ae81-3d773bf36582',
        backup_sha256: '42'.repeat(32),
        installation_id: 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09',
        issued_at: 1_700_000_000,
        expires_at: 1_700_000_300,
        nonce: 'ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09',
        signature: 'ab'.repeat(32),
    },
    backup: {
        id: '57dca9dd-6c10-49c8-ae81-3d773bf36582',
        filename: 'Drclick-Backup-2026-08-05-100000-abc123.msbackup',
        sha256_hint: '424242424242…',
        completed_at: '2026-08-05T10:00:00+01:00',
    },
});

const checkReply = (version: string | null = VERSION) => ({
    update:
        version === null
            ? null
            : { version, current_version: '1.2.0', published_at: null },
    checked_at: 1_760_000_000,
});

const installReply = (targetVersion = VERSION) => ({
    accepted: true,
    target_version: targetVersion,
    message_fr: SUCCESS_MESSAGE,
});

const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status });

type Route = () => Response | Promise<Response>;

let routes: Record<string, Route> = {};
let nativeInstall: (args: unknown) => unknown = () => installReply();
let nativeCheck: () => unknown = () => checkReply();

const fetchMock = vi.fn<typeof fetch>();

const callsTo = (url: string) =>
    fetchMock.mock.calls.filter(([requested]) => requested === url);

const bodyOf = (url: string, index = 0) =>
    JSON.parse(String(callsTo(url)[index]?.[1]?.body));

const STATUS_URL = '/user/confirmed-password-status?seconds=10800';
const PREPARE_URL = '/app/configuration/updates/prepare-install';
const CONFIRM_URL = '/user/confirm-password';

const mountBanner = async (): Promise<VueWrapper> => {
    // Only the timers are faked: Vue drops DOM events whose timestamp is older
    // than the listener, so a fake clock running ahead of the real one would
    // make every later click look stale.
    vi.useFakeTimers({
        toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
    });
    const wrapper = mount(DesktopUpdateBanner);
    await vi.advanceTimersByTimeAsync(UPDATE_FIRST_CHECK_DELAY_MS);
    vi.useRealTimers();
    await flushPromises();

    return wrapper;
};

const button = (wrapper: VueWrapper, text: string) => {
    const match = wrapper
        .findAll('button')
        .find((candidate) => candidate.text().trim() === text);

    if (!match) {
        throw new Error(`No button labelled ${text}`);
    }

    return match;
};

const hasButton = (wrapper: VueWrapper, text: string) =>
    wrapper.findAll('button').some((candidate) => candidate.text() === text);

describe('DesktopUpdateBanner', () => {
    let confirmSpy = vi.fn<(message?: string) => boolean>();

    beforeEach(() => {
        page.props = { desktopUpdateInstallAvailable: true };
        mockedIsTauri.mockReturnValue(true);
        nativeCheck = () => checkReply();
        nativeInstall = () => installReply();
        mockedInvoke.mockImplementation(async (command, args) => {
            if (command === 'check_for_signed_update') {
                return nativeCheck();
            }

            if (command === 'install_signed_update') {
                return nativeInstall(args);
            }

            throw new Error(`unexpected command ${command}`);
        });
        routes = {
            [STATUS_URL]: () => json({ confirmed: true }),
            [PREPARE_URL]: () => json(preparation()),
            [CONFIRM_URL]: () => new Response(null, { status: 201 }),
        };
        fetchMock.mockReset();
        fetchMock.mockImplementation(async (url) => {
            const route = routes[String(url)];

            if (!route) {
                throw new Error(`unexpected fetch ${String(url)}`);
            }

            return route();
        });
        vi.stubGlobal('fetch', fetchMock);
        confirmSpy = vi.fn(() => true);
        vi.spyOn(window, 'confirm').mockImplementation(confirmSpy);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('visibility', () => {
        it('stays hidden until the first background check finds a release', async () => {
            vi.useFakeTimers();
            const wrapper = mount(DesktopUpdateBanner);

            expect(wrapper.find('[role="status"]').exists()).toBe(false);

            await vi.advanceTimersByTimeAsync(UPDATE_FIRST_CHECK_DELAY_MS);

            expect(wrapper.get('[role="status"]').text()).toContain(
                `La version ${VERSION} est disponible.`,
            );
        });

        it('announces itself politely to assistive technology', async () => {
            const wrapper = await mountBanner();
            const banner = wrapper.get('[role="status"]');

            expect(banner.attributes('aria-live')).toBe('polite');
        });

        it('stays hidden when the server does not allow installs here', async () => {
            page.props = { desktopUpdateInstallAvailable: false };
            const wrapper = await mountBanner();

            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('requires the install flag to be exactly true', async () => {
            page.props = { desktopUpdateInstallAvailable: 'true' };
            const wrapper = await mountBanner();

            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('stays hidden when the shell reports no update', async () => {
            nativeCheck = () => checkReply(null);
            const wrapper = await mountBanner();

            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('stays hidden in the hosted browser', async () => {
            mockedIsTauri.mockReturnValue(false);
            const wrapper = await mountBanner();

            expect(mockedInvoke).not.toHaveBeenCalled();
            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('reacts when the server later withdraws install permission', async () => {
            const wrapper = await mountBanner();
            expect(wrapper.find('[role="status"]').exists()).toBe(true);

            page.props = { desktopUpdateInstallAvailable: false };
            await flushPromises();

            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('hides for the session when the user chooses later', async () => {
            const wrapper = await mountBanner();

            await button(wrapper, 'Plus tard').trigger('click');

            expect(wrapper.find('[role="status"]').exists()).toBe(false);
        });

        it('offers both the install and later actions', async () => {
            const wrapper = await mountBanner();

            expect(hasButton(wrapper, 'Mettre à jour maintenant')).toBe(true);
            expect(hasButton(wrapper, 'Plus tard')).toBe(true);
        });
    });

    describe('installing', () => {
        it('asks for confirmation naming the version and does nothing when declined', async () => {
            confirmSpy.mockReturnValue(false);
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(confirmSpy).toHaveBeenCalledTimes(1);
            expect(confirmSpy.mock.calls[0]?.[0]).toContain(
                `Installer Drclick ${VERSION}`,
            );
            expect(confirmSpy.mock.calls[0]?.[0]).toContain('sauvegarde');
            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('installs after a verified backup when the password was recently confirmed', async () => {
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(callsTo(STATUS_URL)).toHaveLength(1);
            expect(bodyOf(PREPARE_URL)).toEqual({ target_version: VERSION });
            expect(mockedInvoke).toHaveBeenCalledWith('install_signed_update', {
                authorization: preparation().authorization,
            });
            expect(wrapper.text()).toContain(SUCCESS_MESSAGE);
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        });

        it('shows progress and blocks repeated clicks while preparing', async () => {
            let release: (response: Response) => void = () => undefined;
            routes[PREPARE_URL] = () =>
                new Promise<Response>((resolve) => {
                    release = resolve;
                });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            const busy = button(wrapper, 'Préparation…');

            expect(busy.attributes('disabled')).toBeDefined();
            expect(hasButton(wrapper, 'Plus tard')).toBe(false);
            expect(wrapper.text()).toContain(
                'Création et vérification de la sauvegarde de sécurité…',
            );

            await busy.trigger('click');
            expect(callsTo(PREPARE_URL)).toHaveLength(1);

            release(json(preparation()));
            await flushPromises();

            expect(wrapper.text()).toContain(SUCCESS_MESSAGE);
            expect(hasButton(wrapper, 'Mettre à jour maintenant')).toBe(true);
        });

        it('announces the download step once the backup is verified', async () => {
            nativeInstall = () => new Promise(() => undefined);
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.text()).toContain(
                `Sauvegarde vérifiée. Téléchargement et vérification de la version ${VERSION}…`,
            );
        });

        it('asks for the password first when it was not recently confirmed', async () => {
            routes[STATUS_URL] = () => json({ confirmed: false });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.find('input[type="password"]').exists()).toBe(true);
            expect(callsTo(PREPARE_URL)).toHaveLength(0);
            expect(hasButton(wrapper, 'Mettre à jour maintenant')).toBe(false);
            expect(hasButton(wrapper, 'Plus tard')).toBe(false);
        });

        it('treats a non-boolean confirmation status as unconfirmed', async () => {
            routes[STATUS_URL] = () => json({ confirmed: 'yes' });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.find('input[type="password"]').exists()).toBe(true);
        });

        it('lets prepare-install decide when the status endpoint is unavailable', async () => {
            routes[STATUS_URL] = () => Promise.reject(new TypeError('offline'));
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(callsTo(PREPARE_URL)).toHaveLength(1);
            expect(wrapper.text()).toContain(SUCCESS_MESSAGE);
        });

        it('switches to the password prompt when prepare-install answers 423', async () => {
            routes[PREPARE_URL] = () =>
                json({ message: 'Password confirmation required.' }, 423);
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.find('input[type="password"]').exists()).toBe(true);
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('Création et vérification');
            expect(mockedInvoke).not.toHaveBeenCalledWith(
                'install_signed_update',
                expect.anything(),
            );
        });

        it('shows the server message when preparation fails', async () => {
            routes[PREPARE_URL] = () =>
                json({ message: 'La sauvegarde de sécurité a échoué.' }, 500);
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(
                'La sauvegarde de sécurité a échoué.',
            );
            expect(wrapper.text()).not.toContain('Création et vérification');
        });

        it('refuses a malformed preparation without invoking the installer', async () => {
            routes[PREPARE_URL] = () => json({ authorization: {} });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(FALLBACK_MESSAGE);
            expect(mockedInvoke).not.toHaveBeenCalledWith(
                'install_signed_update',
                expect.anything(),
            );
        });

        it('refuses an authorization issued for another version', async () => {
            routes[PREPARE_URL] = () => json(preparation('1.4.0'));
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(FALLBACK_MESSAGE);
            expect(mockedInvoke).not.toHaveBeenCalledWith(
                'install_signed_update',
                expect.anything(),
            );
        });

        it('shows the native updater message for a known failure code', async () => {
            nativeInstall = () =>
                Promise.reject({
                    code: 'signed_updater_busy',
                    message_fr:
                        'Une opération de mise à jour est déjà en cours.',
                });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(
                'Une opération de mise à jour est déjà en cours.',
            );
        });

        it('never displays an unrecognised native error verbatim', async () => {
            nativeInstall = () =>
                Promise.reject({
                    code: 'signed_update_install_failed',
                    message_fr: 'C:\\Users\\cabinet\\AppData\\secret.zip',
                });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(FALLBACK_MESSAGE);
            expect(wrapper.text()).not.toContain('AppData');
        });

        it('refuses an install result for another version', async () => {
            nativeInstall = () => installReply('9.9.9');
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe(FALLBACK_MESSAGE);
            expect(wrapper.text()).not.toContain(SUCCESS_MESSAGE);
        });

        it('clears a previous error when the user tries again', async () => {
            routes[PREPARE_URL] = () => json({ message: 'Échec.' }, 500);
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();
            expect(wrapper.find('[role="alert"]').exists()).toBe(true);

            routes[PREPARE_URL] = () => json(preparation());
            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
            expect(wrapper.text()).toContain(SUCCESS_MESSAGE);
        });
    });

    describe('password confirmation', () => {
        const openPrompt = async () => {
            routes[STATUS_URL] = () => json({ confirmed: false });
            const wrapper = await mountBanner();

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            return wrapper;
        };

        it('keeps the submit button disabled until a password is typed', async () => {
            const wrapper = await openPrompt();

            expect(
                button(wrapper, 'Confirmer et installer').attributes(
                    'disabled',
                ),
            ).toBeDefined();

            await wrapper.get('input[type="password"]').setValue('secret');

            expect(
                button(wrapper, 'Confirmer et installer').attributes(
                    'disabled',
                ),
            ).toBeUndefined();
        });

        it('uses a current-password field so password managers can fill it', async () => {
            const wrapper = await openPrompt();
            const input = wrapper.get('input[type="password"]');

            expect(input.attributes('autocomplete')).toBe('current-password');
            expect(input.attributes('name')).toBe('password');
            expect(input.attributes('required')).toBeDefined();
        });

        it('confirms the password, then installs and clears the field', async () => {
            const wrapper = await openPrompt();

            await wrapper.get('input[type="password"]').setValue('s3cret!');
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(bodyOf(CONFIRM_URL)).toEqual({ password: 's3cret!' });
            expect(bodyOf(PREPARE_URL)).toEqual({ target_version: VERSION });
            expect(wrapper.text()).toContain(SUCCESS_MESSAGE);
            expect(wrapper.find('input[type="password"]').exists()).toBe(false);
        });

        it('does not submit an empty password', async () => {
            const wrapper = await openPrompt();

            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(callsTo(CONFIRM_URL)).toHaveLength(0);
        });

        it('shows the validation message for a wrong password', async () => {
            routes[CONFIRM_URL] = () =>
                json(
                    {
                        message: 'Invalide.',
                        errors: {
                            password: ['Le mot de passe est incorrect.'],
                        },
                    },
                    422,
                );
            const wrapper = await openPrompt();

            await wrapper.get('input[type="password"]').setValue('wrong');
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(wrapper.text()).toContain('Le mot de passe est incorrect.');
            expect(callsTo(PREPARE_URL)).toHaveLength(0);
            expect(
                (
                    wrapper.get('input[type="password"]')
                        .element as HTMLInputElement
                ).value,
            ).toBe('wrong');
        });

        it('shows the server message when confirmation is throttled', async () => {
            routes[CONFIRM_URL] = () =>
                json({ message: 'Trop de tentatives.' }, 429);
            const wrapper = await openPrompt();

            await wrapper.get('input[type="password"]').setValue('again');
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(wrapper.text()).toContain('Trop de tentatives.');
        });

        it('falls back to a generic message for an unexpected failure', async () => {
            routes[CONFIRM_URL] = () =>
                Promise.reject(new TypeError('Failed to fetch'));
            const wrapper = await openPrompt();

            await wrapper.get('input[type="password"]').setValue('again');
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(wrapper.text()).toContain(FALLBACK_MESSAGE);
            expect(wrapper.text()).not.toContain('Failed to fetch');
        });

        it('cancel closes the prompt and forgets the typed password', async () => {
            const wrapper = await openPrompt();

            await wrapper.get('input[type="password"]').setValue('typed');
            await button(wrapper, 'Annuler').trigger('click');

            expect(wrapper.find('input[type="password"]').exists()).toBe(false);
            expect(hasButton(wrapper, 'Mettre à jour maintenant')).toBe(true);
            expect(hasButton(wrapper, 'Plus tard')).toBe(true);

            await button(wrapper, 'Mettre à jour maintenant').trigger('click');
            await flushPromises();

            expect(
                (
                    wrapper.get('input[type="password"]')
                        .element as HTMLInputElement
                ).value,
            ).toBe('');
        });
    });
});
