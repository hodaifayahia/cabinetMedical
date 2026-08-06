export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    roles: string[];
    permissions: string[];
    can: {
        accessAdminPanel: boolean;
        manageStaff: boolean;
        [key: string]: boolean;
    };
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type SessionLockState = {
    idleTimeoutSeconds: number;
    remainingSeconds: number;
    instanceId: string;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
