import { invoke, isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LanJoinCard from '@/components/desktop/LanJoinCard.vue';

vi.mock('@tauri-apps/api/core', () => ({
    invoke: vi.fn(),
    isTauri: vi.fn(),
}));

const mockedInvoke = vi.mocked(invoke);
const mockedIsTauri = vi.mocked(isTauri);

const hostStatus = (running: boolean) => ({
    mode: 'local',
    available: true,
    enabled: running,
    running,
    starting: false,
    port: 47850,
    default_port: 47850,
    discovery_port: 47851,
    computer_name: 'CABINET-PC',
    addresses: [
        {
            address: '192.168.1.10',
            interface: 'Ethernet',
            url: 'http://192.168.1.10:47850/',
        },
    ],
    name_url: 'http://cabinet-pc:47850/',
    error: null,
    attached_to: null,
});

const bridge = (handlers: Record<string, (args?: unknown) => unknown>) => {
    mockedInvoke.mockImplementation(((command: string, args?: unknown) => {
        const handler = handlers[command];

        return handler
            ? Promise.resolve(handler(args))
            : Promise.reject(new Error(`unexpected ${command}`));
    }) as typeof invoke);
};

describe('LanJoinCard', () => {
    beforeEach(() => {
        mockedInvoke.mockReset();
        mockedIsTauri.mockReset();
        mockedIsTauri.mockReturnValue(true);
    });

    it('renders nothing in a browser', async () => {
        mockedIsTauri.mockReturnValue(false);

        const wrapper = mount(LanJoinCard);
        await flushPromises();

        expect(wrapper.html()).not.toContain('section');
        expect(mockedInvoke).not.toHaveBeenCalled();
    });

    it('shows the poste principal and offers to go back on an attached PC', async () => {
        bridge({
            runtime_mode_status: () => ({
                mode: 'attach',
                url: 'http://192.168.1.10:47850/',
                local_error: null,
            }),
            use_local_mode: () => true,
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = mount(LanJoinCard);
        await flushPromises();

        expect(wrapper.get('[data-test="lan-attached-card"]').text()).toContain(
            '192.168.1.10',
        );

        await wrapper.get('[data-test="lan-back-to-local"]').trigger('click');
        await flushPromises();

        expect(mockedInvoke).toHaveBeenCalledWith('use_local_mode');
        expect(wrapper.text()).toContain('Drclick redémarre');
    });

    it('joins a discovered poste principal from a PC that owns its data', async () => {
        bridge({
            runtime_mode_status: () => ({
                mode: 'local',
                url: 'http://127.0.0.1:51234/',
                local_error: null,
            }),
            lan_host_status: () => hostStatus(false),
            discover_lan_hosts: () => [
                {
                    name: 'CABINET-PC',
                    address: '192.168.1.10',
                    port: 47850,
                    url: 'http://192.168.1.10:47850/',
                    version: '0.3.0',
                },
            ],
            connect_to_lan_host: () => ({ url: 'http://192.168.1.10:47850/' }),
        });

        const wrapper = mount(LanJoinCard);
        await flushPromises();

        await wrapper.get('[data-test="lan-join-open"]').trigger('click');
        await wrapper.get('[data-test="lan-search"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="lan-found-host"]').trigger('click');
        await flushPromises();

        expect(mockedInvoke).toHaveBeenCalledWith('connect_to_lan_host', {
            url: 'http://192.168.1.10:47850/',
        });
        expect(wrapper.text()).toContain('Drclick redémarre');
    });

    it('accepts a typed address and reports a native refusal', async () => {
        bridge({
            runtime_mode_status: () => ({
                mode: 'local',
                url: null,
                local_error: null,
            }),
            lan_host_status: () => hostStatus(false),
            connect_to_lan_host: () => {
                throw 'Le poste principal ne répond pas.';
            },
        });

        const wrapper = mount(LanJoinCard);
        await flushPromises();
        await wrapper.get('[data-test="lan-join-open"]').trigger('click');
        await wrapper.get('#lan-host-address').setValue('192.168.1.10');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mockedInvoke).toHaveBeenCalledWith('connect_to_lan_host', {
            url: 'http://192.168.1.10:47850/',
        });
        expect(wrapper.get('[role="alert"]').text()).toBe(
            'Le poste principal ne répond pas.',
        );
    });

    it('shows the address to type when this PC is already sharing', async () => {
        bridge({
            runtime_mode_status: () => ({
                mode: 'local',
                url: null,
                local_error: null,
            }),
            lan_host_status: () => hostStatus(true),
        });

        const wrapper = mount(LanJoinCard);
        await flushPromises();

        expect(wrapper.get('[data-test="lan-sharing-card"]').text()).toContain(
            'http://192.168.1.10:47850/',
        );
    });
});
