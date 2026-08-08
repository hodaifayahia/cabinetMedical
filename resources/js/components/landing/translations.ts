import { computed, onMounted, ref } from 'vue';

/**
 * Self-contained trilingual copy for the public landing page.
 *
 * There is no i18n library in this project, so the landing page ships its own
 * small reactive translation layer. The three supported locales cover the
 * Algerian medical market: Arabic (default, RTL), French and English. Every
 * string below is written natively in each language rather than machine
 * translated word-for-word.
 */
export type LandingLocale = 'ar' | 'fr' | 'en';

export const LANDING_LOCALES: readonly LandingLocale[] = ['ar', 'fr', 'en'];

const STORAGE_KEY = 'medismart.landing.locale';

type Benefit = { title: string; body: string };
type Step = { title: string; body: string };
type Role = { title: string; body: string; points: string[] };

type LandingCopy = {
    localeLabel: string;
    localeShort: string;
    switcherLabel: string;
    nav: {
        features: string;
        how: string;
        roles: string;
        requirements: string;
        contact: string;
    };
    download: {
        cta: string;
        unavailable: string;
        note: string;
    };
    tagline: string;
    hero: {
        eyebrow: string;
        title: string;
        subtitle: string;
        highlights: string[];
    };
    mockup: {
        sidebar: string[];
        agendaTitle: string;
        slots: { time: string; name: string; status: string }[];
    };
    benefits: {
        eyebrow: string;
        title: string;
        subtitle: string;
        items: Benefit[];
    };
    how: {
        eyebrow: string;
        title: string;
        subtitle: string;
        steps: Step[];
    };
    roles: {
        eyebrow: string;
        title: string;
        subtitle: string;
        items: Role[];
    };
    requirements: {
        eyebrow: string;
        title: string;
        subtitle: string;
        items: string[];
    };
    footer: {
        blurb: string;
        contactTitle: string;
        phoneLabel: string;
        phoneValue: string;
        emailLabel: string;
        emailValue: string;
        hoursLabel: string;
        hoursValue: string;
        rights: string;
    };
};

export const translations: Record<LandingLocale, LandingCopy> = {
    ar: {
        localeLabel: 'العربية',
        localeShort: 'ع',
        switcherLabel: 'تغيير لغة الصفحة',
        nav: {
            features: 'المميزات',
            how: 'طريقة العمل',
            roles: 'الفريق',
            requirements: 'المتطلبات',
            contact: 'اتصل بنا',
        },
        download: {
            cta: 'تحميل النسخة لويندوز',
            unavailable: 'التحميل غير متوفّر حاليًا',
            note: 'ملف تثبيت واحد لأجهزة ويندوز. لا حاجة لأي إعداد معقّد.',
        },
        tagline: 'برنامج تسيير العيادات الطبية',
        hero: {
            eyebrow: 'برنامج مكتبي للعيادات في الجزائر',
            title: 'تحكّم كامل في عيادتك، من تطبيق واحد.',
            subtitle:
                'المرضى، المواعيد، الاستشارات والوصفات الطبية في مكان واحد. برنامج مكتبي مصمّم للطبيب والسكرتارية، مع ثلاثة حسابات لكل عيادة وبيانات محفوظة بأمان.',
            highlights: [
                'ملف طبي كامل لكل مريض',
                'أجندة مواعيد واضحة',
                'وصفات وشهادات في نقرة واحدة',
            ],
        },
        mockup: {
            sidebar: ['لوحة التحكم', 'المرضى', 'المواعيد', 'الاستشارات', 'الوصفات'],
            agendaTitle: 'مواعيد اليوم',
            slots: [
                { time: '08:30', name: 'أمينة بن علي', status: 'مؤكّد' },
                { time: '09:15', name: 'كريم حدّاد', status: 'في الانتظار' },
                { time: '10:00', name: 'سامية مرزوق', status: 'مؤكّد' },
            ],
        },
        benefits: {
            eyebrow: 'كل ما تحتاجه العيادة',
            title: 'مصمّم لطريقة عملك اليومية',
            subtitle:
                'ميزات ملموسة تختصر الوقت وتحافظ على تنظيم ملفاتك، من الاستقبال إلى نهاية الاستشارة.',
            items: [
                {
                    title: 'ملف طبي كامل للمريض',
                    body: 'السوابق، الحساسية، القياسات والوثائق مجمّعة في ملف واحد يسهل الرجوع إليه.',
                },
                {
                    title: 'أجندة مواعيد ذكية',
                    body: 'نظّم المواعيد حسب اليوم أو الأسبوع، وتابع الحضور والإلغاء دون فوضى.',
                },
                {
                    title: 'وصفات ووثائق في نقرة',
                    body: 'أنشئ الوصفات والشهادات والرسائل الطبية واطبعها فورًا بترويسة عيادتك.',
                },
                {
                    title: 'استشارات بتاريخ كامل',
                    body: 'كل استشارة محفوظة مع تفاصيلها، فترى مسار المريض كاملًا في أي وقت.',
                },
                {
                    title: 'ثلاثة حسابات لكل عيادة',
                    body: 'اعمل مع فريقك بأدوار واضحة للطبيب والسكرتارية على نفس العيادة.',
                },
                {
                    title: 'بيانات مركزية وآمنة',
                    body: 'بياناتك محفوظة ومؤمّنة مع نسخ احتياطي، تبقى ملكًا لعيادتك وحدها.',
                },
            ],
        },
        how: {
            eyebrow: 'البداية بسيطة',
            title: 'من التحميل إلى أول استشارة في ثلاث خطوات',
            subtitle: 'لا تحتاج إلى خبرة تقنية. التثبيت مباشر والتفعيل سريع.',
            steps: [
                {
                    title: 'حمّل التطبيق',
                    body: 'نزّل ملف التثبيت لويندوز وثبّته على جهاز الاستقبال أو مكتب الطبيب.',
                },
                {
                    title: 'أنشئ عيادتك',
                    body: 'أكمل معالج إنشاء العيادة داخل التطبيق. يتم التفعيل خلال 24 ساعة.',
                },
                {
                    title: 'ابدأ استشاراتك',
                    body: 'سجّل المرضى، افتح المواعيد وابدأ الاستشارات في نفس اليوم.',
                },
            ],
        },
        roles: {
            eyebrow: 'لكل دوره',
            title: 'الطبيب والسكرتارية على نفس العيادة',
            subtitle:
                'صلاحيات واضحة لكل مستخدم، حتى يركّز كلٌّ على مهامه دون تداخل.',
            items: [
                {
                    title: 'الطبيب',
                    body: 'كل ما يخصّ الجانب الطبي في مكان واحد.',
                    points: [
                        'إجراء الاستشارات وتدوين الملاحظات',
                        'إنشاء الوصفات والوثائق الطبية',
                        'الاطّلاع على التاريخ الكامل للمريض',
                    ],
                },
                {
                    title: 'السكرتارية',
                    body: 'إدارة سلسة للاستقبال والمواعيد.',
                    points: [
                        'تسجيل المرضى وتحديث بياناتهم',
                        'برمجة المواعيد ومتابعة الحضور',
                        'تنظيم أجندة اليوم للطبيب',
                    ],
                },
            ],
        },
        requirements: {
            eyebrow: 'متطلبات التشغيل',
            title: 'يعمل على أجهزة العيادة العادية',
            subtitle: 'لا يحتاج إلى تجهيزات خاصة.',
            items: [
                'نظام ويندوز 10 أو 11 (64 بت)',
                'اتصال بالإنترنت مطلوب للتفعيل والمزامنة',
                'ملف تثبيت واحد، دون إعداد خادم معقّد',
            ],
        },
        footer: {
            blurb: 'MediSmart — برنامج مكتبي لتسيير العيادات الطبية في الجزائر.',
            contactTitle: 'تواصل معنا',
            phoneLabel: 'الهاتف',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'البريد الإلكتروني',
            emailValue: 'contact@medismart.dz',
            hoursLabel: 'أوقات العمل',
            hoursValue: 'من الأحد إلى الخميس، 9:00 – 17:00',
            rights: 'MediSmart. جميع الحقوق محفوظة.',
        },
    },
    fr: {
        localeLabel: 'Français',
        localeShort: 'FR',
        switcherLabel: 'Changer la langue de la page',
        nav: {
            features: 'Fonctionnalités',
            how: 'Comment ça marche',
            roles: 'Équipe',
            requirements: 'Prérequis',
            contact: 'Contact',
        },
        download: {
            cta: 'Télécharger pour Windows',
            unavailable: 'Téléchargement indisponible',
            note: 'Un seul fichier d’installation Windows. Aucune configuration compliquée.',
        },
        tagline: 'Logiciel de gestion de cabinet médical',
        hero: {
            eyebrow: 'Application bureau pour cabinets en Algérie',
            title: 'Gérez tout votre cabinet depuis une seule application.',
            subtitle:
                'Patients, rendez-vous, consultations et ordonnances au même endroit. Une application bureau pensée pour le médecin et le secrétariat, avec trois comptes par cabinet et des données conservées en sécurité.',
            highlights: [
                'Dossier patient complet',
                'Agenda de rendez-vous clair',
                'Ordonnances et documents en un clic',
            ],
        },
        mockup: {
            sidebar: ['Tableau de bord', 'Patients', 'Rendez-vous', 'Consultations', 'Ordonnances'],
            agendaTitle: 'Rendez-vous du jour',
            slots: [
                { time: '08:30', name: 'Amina Ben Ali', status: 'Confirmé' },
                { time: '09:15', name: 'Karim Haddad', status: 'En attente' },
                { time: '10:00', name: 'Samia Merzouk', status: 'Confirmé' },
            ],
        },
        benefits: {
            eyebrow: 'Tout ce dont le cabinet a besoin',
            title: 'Conçu pour votre travail au quotidien',
            subtitle:
                'Des fonctionnalités concrètes qui font gagner du temps et gardent vos dossiers en ordre, de l’accueil à la fin de la consultation.',
            items: [
                {
                    title: 'Dossier patient complet',
                    body: 'Antécédents, allergies, mesures et documents réunis dans une fiche facile à consulter.',
                },
                {
                    title: 'Agenda de rendez-vous intelligent',
                    body: 'Organisez les rendez-vous par jour ou par semaine et suivez présences et annulations sans désordre.',
                },
                {
                    title: 'Ordonnances et documents en un clic',
                    body: 'Générez ordonnances, certificats et courriers, puis imprimez-les à l’en-tête de votre cabinet.',
                },
                {
                    title: 'Consultations avec historique complet',
                    body: 'Chaque consultation est enregistrée avec son détail : le parcours du patient reste visible à tout moment.',
                },
                {
                    title: '3 postes par cabinet avec rôles',
                    body: 'Travaillez à plusieurs avec des rôles clairs pour le médecin et le secrétariat, sur le même cabinet.',
                },
                {
                    title: 'Données centralisées et sécurisées',
                    body: 'Vos données sont regroupées, sécurisées et sauvegardées, et restent la propriété de votre cabinet.',
                },
            ],
        },
        how: {
            eyebrow: 'Le démarrage est simple',
            title: 'Du téléchargement à la première consultation en trois étapes',
            subtitle: 'Aucune compétence technique requise. Installation directe et activation rapide.',
            steps: [
                {
                    title: 'Téléchargez l’application',
                    body: 'Récupérez le fichier d’installation Windows et installez-le sur le poste d’accueil ou du médecin.',
                },
                {
                    title: 'Créez votre cabinet',
                    body: 'Suivez l’assistant de création du cabinet dans l’application. L’activation se fait sous 24 h.',
                },
                {
                    title: 'Commencez vos consultations',
                    body: 'Enregistrez vos patients, ouvrez l’agenda et démarrez les consultations le jour même.',
                },
            ],
        },
        roles: {
            eyebrow: 'Chacun son rôle',
            title: 'Médecin et secrétariat sur le même cabinet',
            subtitle:
                'Des accès clairs pour chaque utilisateur, afin que chacun se concentre sur ses tâches sans se marcher dessus.',
            items: [
                {
                    title: 'Médecin',
                    body: 'Tout le volet médical réuni au même endroit.',
                    points: [
                        'Mener les consultations et saisir les notes',
                        'Créer ordonnances et documents médicaux',
                        'Consulter l’historique complet du patient',
                    ],
                },
                {
                    title: 'Secrétariat',
                    body: 'Une gestion fluide de l’accueil et des rendez-vous.',
                    points: [
                        'Enregistrer les patients et mettre à jour leurs données',
                        'Planifier les rendez-vous et suivre les présences',
                        'Organiser l’agenda du jour du médecin',
                    ],
                },
            ],
        },
        requirements: {
            eyebrow: 'Configuration requise',
            title: 'Fonctionne sur les postes habituels du cabinet',
            subtitle: 'Aucun matériel particulier nécessaire.',
            items: [
                'Windows 10 ou 11 (64 bits)',
                'Connexion Internet requise pour l’activation et la synchronisation',
                'Un seul fichier d’installation, sans serveur à configurer',
            ],
        },
        footer: {
            blurb: 'MediSmart — logiciel bureau de gestion de cabinet médical en Algérie.',
            contactTitle: 'Nous contacter',
            phoneLabel: 'Téléphone',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'E-mail',
            emailValue: 'contact@medismart.dz',
            hoursLabel: 'Horaires',
            hoursValue: 'Dimanche à jeudi, 9h00 – 17h00',
            rights: 'MediSmart. Tous droits réservés.',
        },
    },
    en: {
        localeLabel: 'English',
        localeShort: 'EN',
        switcherLabel: 'Change page language',
        nav: {
            features: 'Features',
            how: 'How it works',
            roles: 'Team',
            requirements: 'Requirements',
            contact: 'Contact',
        },
        download: {
            cta: 'Download for Windows',
            unavailable: 'Download unavailable',
            note: 'A single Windows installer. No complicated setup required.',
        },
        tagline: 'Medical practice management software',
        hero: {
            eyebrow: 'Desktop app for medical practices in Algeria',
            title: 'Run your whole practice from a single app.',
            subtitle:
                'Patients, appointments, consultations and prescriptions in one place. A desktop app built for the doctor and the front desk, with three accounts per practice and data kept safely.',
            highlights: [
                'Complete patient record',
                'Clear appointment agenda',
                'Prescriptions and documents in one click',
            ],
        },
        mockup: {
            sidebar: ['Dashboard', 'Patients', 'Appointments', 'Consultations', 'Prescriptions'],
            agendaTitle: 'Today’s appointments',
            slots: [
                { time: '08:30', name: 'Amina Ben Ali', status: 'Confirmed' },
                { time: '09:15', name: 'Karim Haddad', status: 'Waiting' },
                { time: '10:00', name: 'Samia Merzouk', status: 'Confirmed' },
            ],
        },
        benefits: {
            eyebrow: 'Everything the practice needs',
            title: 'Built around your daily work',
            subtitle:
                'Concrete features that save time and keep your records in order, from the front desk to the end of the consultation.',
            items: [
                {
                    title: 'Complete patient record',
                    body: 'History, allergies, measurements and documents gathered in one record that is easy to review.',
                },
                {
                    title: 'Smart appointment agenda',
                    body: 'Organise appointments by day or week and track attendance and cancellations without the mess.',
                },
                {
                    title: 'Prescriptions and documents in one click',
                    body: 'Generate prescriptions, certificates and letters, then print them with your practice letterhead.',
                },
                {
                    title: 'Consultations with full history',
                    body: 'Every consultation is saved with its detail, so the patient’s journey stays visible at any time.',
                },
                {
                    title: '3 seats per practice with roles',
                    body: 'Work as a team with clear roles for the doctor and the front desk on the same practice.',
                },
                {
                    title: 'Centralised, secure data',
                    body: 'Your data is centralised, secured and backed up, and stays the property of your practice.',
                },
            ],
        },
        how: {
            eyebrow: 'Getting started is simple',
            title: 'From download to first consultation in three steps',
            subtitle: 'No technical skills needed. Straightforward install and fast activation.',
            steps: [
                {
                    title: 'Download the app',
                    body: 'Get the Windows installer and install it on the front-desk or doctor’s computer.',
                },
                {
                    title: 'Create your practice',
                    body: 'Follow the practice setup wizard inside the app. Activation is completed within 24 hours.',
                },
                {
                    title: 'Start your consultations',
                    body: 'Register your patients, open the agenda and start consultations the same day.',
                },
            ],
        },
        roles: {
            eyebrow: 'A role for everyone',
            title: 'Doctor and front desk on the same practice',
            subtitle:
                'Clear access for each user, so everyone focuses on their own tasks without stepping on each other.',
            items: [
                {
                    title: 'Doctor',
                    body: 'The whole clinical side gathered in one place.',
                    points: [
                        'Run consultations and record notes',
                        'Create prescriptions and medical documents',
                        'Review the patient’s full history',
                    ],
                },
                {
                    title: 'Front desk',
                    body: 'Smooth handling of reception and appointments.',
                    points: [
                        'Register patients and update their details',
                        'Schedule appointments and track attendance',
                        'Organise the doctor’s daily agenda',
                    ],
                },
            ],
        },
        requirements: {
            eyebrow: 'System requirements',
            title: 'Runs on the practice’s usual computers',
            subtitle: 'No special hardware required.',
            items: [
                'Windows 10 or 11 (64-bit)',
                'Internet connection required for activation and sync',
                'A single installer, with no server to configure',
            ],
        },
        footer: {
            blurb: 'MediSmart — desktop software for managing medical practices in Algeria.',
            contactTitle: 'Contact us',
            phoneLabel: 'Phone',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'Email',
            emailValue: 'contact@medismart.dz',
            hoursLabel: 'Hours',
            hoursValue: 'Sunday to Thursday, 9:00 – 17:00',
            rights: 'MediSmart. All rights reserved.',
        },
    },
};

function isLandingLocale(value: unknown): value is LandingLocale {
    return typeof value === 'string' && (LANDING_LOCALES as readonly string[]).includes(value);
}

/**
 * Reactive locale state for the landing page. Persists the chosen locale in
 * localStorage and keeps the document `dir`/`lang` attributes in sync so the
 * whole page flips to RTL when Arabic is selected.
 */
export function useLandingLocale() {
    const locale = ref<LandingLocale>('ar');

    const dir = computed<'rtl' | 'ltr'>(() => (locale.value === 'ar' ? 'rtl' : 'ltr'));

    const copy = computed<LandingCopy>(() => translations[locale.value]);

    function applyDocumentAttributes(): void {
        if (typeof document === 'undefined') {
            return;
        }

        document.documentElement.setAttribute('lang', locale.value);
        document.documentElement.setAttribute('dir', dir.value);
    }

    function setLocale(next: LandingLocale): void {
        locale.value = next;

        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Ignore storage failures (private mode, disabled storage, …).
        }

        applyDocumentAttributes();
    }

    onMounted(() => {
        let stored: string | null = null;

        try {
            stored = window.localStorage.getItem(STORAGE_KEY);
        } catch {
            stored = null;
        }

        if (isLandingLocale(stored)) {
            locale.value = stored;
        }

        applyDocumentAttributes();
    });

    return { locale, dir, copy, setLocale };
}
