import { describe, expect, it } from 'vitest';
import { cn, toUrl } from './utils';

describe('cn', () => {
    it('lets the last conflicting Tailwind utility win', () => {
        expect(cn('px-2 py-1', 'px-4')).toBe('py-1 px-4');
    });

    it('drops falsy values and honours conditional objects', () => {
        expect(
            cn('base', false, null, undefined, 0, '', {
                active: true,
                hidden: false,
            }),
        ).toBe('base active');
    });

    it('flattens nested arrays', () => {
        expect(cn(['a', ['b', ['c']]])).toBe('a b c');
    });

    it('returns an empty string for no classes', () => {
        expect(cn()).toBe('');
    });
});

describe('toUrl', () => {
    it('returns a string href unchanged', () => {
        expect(toUrl('/app/patients?x=1')).toBe('/app/patients?x=1');
    });

    it('reads the url of a route object', () => {
        expect(toUrl({ url: '/login', method: 'get' })).toBe('/login');
    });
});
