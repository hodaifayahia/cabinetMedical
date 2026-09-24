import { describe, expect, it } from 'vitest';
import { formatAiText } from './ai';

describe('formatAiText', () => {
    it('renders bold and escapes any markup', () => {
        expect(formatAiText('**FA** <img src=x onerror=alert(1)>')).toBe(
            '<strong>FA</strong> &lt;img src=x onerror=alert(1)&gt;',
        );
    });
});
