import { Buffer } from 'node:buffer';
import { expect, test } from '@playwright/test';
import { lanUploadUrl } from './support/environment.mjs';
import {
    cleanupPublicUploadFixture,
    createPublicUploadFixture,
} from './support/public-upload-fixture.mjs';

const pdf = Buffer.from(
    [
        '%PDF-1.4',
        '1 0 obj',
        '<< /Type /Catalog /Pages 2 0 R >>',
        'endobj',
        '2 0 obj',
        '<< /Type /Pages /Count 0 >>',
        'endobj',
        'trailer',
        '<< /Root 1 0 R >>',
        '%%EOF',
        '',
    ].join('\n'),
);

let fixture: ReturnType<typeof createPublicUploadFixture>;

test.beforeEach(() => {
    fixture = createPublicUploadFixture();
});

test.afterEach(() => {
    cleanupPublicUploadFixture();
});

test('le lien public autonome autorise, reçoit un PDF et termine l’envoi', async ({
    page,
}) => {
    const browserErrors: string[] = [];
    const failedRequests: string[] = [];
    const externalRequests: string[] = [];
    const requests: string[] = [];
    const responseErrors: string[] = [];
    const expectedOrigin = new URL(lanUploadUrl).origin;

    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => browserErrors.push(error.message));
    page.on('request', (request) => {
        const url = new URL(request.url());

        requests.push(`${request.method()} ${url.pathname}`);

        if (url.origin !== expectedOrigin) {
            externalRequests.push(request.url());
        }
    });
    page.on('requestfailed', (request) => {
        failedRequests.push(
            `${request.method()} ${request.url()} ${request.failure()?.errorText ?? ''}`,
        );
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            responseErrors.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.addInitScript(() => {
        const violations: string[] = [];

        Object.defineProperty(window, '__medismartCspViolations', {
            configurable: false,
            value: violations,
        });
        document.addEventListener('securitypolicyviolation', (event) => {
            violations.push(
                `${event.violatedDirective}: ${event.blockedURI || 'inline'}`,
            );
        });
    });

    const authorizeResponsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return (
            response.request().method() === 'POST' &&
            url.pathname === `/upload/${fixture.selector}/authorize`
        );
    });
    const landingResponse = await page.goto(
        `${lanUploadUrl}/upload/${fixture.selector}#v=${fixture.verifier}`,
    );
    const authorizeResponse = await authorizeResponsePromise;

    expect(landingResponse?.ok()).toBe(true);
    expect(landingResponse?.headers()['content-security-policy']).toContain(
        "default-src 'none'",
    );
    expect(authorizeResponse.status()).toBe(200);
    expect(authorizeResponse.request().isNavigationRequest()).toBe(false);
    await expect(page).toHaveURL(`${lanUploadUrl}/upload/${fixture.selector}`);
    expect(await page.evaluate(() => window.location.hash)).toBe('');
    expect(page.url()).not.toContain(fixture.verifier);

    await expect(
        page.getByRole('heading', { name: 'Choisir les documents' }),
    ).toBeVisible();

    const fileName = 'analyse-e2e.pdf';

    await page.locator('#file-input').setInputFiles({
        buffer: pdf,
        mimeType: 'application/pdf',
        name: fileName,
    });
    await expect(
        page.getByRole('list', { name: 'Fichiers sélectionnés' }),
    ).toContainText(fileName);

    const uploadResponsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return (
            response.request().method() === 'POST' &&
            url.pathname === `/upload/${fixture.selector}/files`
        );
    });

    await page.getByRole('button', { name: 'Envoyer les fichiers' }).click();

    const uploadResponse = await uploadResponsePromise;

    expect(uploadResponse.status()).toBe(201);
    await expect(
        page.getByRole('heading', { name: 'Fichiers reçus' }),
    ).toBeVisible();
    await expect(page.locator('#received-count')).toHaveText('1');
    await expect(
        page.getByRole('list', { name: 'Fichiers reçus' }),
    ).toContainText(fileName);
    await expect(page.locator('#feedback')).toContainText(
        'Les fichiers ont été reçus',
    );

    const completeResponsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return (
            response.request().method() === 'POST' &&
            url.pathname === `/upload/${fixture.selector}/complete`
        );
    });

    await page.getByRole('button', { name: 'Terminer l’envoi' }).click();

    const completeResponse = await completeResponsePromise;

    expect(completeResponse.status()).toBe(200);
    await expect(
        page.getByRole('heading', { name: 'Envoi terminé' }),
    ).toBeVisible();
    await expect(page.locator('#ready')).toBeHidden();

    const cspViolations = await page.evaluate(
        () =>
            (
                window as unknown as Window & {
                    __medismartCspViolations: string[];
                }
            ).__medismartCspViolations,
    );

    expect(cspViolations).toEqual([]);
    expect(browserErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
    expect(responseErrors).toEqual([]);
    expect(externalRequests).toEqual([]);
    expect(requests).toEqual([
        `GET /upload/${fixture.selector}`,
        `POST /upload/${fixture.selector}/authorize`,
        `POST /upload/${fixture.selector}/files`,
        `POST /upload/${fixture.selector}/complete`,
    ]);
});
