import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import DictationStatus from './DictationStatus.vue';

type Props = InstanceType<typeof DictationStatus>['$props'];

const render = (props: Partial<Props> = {}) =>
    mount(DictationStatus, {
        props: {
            supported: true,
            engine: 'recorder',
            listening: false,
            level: 0,
            testing: false,
            testResult: null,
            ...props,
        },
    });

describe('DictationStatus', () => {
    it('always shows the Windows dictation tip', () => {
        for (const supported of [true, false]) {
            const wrapper = render({ supported, engine: null });

            expect(
                wrapper.get('[data-testid="dictation-windows-hint"]').text(),
            ).toContain('Windows + H');
        }
    });

    it('names the engine', () => {
        expect(
            render({ engine: 'speech' })
                .get('[data-testid="dictation-engine"]')
                .text(),
        ).toBe('Reconnaissance vocale du navigateur');
        expect(
            render().get('[data-testid="dictation-engine"]').text(),
        ).toContain('transcrit par l’assistant IA');
    });

    it('offers the microphone check only while not listening', async () => {
        const wrapper = render();

        await wrapper
            .get('[data-testid="dictation-test-mic"]')
            .trigger('click');

        expect(wrapper.emitted('test')).toHaveLength(1);
        expect(
            render({ listening: true })
                .find('[data-testid="dictation-test-mic"]')
                .exists(),
        ).toBe(false);
        expect(
            render({ supported: false, engine: null })
                .find('[data-testid="dictation-test-mic"]')
                .exists(),
        ).toBe(false);
    });

    it('shows the live level while recording or testing', () => {
        expect(render().find('[data-testid="dictation-level"]').exists()).toBe(
            false,
        );

        const meter = render({ listening: true, level: 0.42 }).get(
            '[data-testid="dictation-level"]',
        );
        expect(meter.attributes('aria-valuenow')).toBe('42');
        expect(
            render({ testing: true, level: 3 })
                .get('[data-testid="dictation-level"]')
                .attributes('aria-valuenow'),
        ).toBe('100');
        expect(
            render({ engine: 'speech', listening: true })
                .find('[data-testid="dictation-level"]')
                .exists(),
        ).toBe(false);
    });

    it('reports the microphone check result', () => {
        expect(
            render({ testResult: 'ok' })
                .get('[data-testid="dictation-test-result"]')
                .text(),
        ).toContain('Micro OK');
        expect(
            render({ testResult: 'silent' })
                .get('[data-testid="dictation-test-result"]')
                .text(),
        ).toContain('aucun son');
    });
});
