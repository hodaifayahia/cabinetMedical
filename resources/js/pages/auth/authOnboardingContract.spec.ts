import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readAuthPage = (page: string): string =>
    readFileSync(
        resolve(process.cwd(), `resources/js/pages/auth/${page}.vue`),
        'utf8',
    );

const readFrontendFile = (path: string): string =>
    readFileSync(resolve(process.cwd(), `resources/js/${path}`), 'utf8');

const pagesWithBackNavigation = [
    'Login',
    'Register',
    'JoinCabinet',
    'DesktopCabinetLogin',
    'PendingActivation',
    'AwaitingApproval',
    'ForgotPassword',
    'ResetPassword',
    'TwoFactorChallenge',
    'ConfirmPassword',
    'VerifyEmail',
];

describe('Drclick authentication and onboarding contract', () => {
    it.each(pagesWithBackNavigation)(
        '%s exposes an explicit back action',
        (page) => {
            const source = readAuthPage(page);

            expect(source).toContain('AuthBackLink');
            expect(source).toContain('<AuthBackLink');
        },
    );

    it('collects and labels every required cabinet registration field', () => {
        const source = readAuthPage('Register');

        for (const field of [
            'name',
            'phone',
            'email',
            'cabinet_name',
            'specialization',
            'wilaya',
            'password',
            'password_confirmation',
        ]) {
            expect(source).toContain(`name="${field}"`);
        }

        expect(source).toContain('type="tel"');
        expect(source).toContain('autocomplete="tel"');
        expect(source).toContain(':aria-invalid="Boolean(errors.phone)"');
        expect(source).toContain('minlength="12"');
        expect(source).toContain('N’utilisez pas votre adresse e-mail.');
    });

    it('does not expose a platform administration destination on public auth pages', () => {
        for (const page of [
            'Login',
            'Register',
            'JoinCabinet',
            'DesktopCabinetLogin',
        ]) {
            expect(readAuthPage(page)).not.toMatch(/href=["']\/admin/u);
        }
    });

    it('marks successful desktop setup entry points as complete', () => {
        for (const page of ['Login', 'JoinCabinet', 'DesktopCabinetLogin']) {
            const source = readAuthPage(page);

            expect(source).toContain('markDesktopOnboardingComplete');
            expect(source).toContain(
                '@success="markDesktopOnboardingComplete"',
            );
        }

        const registration = readAuthPage('Register');

        expect(registration).toContain(
            '@success="markDesktopOnboardingComplete"',
        );
        expect(registration).toContain('markDesktopOnboardingComplete');
    });

    it('uses one simple centered shadcn card without a split marketing panel', () => {
        const layout = readFrontendFile('layouts/auth/AuthSimpleLayout.vue');

        expect(layout).toContain('<Card');
        expect(layout).toContain('<CardHeader');
        expect(layout).toContain('<CardContent');
        expect(layout).not.toContain('lg:grid-cols-');
        expect(layout).not.toContain('Votre cabinet, fluide');
    });

    it('requires cabinet and staff credentials for an existing desktop cabinet', () => {
        const source = readAuthPage('DesktopCabinetLogin');

        for (const field of ['owner_email', 'email', 'password']) {
            expect(source).toContain(`name="${field}"`);
        }

        expect(source).toContain('action="/desktop/cabinet-login"');
        expect(source).toContain('name="remember"');
        expect(source).toContain('value="1"');
        expect(source).not.toContain('href="/join"');
    });

    it('shows first-run choices once and sends remembered desktop profiles to login', () => {
        const welcome = readFrontendFile('pages/Welcome.vue');
        const login = readAuthPage('Login');

        expect(welcome).toContain('hasCompletedDesktopOnboarding');
        expect(welcome).toContain('authenticatedDesktopDestination');
        expect(welcome).toContain("? '/admin'");
        expect(welcome).toContain(': dashboard().url');
        expect(welcome).toContain(
            'router.visit(login().url, { replace: true })',
        );
        expect(login).toContain('showRegistrationOptions');
        expect(login).toContain(
            'desktopOnboardingComplete.value = hasCompletedDesktopOnboarding()',
        );
    });

    it('gives the cabinet owner a one-time licence-code redemption form', () => {
        const source = readAuthPage('PendingActivation');

        expect(source).toContain(
            'v-if="can_redeem_license && pending_license_grant"',
        );
        expect(source).toContain('pending_license_grant');
        expect(source).toContain('action="/cabinet/license/redeem"');
        expect(source).toContain('method="post"');
        expect(source).toContain('name="_token"');
        expect(source).toContain('name="license_code"');
        expect(source).toContain('autocomplete="one-time-code"');
        expect(source).toContain('data-test="redeem-license-code"');
        expect(source).toContain('data-test="hosted-license-redemption-unavailable"');
        expect(source).toContain('Aucun code actif disponible');
        expect(source).toContain('action="/cabinet/sign-out"');
        expect(source).toContain('data-test="activation-sign-in-return"');
        expect(source).toContain('markDesktopOnboardingComplete');
    });

    it('renders pending, inactive, suspended, and expired licence states', () => {
        const source = readAuthPage('PendingActivation');

        for (const status of [
            'pending',
            'inactive',
            'suspended',
            'expired',
            'upgrade',
        ]) {
            expect(source).toContain(`'${status}'`);
        }

        expect(source).toContain('licence d’essai de 7 jours');
        expect(source).toContain('licence à vie');
        expect(source).toContain('cabinet.license.plan_label');
        expect(source).toContain('cabinet.license.expires_at');
    });
});
