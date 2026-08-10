import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/appointments/Index.vue'),
    'utf8',
);

const between = (start: string, end: string): string => {
    const startIndex = source.indexOf(start);
    const endIndex = source.indexOf(end, startIndex);

    expect(startIndex).toBeGreaterThanOrEqual(0);
    expect(endIndex).toBeGreaterThan(startIndex);

    return source.slice(startIndex, endIndex);
};

describe('appointment action placement', () => {
    it('keeps one gray common mobile sync action beside the booking action', () => {
        const toolbar = between(
            'aria-label="Imprimer les rendez-vous"',
            '<div\n                class="mt-7 grid',
        );

        expect(
            toolbar.match(/data-testid="appointments-mobile-sync"/g),
        ).toHaveLength(1);
        expect(toolbar).toContain('bg-slate-200');
        expect(toolbar).toContain('@click="syncMobileAppointments"');
        expect(toolbar).toContain('Nouveau rendez-vous');
        expect(toolbar.indexOf('appointments-mobile-sync')).toBeLessThan(
            toolbar.indexOf('Nouveau rendez-vous'),
        );
    });

    it('does not expose mobile or consultation actions in either appointment list', () => {
        const redesignedList = between(
            'data-testid="appointment-list-actions"',
            '</article>',
        );
        const legacyList = between('v-if="false"', '<!-- Booking modal:');

        for (const list of [redesignedList, legacyList]) {
            expect(list).not.toContain('syncMobileAppointment(');
            expect(list).not.toContain('startConsultation(appointment)');
            expect(list).not.toContain('Voir la consultation');
        }
    });

    it('reveals authorized consultation and confirmed cancellation actions from a waiting-room patient', () => {
        const waitingRoom = between(
            "Salle d'attente d'aujourd'hui",
            "La salle d'attente est vide.",
        );

        expect(source).toContain('appointment.date === props.today');
        expect(waitingRoom).toContain('data-testid="waiting-room-patient"');
        expect(waitingRoom).toContain('data-testid="waiting-room-actions"');
        expect(waitingRoom).toContain('canOpenWaitingConsultation');
        expect(waitingRoom).toContain('startConsultation(appointment)');
        expect(waitingRoom).toContain('permissions.cancel');
        expect(waitingRoom).toContain('openCancel(appointment)');
    });
});
