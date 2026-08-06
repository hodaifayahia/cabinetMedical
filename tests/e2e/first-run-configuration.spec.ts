import { expect, test } from '@playwright/test';

test('le premier propriétaire peut enregistrer l’identité du cabinet', async ({
    page,
}) => {
    const landingResponse = await page.goto('/');

    expect(landingResponse?.ok()).toBe(true);
    await expect(
        page.getByRole('heading', {
            name: 'Le cabinet reste opérationnel, même sans Internet.',
        }),
    ).toBeVisible();

    await page
        .getByRole('link', { name: 'Configurer le premier compte' })
        .click();
    await expect(page).toHaveURL(/\/register$/u);

    await page.getByLabel('Nom complet').fill('Dr Test Navigateur');
    await page.getByLabel('Spécialité médicale').fill('Médecine générale');
    await page.getByLabel('Adresse e-mail').fill('owner.e2e@medismart.test');
    await page
        .getByLabel('Mot de passe', { exact: true })
        .fill('E2e-Test-2026!');
    await page.getByLabel('Confirmer le mot de passe').fill('E2e-Test-2026!');

    await Promise.all([
        page.waitForURL(/\/dashboard$/u),
        page
            .getByRole('button', { name: 'Créer le compte propriétaire' })
            .click(),
    ]);

    const configurationResponse = await page.goto(
        '/app/configuration/identity',
    );

    expect(configurationResponse?.ok()).toBe(true);
    await expect(
        page.getByRole('heading', { name: 'Cabinet & documents' }),
    ).toBeVisible();
    await expect(page.getByLabel('Spécialité', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Spécialité', { exact: true })).toHaveValue(
        'Médecine générale',
    );
    await expect(
        page.getByLabel('Ligne supplémentaire du pied de page'),
    ).toBeDisabled();
    await expect(
        page.getByText(/la licence active n’autorise pas la personnalisation/u),
    ).toBeVisible();

    await page.getByLabel('Nom du médecin').fill('Dr Nadia Test');
    await page.getByLabel(/identifiant professionnel/u).fill('E2E-0001');
    await page.getByLabel('Nom du cabinet').fill('Cabinet E2E MediSmart');
    await page.getByLabel('Téléphone').fill('+213 555 000 000');
    await page
        .getByLabel('Email', { exact: true })
        .fill('cabinet.e2e@medismart.test');
    await page.getByLabel('Ville').fill('Ghardaïa');
    await page.getByLabel('Adresse complète').fill('12 rue des Tests');
    const updateResponsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return (
            response.request().method() === 'POST' &&
            url.pathname === '/app/configuration/identity'
        );
    });

    await page.getByRole('button', { name: 'Enregistrer' }).click();

    const updateResponse = await updateResponsePromise;

    expect(updateResponse.status()).toBeLessThan(400);
    await expect(
        page.getByRole('button', { name: 'Enregistrer' }),
    ).toBeEnabled();

    await page.reload();

    await expect(page.getByLabel('Nom du médecin')).toHaveValue(
        'Dr Nadia Test',
    );
    await expect(page.getByLabel(/identifiant professionnel/u)).toHaveValue(
        'E2E-0001',
    );
    await expect(page.getByLabel('Nom du cabinet')).toHaveValue(
        'Cabinet E2E MediSmart',
    );
    await expect(page.getByLabel('Spécialité', { exact: true })).toBeDisabled();
    await expect(
        page.getByText('Tél. +213 555 000 000', { exact: false }),
    ).toContainText(
        'Tél. +213 555 000 000 | E-mail cabinet.e2e@medismart.test | Adresse : 12 rue des Tests, Ghardaïa',
    );
});
