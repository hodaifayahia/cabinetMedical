import { describe, expect, it } from 'vitest';
import {
    clampSessionSeconds,
    deadlineFromServerState,
    isTrustedUserActivity,
    normalizedIdleTimeoutSeconds,
    remainingSecondsAt,
} from '@/lib/sessionLockTimer';

const state = (idleTimeoutSeconds: number, remainingSeconds: number) => ({
    idleTimeoutSeconds,
    remainingSeconds,
    instanceId: 'instance-a',
});

describe('session lock timer (extended)', () => {
    it.each([
        [900, 900],
        [1, 1],
        [1.9, 1],
        [900.99, 900],
        [0.5, 0],
        [0, 0],
        [-30, 0],
        [Number.NaN, 0],
        [Number.NEGATIVE_INFINITY, 0],
    ])('normalizes idle timeout %s to %s', (input, expected) => {
        expect(normalizedIdleTimeoutSeconds(input)).toBe(expected);
    });

    it.each([
        [10.9, 900, 10],
        [0, 900, 0],
        [900, 900, 900],
        [901, 900.7, 900],
        [5, 0, 0],
        [5, -10, 0],
        [Number.POSITIVE_INFINITY, 900, 0],
        [Number.NEGATIVE_INFINITY, 900, 0],
    ])('clamps %s within %s to %s', (value, maximum, expected) => {
        expect(clampSessionSeconds(value, maximum)).toBe(expected);
    });

    it('never places the deadline beyond the configured timeout', () => {
        expect(deadlineFromServerState(0, state(60, 10_000))).toBe(60_000);
    });

    it('places an already expired session deadline at now', () => {
        expect(deadlineFromServerState(5_000, state(60, -3))).toBe(5_000);
    });

    it('treats a disabled timeout as an immediate deadline', () => {
        expect(deadlineFromServerState(42_000, state(0, 120))).toBe(42_000);
    });

    it('returns 0 for a non-finite clock', () => {
        expect(deadlineFromServerState(Number.NaN, state(60, 30))).toBe(0);
        expect(
            deadlineFromServerState(Number.POSITIVE_INFINITY, state(0, 30)),
        ).toBe(0);
    });

    it('rounds partial seconds up so the countdown never shows 0 early', () => {
        expect(remainingSecondsAt(1_500, 1_000)).toBe(1);
        expect(remainingSecondsAt(1_001, 1_000)).toBe(1);
        expect(remainingSecondsAt(1_000, 1_000)).toBe(0);
    });

    it('never reports negative remaining time', () => {
        expect(remainingSecondsAt(1_000, 9_000)).toBe(0);
    });

    it('returns 0 when either clock is not finite', () => {
        expect(remainingSecondsAt(Number.POSITIVE_INFINITY, 0)).toBe(0);
        expect(remainingSecondsAt(10_000, Number.NaN)).toBe(0);
    });

    it('round-trips a server state through deadline and remaining time', () => {
        const deadline = deadlineFromServerState(1_000_000, state(900, 600));

        expect(remainingSecondsAt(deadline, 1_000_000)).toBe(600);
        expect(remainingSecondsAt(deadline, 1_000_000 + 599_001)).toBe(1);
        expect(remainingSecondsAt(deadline, 1_000_000 + 600_000)).toBe(0);
    });

    it('reads trust only from the event itself', () => {
        const event = new Event('keydown');

        // Events created by scripts are never trusted.
        expect(isTrustedUserActivity(event)).toBe(false);
    });
});
