import { expect, test } from '@playwright/test';

const directive = (policy: string, name: string): string[] => {
    for (const rawDirective of policy.split(';')) {
        const [directiveName, ...sources] = rawDirective.trim().split(/\s+/u);

        if (directiveName === name) {
            return sources;
        }
    }

    throw new Error(`Missing Content-Security-Policy directive: ${name}`);
};

test('la page Inertia respecte le nonce CSP sans bloquer le navigateur', async ({
    page,
}) => {
    const browserErrors: string[] = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => browserErrors.push(error.message));

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

    const response = await page.goto('/');

    expect(response?.ok()).toBe(true);
    const policy = response?.headers()['content-security-policy'];

    expect(policy).toBeTruthy();
    const scriptSources = directive(policy!, 'script-src');
    const styleSources = directive(policy!, 'style-src');
    const nonceSource = scriptSources.find((source) =>
        source.startsWith("'nonce-"),
    );

    expect(nonceSource).toMatch(/^'nonce-[A-Za-z0-9_-]{43}'$/u);
    expect(scriptSources).not.toContain("'unsafe-inline'");
    expect(scriptSources).not.toContain("'unsafe-eval'");
    expect(styleSources).not.toContain("'unsafe-inline'");
    expect(directive(policy!, 'script-src-attr')).toEqual(["'none'"]);
    expect(directive(policy!, 'style-src-attr')).toEqual(["'unsafe-inline'"]);

    const nonce = nonceSource!.slice(7, -1);
    const documentNonces = await page.evaluate(() => ({
        links: Array.from(
            document.querySelectorAll(
                'link[rel="stylesheet"], link[rel="modulepreload"], link[rel="preload"]',
            ),
            (element) => (element as HTMLLinkElement).nonce,
        ),
        meta: (
            document.querySelector(
                'meta[property="csp-nonce"]',
            ) as HTMLMetaElement | null
        )?.nonce,
        scripts: Array.from(
            document.querySelectorAll('script'),
            (element) => element.nonce,
        ),
        styles: Array.from(
            document.querySelectorAll('style'),
            (element) => element.nonce,
        ),
    }));

    expect(documentNonces.meta).toBe(nonce);
    expect(documentNonces.scripts.length).toBeGreaterThan(0);
    expect(documentNonces.styles.length).toBeGreaterThan(0);
    expect(documentNonces.scripts).toEqual(
        documentNonces.scripts.map(() => nonce),
    );
    expect(documentNonces.styles).toEqual(
        documentNonces.styles.map(() => nonce),
    );
    // Initial Laravel Vite/font preload links carry the nonce. Vite may add
    // further external preload links at runtime; those are covered by the
    // exact source allowlist instead of requiring an inline authorization.
    expect(documentNonces.links).toContain(nonce);

    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator('#app')).not.toBeEmpty();

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
});
