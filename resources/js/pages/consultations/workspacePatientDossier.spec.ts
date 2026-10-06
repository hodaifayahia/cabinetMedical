import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const workspace = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/consultations/Workspace.vue'),
    'utf8',
);

describe('consultation workspace patient dossier', () => {
    it('queues a dossier save behind the one in flight instead of dropping it', () => {
        expect(workspace).toContain('patientSaveQueued = true');
        expect(workspace).not.toContain(
            'if (!patientForm.processing) {\n            savePatient();',
        );
        expect(workspace).toMatch(
            /onFinish: \(\) => \{\s+if \(patientSaveQueued/,
        );
    });

    it('saves the dossier without interrupting the consultation save', () => {
        expect(workspace).toMatch(
            /patientForm\.put\(`\/app\/consultations\/\$\{props\.consultation\.id\}\/patient`, \{\s+preserveScroll: true,\s+async: true,/,
        );
    });

    it('shows why the dossier was not saved', () => {
        expect(workspace).toContain('data-testid="patient-save-error"');
        expect(workspace).toContain('patientForm.errors.allergies');
        expect(workspace).toContain(':maxlength="PATIENT_HISTORY_MAX"');
    });

    it('only restores a local draft that is newer than the server copy', () => {
        expect(workspace).toContain('patientBasedOn: props.patient.updated_at');
        expect(workspace).toContain('draftIsCurrent(');
        expect(workspace).toContain("clearOfflineDraftPart('patient')");
        expect(workspace).toContain("clearOfflineDraftPart('consultation')");
    });

    it('surfaces the relatives’ diseases during the consultation', () => {
        expect(workspace).toContain('familyMedical: FamilyMedicalRelative[]');
        expect(workspace).toContain('Antécédents familiaux détectés');
        expect(workspace).toContain('data-testid="family-history-notice"');
        expect(workspace).toContain('formatRelativeFinding(relative)');
        expect(workspace).toContain('<FamilyFindings');
        expect(workspace).toContain('data-testid="workspace-family-history"');
    });
});
