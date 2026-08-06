import type {
    NativeLanConfigurationResult,
    NativeLanProvisioningState,
} from '@/types';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;
const hasExactKeys = (value: Record<string, unknown>, keys: string[]) =>
    Object.keys(value).length === keys.length &&
    keys.every((key) => Object.hasOwn(value, key));

const phases: NativeLanProvisioningState['phase'][] = [
    'active',
    'pending_attestation',
    'disabled',
    'stopped',
    'unavailable',
];
const reachabilityStates: NativeLanProvisioningState['local_reachability'][] = [
    'passed',
    'pending',
    'failed',
    'not_run',
];

export const isNativeLanProvisioningState = (
    value: unknown,
): value is NativeLanProvisioningState => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, [
            'schema_version',
            'requested_enabled',
            'requested_adapter_id',
            'requested_preferred_port',
            'diagnostics_requested',
            'phase',
            'verified',
            'verified_origin',
            'verified_adapter_id',
            'local_reachability',
            'firewall_assessment',
            'firewall_rules_modified',
            'error_code',
            'adapters',
        ]) ||
        !Array.isArray(value.adapters)
    ) {
        return false;
    }

    return (
        value.schema_version === 1 &&
        typeof value.requested_enabled === 'boolean' &&
        (value.requested_adapter_id === null ||
            typeof value.requested_adapter_id === 'string') &&
        (value.requested_preferred_port === null ||
            (Number.isInteger(value.requested_preferred_port) &&
                Number(value.requested_preferred_port) >= 1024 &&
                Number(value.requested_preferred_port) <= 65535)) &&
        typeof value.diagnostics_requested === 'boolean' &&
        phases.includes(value.phase as NativeLanProvisioningState['phase']) &&
        typeof value.verified === 'boolean' &&
        (value.verified_origin === null ||
            typeof value.verified_origin === 'string') &&
        (value.verified_adapter_id === null ||
            typeof value.verified_adapter_id === 'string') &&
        reachabilityStates.includes(
            value.local_reachability as NativeLanProvisioningState['local_reachability'],
        ) &&
        value.firewall_assessment === 'not_determined' &&
        value.firewall_rules_modified === false &&
        (value.error_code === null || typeof value.error_code === 'string') &&
        value.adapters.every(
            (adapter) =>
                isRecord(adapter) &&
                hasExactKeys(adapter, ['id', 'label', 'address', 'index']) &&
                typeof adapter.id === 'string' &&
                /^adapter-v1:[a-f0-9]{64}$/.test(adapter.id) &&
                typeof adapter.label === 'string' &&
                adapter.label.length > 0 &&
                typeof adapter.address === 'string' &&
                typeof adapter.index === 'number' &&
                Number.isInteger(adapter.index) &&
                adapter.index >= 0,
        )
    );
};

export const normalizeNativeLanConfigurationResult = (
    value: unknown,
): NativeLanConfigurationResult | null => {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['message_fr', 'state']) ||
        typeof value.message_fr !== 'string' ||
        value.message_fr.trim() === '' ||
        !isNativeLanProvisioningState(value.state)
    ) {
        return null;
    }

    return {
        message_fr: value.message_fr,
        state: value.state,
    };
};

export const nativeLanCommandFailure = (
    error: unknown,
): { message: string; state: NativeLanProvisioningState | null } => {
    if (isRecord(error)) {
        return {
            message:
                typeof error.message_fr === 'string' &&
                error.message_fr.trim() !== ''
                    ? error.message_fr
                    : 'Le listener LAN natif reste fermé. Vérifiez la carte réseau et le port, puis réessayez.',
            state: isNativeLanProvisioningState(error.state)
                ? error.state
                : null,
        };
    }

    return {
        message:
            'Le listener LAN natif reste fermé. Vérifiez la carte réseau et le port, puis réessayez.',
        state: null,
    };
};
