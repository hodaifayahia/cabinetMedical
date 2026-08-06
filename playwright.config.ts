import { env } from 'node:process';
import { defineConfig, devices } from '@playwright/test';
import {
    appPort,
    appUrl,
    e2eEnvironment,
    projectRoot,
} from './tests/e2e/support/environment.mjs';

export default defineConfig({
    expect: {
        timeout: 10_000,
    },
    forbidOnly: Boolean(env.CI),
    fullyParallel: false,
    outputDir: 'test-results/playwright',
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    reporter: env.CI
        ? [
              ['github'],
              ['html', { open: 'never', outputFolder: 'playwright-report' }],
          ]
        : [
              ['list'],
              ['html', { open: 'never', outputFolder: 'playwright-report' }],
          ],
    retries: 0,
    testDir: './tests/e2e',
    testIgnore: 'support/**',
    timeout: 45_000,
    use: {
        actionTimeout: 10_000,
        baseURL: appUrl,
        colorScheme: 'light',
        locale: 'fr-FR',
        navigationTimeout: 20_000,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        timezoneId: 'Africa/Algiers',
        video: 'off',
    },
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${appPort}`,
        cwd: projectRoot,
        env: e2eEnvironment,
        name: 'Laravel',
        reuseExistingServer: false,
        stderr: 'pipe',
        stdout: 'pipe',
        timeout: 120_000,
        url: `${appUrl}/up`,
    },
    workers: 1,
});
