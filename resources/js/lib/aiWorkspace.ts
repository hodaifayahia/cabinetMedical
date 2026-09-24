// Shared state between the consultation panels and the AI copilot.
//
// The bilan and ordonnance panels are only mounted while their tab is open,
// so the copilot cannot reach into them directly. Instead: the panels publish
// what is currently on the bilan/ordonnance (so the copilot knows), and the
// copilot queues what the doctor accepted (so the panels pick it up).

import { reactive } from 'vue';

export type QueuedMedication = {
    medication: string;
    dosage: string;
    duration: string;
    instructions: string;
};

export const aiWorkspace = reactive({
    consultationId: null as number | null,
    /** Exam names on the bilan being prepared. */
    bilanExams: [] as string[],
    /** Medications on the ordonnance being prepared. */
    ordonnanceItems: [] as string[],
    queuedExams: [] as { exam_id: number | null; name: string }[],
    queuedMedications: [] as QueuedMedication[],
    queuedAdvice: [] as string[],
});

/** Forget everything when another consultation opens. */
export const bindAiWorkspace = (consultationId: number): void => {
    if (aiWorkspace.consultationId === consultationId) {
        return;
    }

    aiWorkspace.consultationId = consultationId;
    aiWorkspace.bilanExams = [];
    aiWorkspace.ordonnanceItems = [];
    aiWorkspace.queuedExams = [];
    aiWorkspace.queuedMedications = [];
    aiWorkspace.queuedAdvice = [];
};

/** Take (and clear) what the copilot queued for a panel. */
export const takeQueued = <
    K extends 'queuedExams' | 'queuedMedications' | 'queuedAdvice',
>(
    key: K,
): (typeof aiWorkspace)[K] => {
    const items = [...aiWorkspace[key]] as (typeof aiWorkspace)[K];
    aiWorkspace[key].splice(0);

    return items;
};
