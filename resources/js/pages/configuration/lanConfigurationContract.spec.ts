import { describe, expect, it } from 'vitest';
import {
    isNativeLanProvisioningState,
    nativeLanCommandFailure,
    normalizeNativeLanConfigurationResult,
} from './lanConfigurationContract';

const validState = {
    schema_version: 1,
    requested_enabled: true,
    requested_adapter_id: `adapter-v1:${'a'.repeat(64)}`,
    requested_preferred_port: 43124,
    diagnostics_requested: true,
    phase: 'pending_attestation',
    verified: false,
    verified_origin: 'http://192.168.1.10:43124',
    verified_adapter_id: `adapter-v1:${'a'.repeat(64)}`,
    local_reachability: 'pending',
    firewall_assessment: 'not_determined',
    firewall_rules_modified: false,
    error_code: 'lan_boundary_not_attested',
    adapters: [
        {
            id: `adapter-v1:${'a'.repeat(64)}`,
            label: 'Wi-Fi',
            address: '192.168.1.10',
            index: 7,
        },
    ],
};

describe('native LAN configuration contract', () => {
    it('accepts the exact schema-v1 state and result', () => {
        expect(isNativeLanProvisioningState(validState)).toBe(true);
        expect(
            normalizeNativeLanConfigurationResult({
                message_fr: 'Vérification en cours.',
                state: validState,
            }),
        ).not.toBeNull();
    });

    it('rejects raw adapter identities and claimed firewall proof', () => {
        expect(
            isNativeLanProvisioningState({
                ...validState,
                adapters: [
                    {
                        ...validState.adapters[0],
                        id: 'AA:BB:CC:DD:EE:FF',
                    },
                ],
            }),
        ).toBe(false);
        expect(
            isNativeLanProvisioningState({
                ...validState,
                firewall_assessment: 'phone_reachable',
            }),
        ).toBe(false);
        expect(
            isNativeLanProvisioningState({
                ...validState,
                raw_mac: 'AA:BB:CC:DD:EE:FF',
            }),
        ).toBe(false);
    });

    it('preserves actionable structured native failures', () => {
        expect(
            nativeLanCommandFailure({
                code: 'lan_adapter_unavailable',
                message_fr: 'Carte indisponible.',
                state: { ...validState, phase: 'unavailable' },
            }),
        ).toEqual({
            message: 'Carte indisponible.',
            state: { ...validState, phase: 'unavailable' },
        });
    });
});
