// Lightweight JSON fetch helpers for non-Inertia requests (e.g. quick create
// from within a modal). Sends the Laravel XSRF-TOKEN cookie as a header so the
// web CSRF middleware accepts the request, and tolerates a leading UTF-8 BOM.

export type ValidationError = {
    validation: true;
    errors: Record<string, string[]>;
    message?: string;
};

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
        this.name = 'HttpError';
    }
}

export const isValidationError = (error: unknown): error is ValidationError =>
    typeof error === 'object' &&
    error !== null &&
    (error as { validation?: boolean }).validation === true;

export const isHttpError = (error: unknown): error is HttpError =>
    error instanceof HttpError;

const readCookie = (name: string): string | null => {
    const escaped = name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1');
    const match = document.cookie.match(
        new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[1]) : null;
};

const request = async <T>(
    url: string,
    method: string,
    body?: unknown,
): Promise<T> => {
    const xsrf = readCookie('XSRF-TOKEN');
    const isMultipart =
        typeof FormData !== 'undefined' && body instanceof FormData;

    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body !== undefined && !isMultipart
                ? { 'Content-Type': 'application/json' }
                : {}),
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        credentials: 'same-origin',
        body:
            body === undefined
                ? undefined
                : isMultipart
                  ? body
                  : JSON.stringify(body),
    });

    if (response.status === 422) {
        const data = (await response.json().catch(() => ({ errors: {} }))) as {
            errors?: Record<string, string[]>;
            message?: string;
        };

        throw {
            validation: true,
            errors: data.errors ?? {},
            message: data.message,
        } as ValidationError;
    }

    if (!response.ok) {
        const data = (await response.json().catch(() => null)) as unknown;
        const message =
            typeof data === 'object' &&
            data !== null &&
            'message' in data &&
            typeof data.message === 'string'
                ? data.message
                : `Request failed with status ${response.status}`;

        throw new HttpError(response.status, message);
    }

    const text = await response.text();

    if (!text) {
        return null as T;
    }

    const clean = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;

    return JSON.parse(clean) as T;
};

export const getJson = <T>(url: string): Promise<T> => request<T>(url, 'GET');

export const postJson = <T>(url: string, body: unknown): Promise<T> =>
    request<T>(url, 'POST', body);

export const postFormData = <T>(url: string, body: FormData): Promise<T> =>
    request<T>(url, 'POST', body);

export const putJson = <T>(url: string, body: unknown): Promise<T> =>
    request<T>(url, 'PUT', body);
