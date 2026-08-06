import { describe, expect, it } from 'vitest';
import {
    clampSessionSeconds,
    deadlineFromServerState,
    isTrustedUserActivity,
    normalizedIdleTimeoutSeconds,
    remainingSecondsAt,
} from '@/lib/sessionLockTimer';

describe('session lock timer', () => {
    it('uses the server remaining time without extending the session', () => {
        const deadline = deadlineFromServerState(10_000, {
            idleTimeoutSeconds: 900,
            remainingSeconds: 275,
            instanceId: 'test-instance',
        });

        expect(deadline).toBe(285_000);
        expect(remainingSecondsAt(deadline, 12_500)).toBe(273);
    });

    it('clamps invalid or stale server values to the configured timeout', () => {
        expect(clampSessionSeconds(-10, 900)).toBe(0);
        expect(clampSessionSeconds(1_500, 900)).toBe(900);
        expect(clampSessionSeconds(Number.NaN, 900)).toBe(0);
        expect(clampSessionSeconds(10, Number.NaN)).toBe(0);
        expect(normalizedIdleTimeoutSeconds(Number.POSITIVE_INFINITY)).toBe(0);
        expect(
            deadlineFromServerState(10_000, {
                idleTimeoutSeconds: Number.NaN,
                remainingSeconds: Number.NaN,
                instanceId: 'test-instance',
            }),
        ).toBe(10_000);
        expect(remainingSecondsAt(Number.NaN, 10_000)).toBe(0);
    });

    it('does not treat programmatic events as real user activity', () => {
        expect(isTrustedUserActivity({ isTrusted: false })).toBe(false);
        expect(isTrustedUserActivity({ isTrusted: true })).toBe(true);
    });
});
