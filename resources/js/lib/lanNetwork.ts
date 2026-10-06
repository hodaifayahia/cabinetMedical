import { invoke, isTauri } from '@tauri-apps/api/core';

/**
 * Several Drclick PCs, one cabinet database (ADR-005).
 *
 * The poste principal shares its database on the cabinet LAN; a poste
 * secondaire attaches to it. Every switch is native (Tauri commands), and the
 * commands are only granted to the origins that may use them: sharing and
 * joining to this PC's own loopback origin, "back to local mode" also to the
 * poste principal's pages shown on a poste secondaire.
 */

export const DEFAULT_LAN_HOST_PORT = 47850;

export type RuntimeModeStatus = {
    mode: 'local' | 'attach' | 'cloud' | 'damaged' | string;
    url: string | null;
    local_error: string | null;
};

export type LanAddress = {
    address: string;
    interface: string;
    url: string;
};

export type LanHostStatus = {
    mode: string;
    available: boolean;
    enabled: boolean;
    running: boolean;
    starting: boolean;
    port: number;
    default_port: number;
    discovery_port: number;
    computer_name: string;
    addresses: LanAddress[];
    name_url: string | null;
    error: string | null;
    attached_to: string | null;
};

export type DiscoveredLanHost = {
    name: string;
    address: string;
    port: number;
    url: string;
    version: string;
};

export const isDesktopShell = (): boolean => {
    try {
        return isTauri();
    } catch {
        return false;
    }
};

/** Which machine owns the data, or null outside the desktop shell. */
export const runtimeModeStatus =
    async (): Promise<RuntimeModeStatus | null> => {
        if (!isDesktopShell()) {
            return null;
        }

        try {
            return await invoke<RuntimeModeStatus>('runtime_mode_status');
        } catch {
            return null;
        }
    };

export const lanHostStatus = (): Promise<LanHostStatus> =>
    invoke<LanHostStatus>('lan_host_status');

export const setLanHost = (
    enabled: boolean,
    port?: number,
): Promise<LanHostStatus> =>
    invoke<LanHostStatus>('set_lan_host', { enabled, port: port ?? null });

export const openLanFirewall = (): Promise<boolean> =>
    invoke<boolean>('open_lan_firewall');

export const discoverLanHosts = (): Promise<DiscoveredLanHost[]> =>
    invoke<DiscoveredLanHost[]>('discover_lan_hosts');

/** Attach this PC to a poste principal; Drclick restarts on success. */
export const connectToLanHost = (url: string): Promise<unknown> =>
    invoke('connect_to_lan_host', { url });

/** Go back to this PC's own database; Drclick restarts on success. */
export const returnToLocalMode = (): Promise<boolean> =>
    invoke<boolean>('use_local_mode');

/**
 * Turn what someone typed ("192.168.1.10", "CABINET-PC:47850",
 * "http://192.168.1.10:47850/") into the origin the native command expects.
 * Returns null when it cannot be an address.
 */
export const normalizeLanHostAddress = (
    input: string,
    defaultPort: number = DEFAULT_LAN_HOST_PORT,
): string | null => {
    const trimmed = input.trim();

    if (trimmed === '' || /\s/u.test(trimmed)) {
        return null;
    }

    const withScheme = /^[a-z][a-z0-9+.-]*:\/\//iu.test(trimmed)
        ? trimmed
        : `http://${trimmed}`;

    let url: URL;

    try {
        url = new URL(withScheme);
    } catch {
        return null;
    }

    if (
        !['http:', 'https:'].includes(url.protocol) ||
        url.hostname === '' ||
        url.username !== '' ||
        url.password !== ''
    ) {
        return null;
    }

    if (url.port === '' && url.protocol === 'http:') {
        url.port = String(defaultPort);
    }

    return `${url.protocol}//${url.host}/`;
};

/** "http://192.168.1.10:47850/" → "192.168.1.10". */
export const lanHostLabel = (url: string | null | undefined): string => {
    if (!url) {
        return '';
    }

    try {
        return new URL(url).hostname;
    } catch {
        return url;
    }
};

/** Native commands reject with a French string; keep it readable. */
export const readableNativeError = (
    error: unknown,
    fallback = 'L’opération a échoué.',
): string => {
    if (typeof error === 'string' && error.trim() !== '') {
        return error;
    }

    if (
        error instanceof Error &&
        typeof error.message === 'string' &&
        error.message.trim() !== ''
    ) {
        return error.message;
    }

    return fallback;
};
