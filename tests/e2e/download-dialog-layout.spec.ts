import { expect, test } from '@playwright/test';

const viewports = [
    { height: 837, width: 847 },
    { height: 667, width: 375 },
] as const;

test('la fenêtre de téléchargement reste cadrée en arabe et sur petit écran', async ({
    page,
}) => {
    await page.addInitScript(() => {
        window.localStorage.setItem('medismart.landing.locale', 'ar');
    });

    for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        await page.goto('/');
        await page.getByTestId('open-desktop-download-form').first().click();

        const dialog = page.getByTestId('desktop-download-lead-dialog');
        const submit = page.getByTestId('desktop-download-submit');

        await expect(dialog).toBeVisible();
        await expect(submit).toBeVisible();

        const layout = await dialog.evaluate((element) => {
            const overlay = element.parentElement;
            const scrollRegion = element.querySelector(
                '[data-testid="desktop-download-scroll-region"]',
            );
            const submitButton = element.querySelector(
                '[data-testid="desktop-download-submit"]',
            );
            const dialogRect = element.getBoundingClientRect();
            const submitRect = submitButton?.getBoundingClientRect();

            return {
                dialogClientWidth: element.clientWidth,
                dialogDirection: getComputedStyle(element).direction,
                dialogLeft: dialogRect.left,
                dialogRight: dialogRect.right,
                dialogScrollWidth: element.scrollWidth,
                overlayClientWidth: overlay?.clientWidth ?? 0,
                overlayScrollWidth: overlay?.scrollWidth ?? 0,
                scrollClientWidth: scrollRegion?.clientWidth ?? 0,
                scrollScrollWidth: scrollRegion?.scrollWidth ?? 0,
                submitBottom: submitRect?.bottom ?? Number.POSITIVE_INFINITY,
                submitLeft: submitRect?.left ?? Number.NEGATIVE_INFINITY,
                submitRight: submitRect?.right ?? Number.POSITIVE_INFINITY,
                submitTop: submitRect?.top ?? Number.NEGATIVE_INFINITY,
                viewportHeight: window.innerHeight,
                viewportWidth: window.innerWidth,
            };
        });

        expect(layout.dialogDirection).toBe('ltr');
        expect(layout.dialogLeft).toBeGreaterThanOrEqual(7);
        expect(layout.dialogRight).toBeLessThanOrEqual(
            layout.viewportWidth - 7,
        );
        expect(layout.dialogScrollWidth).toBeLessThanOrEqual(
            layout.dialogClientWidth,
        );
        expect(layout.overlayScrollWidth).toBeLessThanOrEqual(
            layout.overlayClientWidth,
        );
        expect(layout.scrollScrollWidth).toBeLessThanOrEqual(
            layout.scrollClientWidth,
        );
        expect(layout.submitLeft).toBeGreaterThanOrEqual(layout.dialogLeft);
        expect(layout.submitRight).toBeLessThanOrEqual(layout.dialogRight);
        expect(layout.submitTop).toBeGreaterThanOrEqual(0);
        expect(layout.submitBottom).toBeLessThanOrEqual(layout.viewportHeight);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    }
});
