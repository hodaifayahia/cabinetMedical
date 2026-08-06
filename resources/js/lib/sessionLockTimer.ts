export type SessionLockState = {
    idleTimeoutSeconds: number;
    remainingSeconds: number;
    instanceId: string;
};

export const normalizedIdleTimeoutSeconds = (value: number): number =>
    Number.isFinite(value) && value >= 1 ? Math.floor(value) : 0;

export const clampSessionSeconds = (value: number, maximum: number): number => {
    const safeMaximum = normalizedIdleTimeoutSeconds(maximum);

    if (!Number.isFinite(value) || safeMaximum === 0) {
        return 0;
    }

    return Math.min(safeMaximum, Math.max(0, Math.floor(value)));
};

export const deadlineFromServerState = (
    nowMilliseconds: number,
    state: SessionLockState,
): number => {
    const timeout = normalizedIdleTimeoutSeconds(state.idleTimeoutSeconds);

    if (!Number.isFinite(nowMilliseconds) || timeout === 0) {
        return Number.isFinite(nowMilliseconds) ? nowMilliseconds : 0;
    }

    const remaining = clampSessionSeconds(state.remainingSeconds, timeout);

    return nowMilliseconds + remaining * 1000;
};

export const remainingSecondsAt = (
    deadlineMilliseconds: number,
    nowMilliseconds: number,
): number => {
    if (
        !Number.isFinite(deadlineMilliseconds) ||
        !Number.isFinite(nowMilliseconds)
    ) {
        return 0;
    }

    return Math.max(
        0,
        Math.ceil((deadlineMilliseconds - nowMilliseconds) / 1000),
    );
};

export const isTrustedUserActivity = (
    event: Pick<Event, 'isTrusted'>,
): boolean => event.isTrusted;
