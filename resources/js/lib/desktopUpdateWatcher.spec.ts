import { describe, expect, it } from 'vitest';

import {
    availableUpdateFrom,
    UPDATE_FIRST_CHECK_DELAY_MS,
    UPDATE_POLL_INTERVAL_MS,
} from '@/lib/desktopUpdateWatcher';

describe('desktop update watcher', () => {
    it('reports the version when the shell found one', () => {
        expect(
            availableUpdateFrom({
                update: {
                    version: '1.3.0',
                    current_version: '1.2.0',
                    published_at: null,
                },
                checked_at: 1_760_000_000,
            }),
        ).toEqual({ version: '1.3.0', currentVersion: '1.2.0' });
    });

    it('reports nothing when the shell says the app is current', () => {
        expect(
            availableUpdateFrom({ update: null, checked_at: 1_760_000_000 }),
        ).toBeNull();
    });

    it('refuses a malformed reply rather than inventing a version', () => {
        // A native reply this component does not recognise must never turn
        // into an update prompt, because the next click would install it.
        for (const raw of [
            null,
            undefined,
            'nope',
            {},
            { update: {}, checked_at: 1 },
            { update: { version: '1.0.0' }, checked_at: 0 },
            { update: null },
        ]) {
            expect(availableUpdateFrom(raw)).toBeNull();
        }
    });

    it('waits before the first check and then polls on a calm interval', () => {
        // Launch is the worst moment to spend a clinic's bandwidth, and an
        // update is never urgent to the second.
        expect(UPDATE_FIRST_CHECK_DELAY_MS).toBeGreaterThanOrEqual(5_000);
        expect(UPDATE_POLL_INTERVAL_MS).toBeGreaterThanOrEqual(60_000);
    });
});
