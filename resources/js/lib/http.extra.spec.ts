import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    deleteJson,
    getJson,
    HttpError,
    isHttpError,
    isValidationError,
    postFormData,
    postJson,
    putJson,
} from './http';

const clearCookies = () => {
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0]?.trim();

        if (name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
        }
    }
};

const jsonResponse = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status });

describe('HTTP helpers (extended)', () => {
    let fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        clearCookies();
        fetchMock = vi.fn<typeof fetch>();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        clearCookies();
    });

    const lastInit = () => fetchMock.mock.calls.at(-1)?.[1] as RequestInit;
    const lastHeaders = () => lastInit().headers as Record<string, string>;

    it('GET sends no body and no content type', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ ok: true }));

        await expect(getJson('/status')).resolves.toEqual({ ok: true });

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/status');
        expect(lastInit().method).toBe('GET');
        expect(lastInit().body).toBeUndefined();
        expect(lastHeaders()).not.toHaveProperty('Content-Type');
        expect(lastHeaders().Accept).toBe('application/json');
        expect(lastHeaders()['X-Requested-With']).toBe('XMLHttpRequest');
        expect(lastInit().credentials).toBe('same-origin');
    });

    it('PUT serializes its JSON body', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ saved: 1 }));

        await putJson('/items/1', { name: 'Échographie' });

        expect(lastInit().method).toBe('PUT');
        expect(lastInit().body).toBe(JSON.stringify({ name: 'Échographie' }));
        expect(lastHeaders()['Content-Type']).toBe('application/json');
    });

    it('DELETE sends no body', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

        await expect(deleteJson('/items/1')).resolves.toBeNull();

        expect(lastInit().method).toBe('DELETE');
        expect(lastInit().body).toBeUndefined();
        expect(lastHeaders()).not.toHaveProperty('Content-Type');
    });

    it('serializes an explicit null body as JSON', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));

        await postJson('/endpoint', null);

        expect(lastInit().body).toBe('null');
        expect(lastHeaders()['Content-Type']).toBe('application/json');
    });

    it('omits the XSRF header when no cookie is present', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));

        await postJson('/endpoint', {});

        expect(lastHeaders()).not.toHaveProperty('X-XSRF-TOKEN');
    });

    it('does not confuse a look-alike cookie with the XSRF token', async () => {
        document.cookie = 'MY-XSRF-TOKEN=impostor; path=/';
        fetchMock.mockResolvedValue(jsonResponse({}));

        await getJson('/endpoint');

        expect(lastHeaders()).not.toHaveProperty('X-XSRF-TOKEN');
    });

    it('finds the XSRF cookie among others', async () => {
        document.cookie = 'appearance=light; path=/';
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
        document.cookie = 'locale=fr; path=/';
        fetchMock.mockResolvedValue(jsonResponse({}));

        await getJson('/endpoint');

        expect(lastHeaders()['X-XSRF-TOKEN']).toBe('abc==');
    });

    it('sends the XSRF header with multipart uploads too', async () => {
        document.cookie = 'XSRF-TOKEN=token; path=/';
        fetchMock.mockResolvedValue(jsonResponse({}));

        await postFormData('/upload', new FormData());

        expect(lastHeaders()['X-XSRF-TOKEN']).toBe('token');
        expect(lastHeaders()).not.toHaveProperty('Content-Type');
    });

    it('strips a UTF-8 byte-order mark before parsing', async () => {
        fetchMock.mockResolvedValue(
            new Response('﻿{"value":"bom"}', { status: 200 }),
        );

        await expect(getJson('/bom')).resolves.toEqual({ value: 'bom' });
    });

    it('resolves null for an empty successful response', async () => {
        fetchMock.mockResolvedValue(new Response('', { status: 200 }));

        await expect(getJson('/empty')).resolves.toBeNull();
    });

    it('rejects with a SyntaxError for an invalid JSON success body', async () => {
        fetchMock.mockResolvedValue(new Response('<html>', { status: 200 }));

        await expect(getJson('/html')).rejects.toBeInstanceOf(SyntaxError);
    });

    it('propagates a network failure unchanged', async () => {
        const failure = new TypeError('Failed to fetch');
        fetchMock.mockRejectedValue(failure);

        const error = await getJson('/offline').catch((caught) => caught);

        expect(error).toBe(failure);
        expect(isHttpError(error)).toBe(false);
        expect(isValidationError(error)).toBe(false);
    });

    it('turns a 422 without JSON into an empty validation error', async () => {
        fetchMock.mockResolvedValue(new Response('oops', { status: 422 }));

        const error = await postJson('/x', {}).catch((caught) => caught);

        expect(isValidationError(error)).toBe(true);
        expect(error).toEqual({
            validation: true,
            errors: {},
            message: undefined,
        });
    });

    it('defaults missing 422 field errors to an empty object', async () => {
        fetchMock.mockResolvedValue(
            jsonResponse({ message: 'Invalide.' }, 422),
        );

        const error = await postJson('/x', {}).catch((caught) => caught);

        expect(error).toEqual({
            validation: true,
            errors: {},
            message: 'Invalide.',
        });
    });

    it('a 422 is a validation error, not an HttpError', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ errors: {} }, 422));

        const error = await postJson('/x', {}).catch((caught) => caught);

        expect(isHttpError(error)).toBe(false);
    });

    it.each([
        ['a non-JSON body', new Response('Bad gateway', { status: 502 }), 502],
        ['a non-string message', jsonResponse({ message: 42 }, 500), 500],
        ['no message', jsonResponse({ error: 'x' }, 403), 403],
        ['a JSON null body', new Response('null', { status: 404 }), 404],
    ])(
        'falls back to a generic message for %s',
        async (_label, response, status) => {
            fetchMock.mockResolvedValue(response);

            const error = await getJson('/x').catch((caught) => caught);

            expect(error).toBeInstanceOf(HttpError);
            expect(error.status).toBe(status);
            expect(error.message).toBe(`Request failed with status ${status}`);
        },
    );

    it('exposes 423 password-confirmation responses with their status', async () => {
        fetchMock.mockResolvedValue(
            jsonResponse({ message: 'Password confirmation required.' }, 423),
        );

        const error = await postJson('/prepare', {}).catch((caught) => caught);

        expect(isHttpError(error)).toBe(true);
        expect(error.status).toBe(423);
        expect(error.message).toBe('Password confirmation required.');
    });
});

describe('HTTP error guards', () => {
    it('HttpError is a named Error carrying its status', () => {
        const error = new HttpError(503, 'Indisponible');

        expect(error).toBeInstanceOf(Error);
        expect(error.name).toBe('HttpError');
        expect(error.status).toBe(503);
        expect(error.message).toBe('Indisponible');
    });

    it.each([
        null,
        undefined,
        'validation',
        { validation: 'true' },
        { validation: 1 },
        { errors: {} },
    ])('isValidationError rejects %j', (value) => {
        expect(isValidationError(value)).toBe(false);
    });

    it('isValidationError accepts the thrown validation shape', () => {
        expect(isValidationError({ validation: true, errors: {} })).toBe(true);
    });

    it('isHttpError rejects look-alike objects and plain errors', () => {
        expect(isHttpError(new Error('x'))).toBe(false);
        expect(isHttpError({ name: 'HttpError', status: 500 })).toBe(false);
    });
});
