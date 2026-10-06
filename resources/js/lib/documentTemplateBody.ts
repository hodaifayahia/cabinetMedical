/**
 * Template bodies come in two shapes:
 * - 'text': the historical line-based body ("## " starts a heading);
 * - 'html': a body written in the Word-like editor or imported from .docx.
 *
 * These helpers mirror app/ClinicalDocuments/TemplateBody.php so the
 * consultation preview and the generated Word file render the same way.
 */

export type TemplateBodyFormat = 'text' | 'html';

export const TEMPLATE_TOKEN_PATTERN = /\{\{\s*([a-z0-9_.]+)\s*\}\}/gi;

export const escapeHtml = (value: string | null | undefined): string =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

const HTML_START =
    /^\s*<(?:p|h[1-6]|div|table|ul|ol|blockquote|hr|img|span|strong|em|b|i|u|br|figure)\b/i;

/** Whether a body that carries no explicit format looks like HTML. */
export const isHtmlBody = (body: string | null | undefined): boolean =>
    HTML_START.test(body ?? '');

/**
 * Convert a legacy line-based body to HTML: "## " lines become <h3>,
 * consecutive lines form one paragraph (joined by <br>), blank lines
 * separate paragraphs. {{tokens}} are kept so they can be substituted later.
 */
export const legacyTextToHtml = (text: string | null | undefined): string => {
    const html: string[] = [];
    let paragraph: string[] = [];

    const flush = () => {
        if (paragraph.length > 0) {
            html.push('<p>' + paragraph.join('<br>') + '</p>');
            paragraph = [];
        }
    };

    for (const line of String(text ?? '').split(/\r\n|\r|\n/)) {
        const heading = /^##\s+(.+)$/.exec(line);

        if (heading) {
            flush();
            html.push('<h3>' + escapeHtml(heading[1].trim()) + '</h3>');
        } else if (line.trim() === '') {
            flush();
        } else {
            paragraph.push(escapeHtml(line));
        }
    }

    flush();

    return html.join('');
};

/** The body as HTML, whatever its stored (or detected) format. */
export const templateBodyToHtml = (
    body: string | null | undefined,
    format?: TemplateBodyFormat | string | null,
): string => {
    const source = String(body ?? '');
    const html = format === 'html' || (format !== 'text' && isHtmlBody(source));

    return html ? source : legacyTextToHtml(source);
};

/**
 * Replace {{tokens}} inside HTML. Values are escaped and their line breaks
 * become <br>. Unknown tokens render empty unless keepUnknown is set.
 */
export const substituteTemplateTokens = (
    html: string,
    values: Record<string, string | null | undefined>,
    { keepUnknown = false }: { keepUnknown?: boolean } = {},
): string =>
    html.replace(TEMPLATE_TOKEN_PATTERN, (match, key: string) => {
        if (!Object.prototype.hasOwnProperty.call(values, key)) {
            return keepUnknown ? match : '';
        }

        return escapeHtml(values[key] ?? '').replace(/\r\n|\r|\n/g, '<br>');
    });

export const renderTemplateHtml = (
    body: string | null | undefined,
    format: TemplateBodyFormat | string | null | undefined,
    values: Record<string, string | null | undefined>,
    options: { keepUnknown?: boolean } = {},
): string =>
    substituteTemplateTokens(templateBodyToHtml(body, format), values, options);

/** True when the HTML holds no visible text, image, table or rule. */
export const htmlIsEffectivelyEmpty = (
    html: string | null | undefined,
): boolean => {
    const source = String(html ?? '');

    if (/<(img|table|hr)\b/i.test(source)) {
        return false;
    }

    return (
        source
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;|&#160;/g, ' ')
            .trim() === ''
    );
};

/** dd/mm/yyyy from an ISO date (yyyy-mm-dd…); other strings pass through. */
export const formatDisplayDate = (date: string | null | undefined): string => {
    if (!date) {
        return '';
    }

    const [year, month, day] = String(date).slice(0, 10).split('-');

    return year && month && day ? day + '/' + month + '/' + year : String(date);
};

export const formatLongDate = (date: string | null | undefined): string => {
    if (!date) {
        return '';
    }

    const parsed = new Date(String(date).slice(0, 10) + 'T00:00:00');

    if (Number.isNaN(parsed.getTime())) {
        return String(date);
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(parsed);
};

export const ageFromBirthDate = (
    dateOfBirth: string | null | undefined,
    today: Date = new Date(),
): number | null => {
    if (!dateOfBirth) {
        return null;
    }

    const birth = new Date(String(dateOfBirth).slice(0, 10) + 'T00:00:00');

    if (Number.isNaN(birth.getTime())) {
        return null;
    }

    let age = today.getFullYear() - birth.getFullYear();
    const beforeBirthday =
        today.getMonth() < birth.getMonth() ||
        (today.getMonth() === birth.getMonth() &&
            today.getDate() < birth.getDate());

    if (beforeBirthday) {
        age -= 1;
    }

    return age >= 0 ? age : null;
};

export type TemplateValueSources = {
    patient: {
        full_name?: string | null;
        first_name?: string | null;
        last_name?: string | null;
        patient_number?: string | null;
        date_of_birth?: string | null;
        gender?: string | null;
        profession?: string | null;
        phone?: string | null;
        email?: string | null;
        address?: string | null;
        city?: string | null;
        blood_group?: string | null;
        allergies?: string | null;
        antecedents_medical?: string | null;
        antecedents_surgical?: string | null;
        antecedents_family?: string | null;
        antecedents_gyneco?: string | null;
        antecedents_other?: string | null;
    };
    consultation?: {
        motif?: string | null;
        examens?: string | null;
        diagnostic?: string | null;
        traitement?: string | null;
        notes?: string | null;
    } | null;
    cabinet?: {
        doctor_name?: string | null;
        specialty?: string | null;
        order_number?: string | null;
        clinic_name?: string | null;
        phone?: string | null;
        email?: string | null;
        address?: string | null;
        city?: string | null;
    } | null;
    /** ISO date (yyyy-mm-dd) printed as the document date. */
    documentDate: string;
};

/**
 * Every value a template may reference, keyed by token name. It covers the
 * tokens offered in Configuration › Modèles de documents plus the legacy
 * short aliases (doctor_name, diagnostic…) older bodies still use.
 */
export const buildTemplateValues = ({
    patient,
    consultation,
    cabinet,
    documentDate,
}: TemplateValueSources): Record<string, string> => {
    const text = (value: string | null | undefined): string =>
        String(value ?? '').trim();
    const age = ageFromBirthDate(patient.date_of_birth);
    const dob = formatDisplayDate(patient.date_of_birth);
    const date = formatDisplayDate(documentDate);
    const dateLong = formatLongDate(documentDate);
    const fullName = text(patient.full_name);
    const address = [cabinet?.address, cabinet?.city]
        .map(text)
        .filter(Boolean)
        .join(', ');

    return {
        'patient.full_name': fullName,
        'patient.name': fullName,
        'patient.first_name': text(patient.first_name),
        'patient.last_name': text(patient.last_name),
        'patient.patient_number': text(patient.patient_number),
        'patient.date_of_birth': dob,
        'patient.birth_date': dob,
        'patient.age': age === null ? '' : String(age),
        'patient.gender': text(patient.gender),
        'patient.profession': text(patient.profession),
        'patient.phone': text(patient.phone),
        'patient.email': text(patient.email),
        'patient.address': text(patient.address),
        'patient.city': text(patient.city),
        'patient.blood_group': text(patient.blood_group),
        'patient.allergies': text(patient.allergies),
        'patient.antecedents_medical': text(patient.antecedents_medical),
        'patient.antecedents_surgical': text(patient.antecedents_surgical),
        'patient.antecedents_family': text(patient.antecedents_family),
        'patient.antecedents_gyneco': text(patient.antecedents_gyneco),
        'patient.antecedents_other': text(patient.antecedents_other),
        'consultation.motif': text(consultation?.motif),
        'consultation.examens': text(consultation?.examens),
        'consultation.diagnostic': text(consultation?.diagnostic),
        'consultation.traitement': text(consultation?.traitement),
        'consultation.notes': text(consultation?.notes),
        'doctor.name': text(cabinet?.doctor_name),
        'doctor.specialty': text(cabinet?.specialty),
        'doctor.order_number': text(cabinet?.order_number),
        'cabinet.name': text(cabinet?.clinic_name),
        'cabinet.phone': text(cabinet?.phone),
        'cabinet.email': text(cabinet?.email),
        'cabinet.address': address,
        'cabinet.city': text(cabinet?.city),
        'document.date': date,
        'document.date_long': dateLong,
        // Legacy short aliases.
        patient_name: fullName,
        dob,
        age: age === null ? '' : String(age),
        doctor_name: text(cabinet?.doctor_name),
        specialty: text(cabinet?.specialty),
        date,
        date_longue: dateLong,
        motif: text(consultation?.motif),
        examens: text(consultation?.examens),
        diagnostic: text(consultation?.diagnostic),
        traitement: text(consultation?.traitement),
        notes: text(consultation?.notes),
    };
};

/** Fictitious values used by the template preview in Configuration. */
export const SAMPLE_TEMPLATE_VALUES: Record<string, string> =
    buildTemplateValues({
        patient: {
            full_name: 'Amine Bensalem',
            first_name: 'Amine',
            last_name: 'Bensalem',
            date_of_birth: '1980-04-12',
            allergies: 'Pénicilline',
            antecedents_medical: 'Hypertension artérielle',
            antecedents_surgical: 'Appendicectomie (2004)',
            antecedents_family: 'Père diabétique',
        },
        consultation: {
            motif: 'Douleurs thoraciques',
            examens: 'ECG, bilan lipidique',
            diagnostic: 'Angor stable',
            traitement: 'Aspirine 100 mg/j',
        },
        cabinet: {
            doctor_name: 'Dr Exemple',
            specialty: 'Cardiologie',
            clinic_name: 'Cabinet médical',
        },
        documentDate: new Date().toISOString().slice(0, 10),
    });
