export type DocumentTemplate = {
    key: string;
    category: 'courrier' | 'bilan' | 'ordonnance';
    group: string;
    title: string;
    // Body HTML. {{var}} placeholders are substituted + highlighted by DocumentEditor.
    body: string;
};

const boxed = (title: string): string =>
    `<p style="text-align:center;margin:16px 0"><span style="display:inline-block;border:1px solid #111;padding:7px 22px;font-weight:700;font-size:15px;letter-spacing:1px">${title}</span></p>`;

const underline = (title: string): string =>
    `<p style="text-align:center;margin:16px 0;font-weight:700;font-size:16px;text-decoration:underline">${title}</p>`;

const blank =
    '<u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u>';
const sign = `<p>Dr {{doctor_name}}</p>`;

export const documentTemplates: DocumentTemplate[] = [
    {
        key: 'arret-travail',
        category: 'courrier',
        group: 'Arrêt de travail',
        title: 'Arret de travail',
        body:
            underline('ARRET DE TRAVAIL') +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, prescris un arret de travail a : {{patient_name}}, ne(e) le {{dob}}, age(e) de {{age}} ans.</p>` +
            `<p>Duree : {{duree}} jour(s), a compter du {{date}}.</p>` +
            `<p>Motif : {{diagnostic}}</p>` +
            `<p>{{traitement}}</p>` +
            `<p>Arret de travail etabli a la demande de l'interesse(e) pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-aptitude',
        category: 'courrier',
        group: 'Certificats',
        title: "Certificat d'aptitude",
        body:
            underline("CERTIFICAT D'APTITUDE") +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie avoir examine ce jour : {{patient_name}}, ne(e) le {{dob}}, age(e) de {{age}} ans.</p>` +
            `<p>A l'issue de cet examen, je certifie que {{le_patient}} est apte a :</p>` +
            `<p>${blank}</p><p>{{diagnostic}}</p>` +
            `<p>Certificat etabli a la demande de l'interesse(e) pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-bonne-sante',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat de bonne sante',
        body:
            underline('CERTIFICAT DE BONNE SANTE') +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie avoir examine ce jour : {{patient_name}}, ne(e) le {{dob}}, age(e) de {{age}} ans.</p>` +
            `<p>A l'issue de cet examen clinique, je certifie que {{le_patient}} est en bonne sante apparente et ne presente pas de contre-indication medicale connue.</p>` +
            `<p>{{diagnostic}}</p>` +
            `<p>Certificat etabli a la demande de l'interesse(e) pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-dispense-sportive',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat de dispense sportive',
        body:
            boxed("CERTIFICAT MEDICAL DE DISPENSE DE L'ACTIVITE SPORTIVE") +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie que :</p>` +
            `<p>{{le_patient}} : .... {{patient_name}} ....</p>` +
            `<p>Ne(e) le : ............ {{dob}} ........</p>` +
            `<p>est suivi(e) en cardiologie, dont son etat de sante lui dispense de la pratique d'une activite sportive durant l'annee scolaire {{annee_scolaire}}.</p>` +
            `<p>Certificat delivre a la demande de {{interesse}} pour lui servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-grossesse',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat de grossesse',
        body:
            underline('CERTIFICAT DE GROSSESSE') +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie que :</p>` +
            `<p>{{patient_name}}, nee {{dob}}, agee de {{age}} ans,</p>` +
            `<p>est enceinte de <u>&nbsp;&nbsp;&nbsp;</u> semaines d'amenorrhee, soit une grossesse debutee approximativement le ${blank}.</p>` +
            `<p>Date presumee d'accouchement : ${blank}</p>` +
            `<p>{{diagnostic}}</p>` +
            `<p>Certificat etabli a la demande de l'interessee pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-non-contre-indication',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat de non contre-indication',
        body:
            boxed(
                'CERTIFICAT MEDICAL DE NON CONTRE-INDICATION A LA PRATIQUE SPORTIVE',
            ) +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie avoir examine ce jour :</p>` +
            `<p>{{patient_name}}, ne(e) le {{dob}}.</p>` +
            `<p>Et ne pas avoir constate, ce jour, de signe clinique apparent contre-indiquant la pratique de l'activite physique et sportive.</p>` +
            `<p>Ce certificat est valable pour une duree de {{duree}}.</p>`,
    },
    {
        key: 'certificat-reprise',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat de reprise',
        body:
            underline('CERTIFICAT DE REPRISE') +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie que l'etat de sante de :</p>` +
            `<p>{{patient_name}}, age(e) de {{age}} ans,</p>` +
            `<p>lui permet de reprendre son activite professionnelle / scolaire / sportive a compter du {{date}}.</p>` +
            `<p>{{diagnostic}}</p>` +
            `<p>Certificat etabli a la demande de l'interesse(e) pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'certificat-medical-simple',
        category: 'courrier',
        group: 'Certificats',
        title: 'Certificat medical simple',
        body:
            boxed('CERTIFICAT MEDICAL') +
            `<p>Je soussigne, Dr {{doctor_name}}, {{specialty}}, certifie avoir examine ce jour :</p>` +
            `<p>{{patient_name}}, ne(e) le {{dob}}, age(e) de {{age}} ans.</p>` +
            `<p>{{diagnostic}}</p>` +
            `<p>Certificat delivre a l'interesse(e) pour servir et valoir ce que de droit.</p>`,
    },
    {
        key: 'compte-rendu-consultation',
        category: 'courrier',
        group: 'Comptes rendus',
        title: 'Compte rendu de consultation',
        body:
            underline('COMPTE RENDU DE CONSULTATION') +
            `<p>Patient : {{patient_name}} — {{age}} ans — ne(e) le {{dob}}</p>` +
            `<p><b>Motif de consultation :</b></p><p>{{diagnostic}}</p>` +
            `<p><b>Examen clinique :</b></p><p>${blank}</p>` +
            `<p><b>Traitement / Conduite a tenir :</b></p><p>{{traitement}}</p>` +
            `<p><b>Conclusion :</b></p><p>${blank}</p>`,
    },
    {
        key: 'courrier-medical',
        category: 'courrier',
        group: 'Courrier libre',
        title: 'Courrier medical',
        body:
            boxed('COURRIER MEDICAL') +
            `<p>Confraternellement,</p>` +
            `<p>Je vous adresse {{patient_name}} pour avis et prise en charge.</p>` +
            `<p>{{motif}}</p><p>{{conclusion}}</p>`,
    },
    {
        key: 'lettre-confrere',
        category: 'courrier',
        group: 'Courrier libre',
        title: 'Lettre au confrere',
        body:
            `<p style="text-align:right">{{date_longue}}</p>` +
            `<p>Cher Confrere,</p>` +
            `<p>Je vous adresse {{patient_name}}, age(e) de {{age}} ans, pour avis et prise en charge concernant :</p>` +
            `<p>{{diagnostic}}</p>` +
            `<p><b>Traitement actuel :</b></p><p>{{traitement}}</p>` +
            `<p>En esperant votre bienveillant concours, je vous adresse mes cordiales confraternelles salutations.</p>` +
            sign,
    },
    {
        key: 'ordonnance',
        category: 'ordonnance',
        group: 'Ordonnances',
        title: 'Ordonnance',
        body: boxed('ORDONNANCE') + `<p>{{traitement}}</p>`,
    },
    {
        key: 'lettre-orientation',
        category: 'courrier',
        group: "Lettre d'orientation",
        title: "Lettre d'orientation",
        body:
            `<p style="text-align:right">{{date_longue}}</p>` +
            `<p>Cher(e) Confrere(e),</p>` +
            `<p>Je vous adresse {{patient_name}}, ne(e) le {{dob}}, age(e) de {{age}} ans, pour prise en charge specialisee.</p>` +
            `<p><b>Motif d'orientation :</b></p><p>{{diagnostic}}</p>` +
            `<p><b>Antecedents / Traitement en cours :</b></p><p>{{traitement}}</p>` +
            `<p>En vous remerciant de bien vouloir prendre en charge ce patient, je reste a votre disposition pour tout renseignement complementaire.</p>` +
            `<p>Confraternellement,</p>` +
            sign,
    },
    {
        key: 'compte-rendu-cardio',
        category: 'courrier',
        group: 'Comptes rendus',
        title: 'Compte rendu cardiologique',
        body:
            boxed('COMPTE RENDU DE CONSULTATION CARDIOLOGIQUE') +
            `<p><b>Motif de consultation</b></p><p>{{diagnostic}}</p>` +
            `<p><b>Examen clinique</b></p><p>{{traitement}}</p>` +
            `<p><b>Conclusion et conduite a tenir</b></p>`,
    },
    {
        key: 'lettre-hospitalisation',
        category: 'courrier',
        group: 'Comptes rendus',
        title: "Lettre d'hospitalisation",
        body:
            boxed("LETTRE D'HOSPITALISATION") +
            `<p>Cher Confrere,</p>` +
            `<p>Je vous adresse {{patient_name}}, age(e) de {{age}} ans, pour prise en charge de :</p>` +
            `<p>{{diagnostic}}</p><p>{{traitement}}</p>`,
    },
    {
        key: 'rapport-ecg',
        category: 'courrier',
        group: 'Comptes rendus',
        title: 'Rapport ECG',
        body:
            boxed("RAPPORT D'ELECTROCARDIOGRAMME") +
            `<p><b>Interpretation</b></p><p>{{diagnostic}}</p>` +
            `<p><b>Conclusion</b></p><p>{{traitement}}</p>`,
    },
    {
        key: 'rapport-echo',
        category: 'courrier',
        group: 'Comptes rendus',
        title: 'Rapport echocardiographie',
        body:
            boxed("RAPPORT D'ECHOCARDIOGRAPHIE") +
            `<p><b>Resultats</b></p><p>{{diagnostic}}</p>` +
            `<p><b>Conclusion</b></p><p>{{traitement}}</p>`,
    },
    {
        key: 'bilan',
        category: 'bilan',
        group: 'Bilans',
        title: 'Bilan',
        body: boxed('BILAN') + `<p>{{examens}}</p>`,
    },
];
