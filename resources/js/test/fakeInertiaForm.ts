import { vi } from 'vitest';
import { reactive } from 'vue';

/**
 * A small stand-in for Inertia's `useForm` that keeps the parts components
 * rely on (field values, errors, processing, post, reset) but never touches
 * the network or the Inertia router. Each created form is recorded so tests
 * can drive `post` callbacks such as `onSuccess` and `onError`.
 */
export type FakeForm<T extends Record<string, unknown>> = T & {
    errors: Record<string, string | undefined>;
    processing: boolean;
    clearErrors: (...fields: string[]) => void;
    setError: (field: string, message: string) => void;
    reset: (...fields: string[]) => void;
    post: ReturnType<typeof vi.fn>;
};

export const createdForms: FakeForm<Record<string, unknown>>[] = [];

export function fakeUseForm<T extends Record<string, unknown>>(
    initial: T,
): FakeForm<T> {
    const defaults = { ...initial };
    const form = reactive({
        ...initial,
        errors: {} as Record<string, string | undefined>,
        processing: false,
        clearErrors(...fields: string[]) {
            if (fields.length === 0) {
                form.errors = {};

                return;
            }

            const next = { ...form.errors };

            for (const field of fields) {
                delete next[field];
            }

            form.errors = next;
        },
        setError(field: string, message: string) {
            form.errors = { ...form.errors, [field]: message };
        },
        reset(...fields: string[]) {
            const names = fields.length > 0 ? fields : Object.keys(defaults);
            const target = form as Record<string, unknown>;

            for (const name of names) {
                target[name] = defaults[name];
            }
        },
        post: vi.fn(),
    }) as unknown as FakeForm<T>;

    createdForms.push(form as FakeForm<Record<string, unknown>>);

    return form;
}

export function lastCreatedForm<
    T extends Record<string, unknown> = Record<string, unknown>,
>(): FakeForm<T> {
    const form = createdForms.at(-1);

    if (!form) {
        throw new Error('No form has been created.');
    }

    return form as unknown as FakeForm<T>;
}

export function resetCreatedForms(): void {
    createdForms.length = 0;
}
