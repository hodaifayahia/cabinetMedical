import { beforeEach, describe, expect, it, vi } from 'vitest';

import { isHttpError, isValidationError, postFormData, postJson } from './http';

describe('HTTP helpers', () => {
    let fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        fetchMock = vi.fn<typeof fetch>();
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=csrf%20value; path=/';
    });

    it('sends JSON with the decoded Laravel XSRF cookie', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ accepted: true }), { status: 200 }),
        );

        await expect(postJson('/endpoint', { name: 'test' })).resolves.toEqual({
            accepted: true,
        });

        expect(fetchMock).toHaveBeenCalledWith('/endpoint', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': 'csrf value',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ name: 'test' }),
        });
    });

    it('lets the browser set the multipart boundary for FormData', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ prepared: true }), { status: 200 }),
        );
        const formData = new FormData();
        formData.append('passphrase', 'correct horse battery staple');

        await postFormData('/restore', formData);

        const request = fetchMock.mock.calls[0]?.[1];

        expect(request?.body).toBe(formData);
        expect(request?.headers).not.toHaveProperty('Content-Type');
    });

    it('preserves a safe server message on non-validation failures', async () => {
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Service temporairement indisponible.',
                }),
                { status: 503 },
            ),
        );

        try {
            await postJson('/endpoint', {});

            throw new Error('Expected the request to fail.');
        } catch (error) {
            expect(isHttpError(error)).toBe(true);
            expect(error).toMatchObject({
                status: 503,
                message: 'Service temporairement indisponible.',
            });
        }
    });

    it('retains Laravel field errors for 422 responses', async () => {
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Les données sont invalides.',
                    errors: { passphrase: ['La phrase secrète est requise.'] },
                }),
                { status: 422 },
            ),
        );

        try {
            await postJson('/endpoint', {});

            throw new Error('Expected the request to fail.');
        } catch (error) {
            expect(isValidationError(error)).toBe(true);
            expect(error).toMatchObject({
                validation: true,
                errors: {
                    passphrase: ['La phrase secrète est requise.'],
                },
            });
        }
    });
});
