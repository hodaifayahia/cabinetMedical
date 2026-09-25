import { computed, onMounted, onUnmounted, ref } from 'vue';

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

/** One reassurance under the hero (replaces the old bare number stats). */
type Assurance = { title: string; body: string };

/**
 * One screen of the product tour. `tab` is the short switcher label, `alt`
 * describes the screenshot for assistive technology. `shot` keys into the
 * screenshot files, so it is shared by every locale and never translated.
 */
type ShowcaseItem = {
    shot: ShowcaseShot;
    tab: string;
    title: string;
    body: string;
    alt: string;
};

export const SHOWCASE_SHOTS = [
    'consultation',
    'prescription',
    'booking',
    'appointments',
    'patients',
    'dashboard',
] as const;

export type ShowcaseShot = (typeof SHOWCASE_SHOTS)[number];

/**
 * The clinical AI showcase. Like the product tour, `shot` keys into
 * public/images/landing/ai and is shared by every locale.
 */
export const AI_SHOTS = [
    'copilot',
    'prescription',
    'visit',
    'ecg',
    'analysis',
] as const;

export type AiShot = (typeof AI_SHOTS)[number];

type AiShowcaseItem = {
    shot: AiShot;
    tab: string;
    title: string;
    body: string;
    alt: string;
};

/** Real captures of the patient app, in public/images/landing/mobile. */
export const MOBILE_SCREENS = ['search', 'booking', 'home'] as const;

export type MobileScreen = (typeof MOBILE_SCREENS)[number];

type MobileScreenCopy = { screen: MobileScreen; label: string; alt: string };

export type LandingCopy = {
    localeLabel: string;
    localeShort: string;
    switcherLabel: string;
    nav: {
        menuLabel: string;
        features: string;
        ai: string;
        tour: string;
        how: string;
        roles: string;
        requirements: string;
        contact: string;
    };
    download: {
        cta: string;
        /** Compact header label; the full `cta` stays on the page CTAs. */
        ctaShort: string;
        unavailable: string;
        note: string;
    };
    tagline: string;
    hero: {
        /** Link to the AI section, above the headline. */
        aiBadge: string;
        eyebrow: string;
        title: string;
        titleLead: string;
        titleRotating: string[];
        subtitle: string;
        highlights: string[];
        assurances: Assurance[];
    };
    photos: {
        documents: string;
        roles: string;
    };
    benefits: {
        eyebrow: string;
        title: string;
        subtitle: string;
        items: Benefit[];
    };
    showcase: {
        eyebrow: string;
        title: string;
        subtitle: string;
        hint: string;
        items: ShowcaseItem[];
    };
    ai: {
        eyebrow: string;
        /** Headline, then its highlighted second half. */
        title: string;
        titleAccent: string;
        subtitle: string;
        hint: string;
        items: AiShowcaseItem[];
        capabilitiesTitle: string;
        /** Every AI action the app offers, in the order of the icons. */
        capabilities: Benefit[];
        principlesTitle: string;
        principles: Benefit[];
        disclaimer: string;
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
    mobileApp: {
        badge: string;
        title: string;
        body: string;
        points: string[];
        screens: MobileScreenCopy[];
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
            menuLabel: 'فتح قائمة التنقل',
            features: 'المميزات',
            ai: 'الذكاء الاصطناعي',
            tour: 'جولة في التطبيق',
            how: 'طريقة العمل',
            roles: 'الفريق',
            requirements: 'المتطلبات',
            contact: 'اتصل بنا',
        },
        download: {
            cta: 'تحميل النسخة لويندوز',
            ctaShort: 'تحميل التطبيق',
            unavailable: 'التحميل غير متوفّر حاليًا',
            note: 'ملف تثبيت واحد لأجهزة ويندوز. لا حاجة لأي إعداد معقّد.',
        },
        tagline: 'برنامج تسيير العيادات الطبية',
        hero: {
            aiBadge: 'جديد: ذكاء اصطناعي طبي داخل كل استشارة',
            eyebrow: 'برنامج مكتبي للعيادات في الجزائر',
            title: 'تحكّم كامل في عيادتك، من تطبيق واحد.',
            titleLead: 'تحكّم كامل في عيادتك.',
            titleRotating: ['تطبيق واحد', 'مكان واحد', 'حياة أسهل'],
            subtitle:
                'المرضى، المواعيد، الاستشارات والوصفات الطبية في مكان واحد. برنامج مكتبي مصمّم للطبيب والسكرتارية، مع ثلاثة حسابات لكل عيادة وبيانات محفوظة بأمان.',
            highlights: [
                'ملف طبي كامل لكل مريض',
                'أجندة مواعيد واضحة',
                'وصفات وشهادات في نقرة واحدة',
            ],
            assurances: [
                {
                    title: 'التفعيل فوري',
                    body: 'أنشئ عيادتك وابدأ استقبال مرضاك في نفس اللحظة. لا انتظار ولا ملفات ترسلها لأحد.',
                },
                {
                    title: 'تثبيت واحد وانتهى',
                    body: 'ملف واحد لويندوز، دون خادم ولا إعدادات شبكة. يشتغل على أجهزة العيادة كما هي.',
                },
                {
                    title: 'فريقك على نفس العيادة',
                    body: 'الطبيب والسكرتارية بأدوار واضحة وبيانات مشتركة، كلٌّ يرى ما يخصّه فقط.',
                },
            ],
        },
        photos: {
            documents: 'يد طبيب تحرّر وثيقة طبية على المكتب',
            roles: 'طبيب بمعطف أبيض يستعمل تطبيقه على الهاتف داخل العيادة',
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
        showcase: {
            eyebrow: 'من داخل التطبيق',
            title: 'شاشات حقيقية من Drclick، لا صور تسويقية',
            subtitle:
                'هذه لقطات مباشرة من التطبيق كما يستعمله الأطباء اليوم. اختر شاشة لتراها عن قرب.',
            hint: 'اختر شاشة',
            items: [
                {
                    shot: 'consultation',
                    tab: 'الاستشارة',
                    title: 'فضاء استشارة كامل في شاشة واحدة',
                    body: 'الملف والسوابق ومنحنيات النمو والوصفات والبيولوجيا والمراسلات والوثائق والصندوق، كلّها في شريط جانبي واحد. وأمامك تنبيه الحساسية، الحالة المدنية، ثم المعاينة: السبب، الفحوصات، التشخيص والعلاج.',
                    alt: 'شاشة فضاء الاستشارة في Drclick مع الملف الطبي وخانة المعاينة',
                },
                {
                    shot: 'prescription',
                    tab: 'الوصفة الطبية',
                    title: 'وصفات جاهزة ونماذج مخصّصة لاختصاصك',
                    body: 'ابحث عن الدواء فتُملأ خاناته تلقائيًا، أو انطلق من نموذج جاهز: وصفة، شهادة، عطلة مرضية، رسالة إلى زميل، تقرير تخطيط القلب أو تقرير الصدى. المتغيّرات تكتب اسم المريض وسنّه وتاريخه وحدها، والطبع بترويسة عيادتك على A4 أو A5.',
                    alt: 'محرّر الوصفات في Drclick مع اختيار النموذج ومعاينة الوصفة بترويسة العيادة',
                },
                {
                    shot: 'booking',
                    tab: 'حجز موعد',
                    title: 'ترى الأماكن الشاغرة قبل أن تحجز',
                    body: 'رزنامة الشهر تعرض عدد الأماكن المتبقّية في كل يوم، مع تمييز الأيام الكاملة والعطل والأيام المغلقة. اختر المريض والخدمة ثم الوقت، والموعد يُسجَّل في ثوانٍ.',
                    alt: 'نافذة حجز موعد في Drclick مع رزنامة الشهر والأوقات المتاحة',
                },
                {
                    shot: 'appointments',
                    tab: 'المواعيد',
                    title: 'يوم العيادة أمامك، ومواعيد الهاتف تصل وحدها',
                    body: 'صنّف المواعيد حسب حالتها، تابع قاعة الانتظار وتقدّم اليوم لحظة بلحظة، واستقبل الحجوزات القادمة من تطبيق المرضى مباشرة في أجندتك.',
                    alt: 'شاشة المواعيد في Drclick مع تصنيف الحالات وقاعة الانتظار',
                },
                {
                    shot: 'patients',
                    tab: 'المرضى',
                    title: 'كل مرضاك برقم ملف واضح',
                    body: 'ابحث بالاسم أو رقم الملف أو الهاتف أو البريد. كل سطر يعرض آخر زيارة والموعد القادم والتنبيهات، وDrclick يكشف الملفات المكرّرة لتدمجها بنقرة.',
                    alt: 'قائمة المرضى في Drclick مع الإحصائيات وآخر زيارة والموعد القادم',
                },
                {
                    shot: 'dashboard',
                    tab: 'لوحة التحكّم',
                    title: 'حالة عيادتك في لمحة',
                    body: 'مواعيد اليوم والمرضى القادمون، ثم المالية: المحصَّل اليوم وهذا الشهر وهذه السنة، ديون المرضى، نسبة التحصيل وصافي الربح.',
                    alt: 'لوحة تحكّم Drclick مع مواعيد اليوم والمرضى القادمين والمؤشّرات المالية',
                },
            ],
        },
        ai: {
            eyebrow: 'جديد · الذكاء الاصطناعي الطبي',
            title: 'ذكاء اصطناعي طبي يعمل معك،',
            titleAccent: 'لا بدلًا منك.',
            subtitle:
                'مدمج في كل استشارة: يقرأ ملف المريض، يحرّر، يقترح ويتحقّق. القرار يبقى لك، ولا يدخل شيء إلى الملف دون مصادقتك.',
            hint: 'اختر وظيفة من وظائف الذكاء الاصطناعي',
            items: [
                {
                    shot: 'copilot',
                    tab: 'المساعد الذكي',
                    title: 'مساعد يعرف ملف المريض',
                    body: 'اطرح سؤالك كما تسأل زميلًا. يقرأ المساعد السوابق والحساسية والوصفات والاستشارة الجارية، يجيب مع ذكر مصادره، ثم يقترح إجراءات، كدواء أو فحص، تطبّقها بنقرة واحدة.',
                    alt: 'المساعد الذكي في Drclick يجيب عن سؤال طبي مع ذكر مصادره ويقترح إضافة علاج وفحص',
                },
                {
                    shot: 'prescription',
                    tab: 'الوصفة الذكية',
                    title: 'وصفة مقترحة مع التحقّق من الحساسية',
                    body: 'يقترح الذكاء الاصطناعي علاجًا مناسبًا للتشخيص ويقارنه بحساسية المريض وسوابقه. موانع الاستعمال تظهر في الأعلى قبل أي إضافة، وأنت تقرّر إبقاء كل سطر أو تعديله أو حذفه.',
                    alt: 'وصفة مقترحة من الذكاء الاصطناعي مع تنبيه حساسية الأسبرين وأدوية للإضافة',
                },
                {
                    shot: 'visit',
                    tab: 'تحرير الفحص',
                    title: 'الفحص محرَّر انطلاقًا من ملاحظاتك',
                    body: 'تكفي كلمات قليلة مكتوبة أو مُملاة: ينظّم الذكاء الاصطناعي سبب الزيارة والفحص والتشخيص والعلاج مع نقاط الحذر، وكل خانة تُعتمد على حدة بنقرة واحدة.',
                    alt: 'اقتراح الذكاء الاصطناعي للفحص الطبي: السبب، الفحوصات، التشخيص والعلاج',
                },
                {
                    shot: 'ecg',
                    tab: 'قراءة تخطيط القلب',
                    title: 'تخطيط قلب يقيسه البرنامج ويقرؤه الذكاء الاصطناعي',
                    body: 'استورد صورة أو مسحًا للتخطيط: يعاير البرنامج الشبكة، يكتشف النبضات ويقيس التردّد والفواصل. يقترح الذكاء الاصطناعي قراءة تُقارن بهذه القياسات، ولا يدخل الملف إلا الاستنتاج الذي توقّعه أنت.',
                    alt: 'تخطيط قلب مستورد في Drclick مع قياسات البرنامج (75 نبضة في الدقيقة) وقراءة الذكاء الاصطناعي',
                },
                {
                    shot: 'analysis',
                    tab: 'ملخّص الملف',
                    title: 'الملف كاملًا في صفحة واحدة',
                    body: 'الاستشارات والوصفات والقياسات والوثائق تُحلَّل معًا: التنبيهات العاجلة أولًا، ثم المخاطر مرتّبة حسب الخطورة، المشاكل النشطة، المتابعة المطلوبة والفحوصات المقترحة.',
                    alt: 'تحليل الذكاء الاصطناعي لملف مريضة: تنبيهات عاجلة، ملخّص، مخاطر ومتابعة',
                },
            ],
            capabilitiesTitle: 'كل ما يقوم به الذكاء الاصطناعي من أجلك',
            capabilities: [
                {
                    title: 'المساعد الطبي',
                    body: 'أسئلة بلغة عادية وأجوبة مع مصادرها.',
                },
                {
                    title: 'الإملاء الصوتي',
                    body: 'تكلّم، والنص يُرتَّب في خانات الفحص.',
                },
                {
                    title: 'تحرير الفحص',
                    body: 'السبب والفحص والتشخيص والعلاج، منظّمة.',
                },
                {
                    title: 'الوصفة المقترحة',
                    body: 'علاج مقترح مع مراقبة الحساسية.',
                },
                {
                    title: 'التحاليل المقترحة',
                    body: 'الفحوصات المناسبة للحالة السريرية.',
                },
                {
                    title: 'تحليل الوثائق',
                    body: 'التقارير والنتائج المستوردة تُقرأ وتُلخَّص.',
                },
                {
                    title: 'قراءة تخطيط القلب',
                    body: 'قياسات التخطيط وقراءة مساعدة.',
                },
                {
                    title: 'ملخّص الملف',
                    body: 'المخاطر والمتابعة والفحوصات في صفحة واحدة.',
                },
            ],
            principlesTitle: 'مصمَّم للممارسة الطبية',
            principles: [
                {
                    title: 'القرار لك',
                    body: 'كل اقتراح يبقى اقتراحًا: لا يدخل شيء إلى الملف دون مصادقتك.',
                },
                {
                    title: 'أجوبة موثّقة',
                    body: 'يشير المساعد إلى أجزاء الملف التي اعتمد عليها.',
                },
                {
                    title: 'مراقبة الحساسية',
                    body: 'تُقارن الاقتراحات بالحساسية المسجّلة، وأي مانع استعمال يظهر أولًا.',
                },
                {
                    title: 'تكلفة واضحة',
                    body: 'كل إجراء يعرض رصيده قبل النقر، ولا يُخصم شيء إن لم يُجب الذكاء الاصطناعي.',
                },
            ],
            disclaimer:
                'الذكاء الاصطناعي في Drclick أداة مساعدة على القرار، ولا يعوّض الفحص السريري ولا حكم الطبيب.',
        },
        how: {
            eyebrow: 'البداية بسيطة',
            title: 'من التحميل إلى أول استشارة في ثلاث خطوات',
            subtitle: 'لا تحتاج إلى خبرة تقنية. التثبيت مباشر والتفعيل فوري.',
            steps: [
                {
                    title: 'حمّل التطبيق',
                    body: 'نزّل ملف التثبيت لويندوز وثبّته على جهاز الاستقبال أو مكتب الطبيب.',
                },
                {
                    title: 'أنشئ عيادتك',
                    body: 'أكمل معالج إنشاء العيادة داخل التطبيق. التفعيل يتم في نفس اللحظة.',
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
        mobileApp: {
            badge: 'متوفّر الآن',
            title: 'تطبيق الهاتف لمرضاك، متوفّر الآن',
            body: 'مرضاك يبحثون عن طبيبهم حسب الولاية والبلدية والاختصاص، يرون أوقاتك الشاغرة فعليًا ويحجزون من هواتفهم. الموعد يصل مباشرة إلى أجندة عيادتك، والسكرتارية تبقى صاحبة القرار الأخير.',
            points: [
                'بحث عن الطبيب حسب الولاية والبلدية والاختصاص',
                'أوقات شاغرة حقيقية وحجز في ثوانٍ',
                'إشعارات وتذكيرات تقلّل مواعيد الغياب',
                'حجز لأفراد العائلة من نفس الحساب',
                'الأجندة تبقى بيد السكرتارية',
            ],
            screens: [
                {
                    screen: 'search',
                    label: 'ابحث عن طبيبك',
                    alt: 'البحث عن طبيب حسب الولاية والاختصاص في تطبيق المرضى Drclick',
                },
                {
                    screen: 'booking',
                    label: 'اختر وقتك',
                    alt: 'اختيار وقت متاح في تطبيق المرضى Drclick',
                },
                {
                    screen: 'home',
                    label: 'تابع مواعيدك',
                    alt: 'الصفحة الرئيسية لتطبيق المرضى مع الموعد القادم',
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
            blurb: 'Drclick — برنامج مكتبي لتسيير العيادات الطبية في الجزائر.',
            contactTitle: 'تواصل معنا',
            phoneLabel: 'الهاتف',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'البريد الإلكتروني',
            emailValue: 'contact@drclick.dz',
            hoursLabel: 'أوقات العمل',
            hoursValue: 'من الأحد إلى الخميس، 9:00 – 17:00',
            rights: 'Drclick. جميع الحقوق محفوظة.',
        },
    },
    fr: {
        localeLabel: 'Français',
        localeShort: 'FR',
        switcherLabel: 'Changer la langue de la page',
        nav: {
            menuLabel: 'Ouvrir le menu de navigation',
            features: 'Fonctionnalités',
            ai: 'IA clinique',
            tour: 'L’application',
            how: 'Comment ça marche',
            roles: 'Équipe',
            requirements: 'Prérequis',
            contact: 'Contact',
        },
        download: {
            cta: 'Télécharger pour Windows',
            ctaShort: 'Télécharger',
            unavailable: 'Téléchargement indisponible',
            note: 'Un seul fichier d’installation Windows. Aucune configuration compliquée.',
        },
        tagline: 'Logiciel de gestion de cabinet médical',
        hero: {
            aiBadge: 'Nouveau : l’IA clinique intégrée à chaque consultation',
            eyebrow: 'Application bureau pour cabinets en Algérie',
            title: 'Gérez tout votre cabinet depuis une seule application.',
            titleLead: 'Gérez tout votre cabinet.',
            titleRotating: [
                'Une seule application',
                'Un seul endroit',
                'Une vie plus simple',
            ],
            subtitle:
                'Patients, rendez-vous, consultations et ordonnances au même endroit. Une application bureau pensée pour le médecin et le secrétariat, avec trois comptes par cabinet et des données conservées en sécurité.',
            highlights: [
                'Dossier patient complet',
                'Agenda de rendez-vous clair',
                'Ordonnances et documents en un clic',
            ],
            assurances: [
                {
                    title: 'Activation immédiate',
                    body: 'Créez votre cabinet et recevez vos patients dans la foulée. Aucun délai, aucun dossier à envoyer.',
                },
                {
                    title: 'Une installation, c’est tout',
                    body: 'Un seul fichier Windows, sans serveur ni configuration réseau. Vos postes actuels suffisent.',
                },
                {
                    title: 'Votre équipe, un seul cabinet',
                    body: 'Médecin et secrétariat, des rôles clairs et des données partagées : chacun ne voit que ce qui le concerne.',
                },
            ],
        },
        photos: {
            documents: 'Main d’un praticien remplissant un document au bureau',
            roles: 'Médecin en blouse blanche utilisant son téléphone au cabinet',
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
        showcase: {
            eyebrow: 'Dans l’application',
            title: 'De vrais écrans de Drclick, pas des illustrations',
            subtitle:
                'Ces captures viennent de l’application telle que les médecins l’utilisent aujourd’hui. Choisissez un écran pour le voir de près.',
            hint: 'Choisissez un écran',
            items: [
                {
                    shot: 'consultation',
                    tab: 'Consultation',
                    title: 'Tout l’espace de consultation sur un seul écran',
                    body: 'Dossier, antécédents, courbes de croissance, ordonnances, bilans, courriers, documents et caisse dans une seule colonne. En face : l’alerte allergies, l’état civil, puis la visite médicale — motif, examens, diagnostic et traitement.',
                    alt: 'Espace de consultation Drclick avec le dossier patient et la visite médicale',
                },
                {
                    shot: 'prescription',
                    tab: 'Ordonnance',
                    title: 'Médicaments pré-remplis et modèles adaptés à votre spécialité',
                    body: 'Cherchez un médicament, ses champs se remplissent seuls — ou partez d’un modèle prêt : ordonnance, certificat, arrêt de travail, lettre au confrère, rapport ECG ou échocardiographie. Les variables écrivent le nom, l’âge et la date à votre place, et l’impression sort à l’en-tête du cabinet en A4 ou A5.',
                    alt: 'Éditeur d’ordonnance Drclick avec sélection du modèle et aperçu à l’en-tête du cabinet',
                },
                {
                    shot: 'booking',
                    tab: 'Prise de RDV',
                    title: 'Les places libres, visibles avant de réserver',
                    body: 'Le calendrier du mois affiche les créneaux restants jour par jour et distingue les journées complètes, les congés et les jours fermés. Patient, prestation, horaire : le rendez-vous est posé en quelques secondes.',
                    alt: 'Fenêtre de prise de rendez-vous Drclick avec calendrier mensuel et créneaux disponibles',
                },
                {
                    shot: 'appointments',
                    tab: 'Rendez-vous',
                    title: 'La journée du cabinet, et les RDV du mobile qui arrivent seuls',
                    body: 'Filtrez les rendez-vous par statut, suivez la salle d’attente et l’avancement en temps réel, et recevez directement dans votre agenda les demandes venues de l’application patients.',
                    alt: 'Écran des rendez-vous Drclick avec filtres par statut et salle d’attente',
                },
                {
                    shot: 'patients',
                    tab: 'Patients',
                    title: 'Tous vos patients, un numéro de dossier clair',
                    body: 'Cherchez par nom, numéro de dossier, téléphone ou e-mail. Chaque ligne montre la dernière visite, le prochain rendez-vous et les alertes, et Drclick repère les doublons pour les fusionner en un clic.',
                    alt: 'Liste des patients Drclick avec statistiques, dernière visite et prochain rendez-vous',
                },
                {
                    shot: 'dashboard',
                    tab: 'Tableau de bord',
                    title: 'L’état du cabinet en un coup d’œil',
                    body: 'Les rendez-vous du jour et les prochains patients, puis les finances : encaissé du jour, du mois et de l’année, dettes patients, taux de recouvrement et bénéfice net.',
                    alt: 'Tableau de bord Drclick avec l’agenda du jour, les prochains patients et les indicateurs financiers',
                },
            ],
        },
        ai: {
            eyebrow: 'Nouveau · IA clinique',
            title: 'Une IA clinique qui travaille avec vous,',
            titleAccent: 'pas à votre place.',
            subtitle:
                'Intégrée à chaque consultation, l’IA de Drclick lit le dossier du patient, rédige, propose et vérifie. Vous gardez la décision : rien n’entre au dossier sans votre validation.',
            hint: 'Choisir une fonction IA',
            items: [
                {
                    shot: 'copilot',
                    tab: 'Copilote',
                    title: 'Un copilote qui connaît le dossier',
                    body: 'Posez votre question comme à un confrère. Le copilote lit les antécédents, les allergies, les ordonnances et la consultation en cours, répond en citant ses sources, puis propose des actions (un médicament, un examen) que vous appliquez d’un clic.',
                    alt: 'Le Copilote Drclick répond à une question clinique en citant le dossier et propose d’ajouter un traitement et un examen',
                },
                {
                    shot: 'prescription',
                    tab: 'Ordonnance IA',
                    title: 'Une ordonnance proposée, les allergies vérifiées',
                    body: 'L’IA propose un traitement adapté au diagnostic et le confronte aux allergies et aux antécédents du patient. Les contre-indications s’affichent en tête, avant tout ajout, et vous gardez, modifiez ou écartez chaque ligne.',
                    alt: 'Proposition d’ordonnance de l’IA avec une alerte d’allergie à l’aspirine et des médicaments à ajouter',
                },
                {
                    shot: 'visit',
                    tab: 'Rédaction de la visite',
                    title: 'La visite rédigée à partir de vos notes',
                    body: 'Quelques mots tapés ou dictés suffisent : l’IA structure le motif, l’examen, le diagnostic et le traitement, avec les points de vigilance. Chaque champ se reprend séparément, d’un clic.',
                    alt: 'Proposition de l’IA pour la visite médicale : motif, examens, diagnostic et traitement',
                },
                {
                    shot: 'ecg',
                    tab: 'Lecture d’ECG',
                    title: 'Un ECG mesuré par le logiciel, lu par l’IA',
                    body: 'Importez une photo ou un scan du tracé : le logiciel calibre la grille, détecte les battements et mesure la fréquence et les intervalles. L’IA propose une lecture confrontée à ces mesures, et seule la conclusion que vous signez entre au dossier.',
                    alt: 'ECG importé dans Drclick avec les mesures du logiciel (75/min) et la lecture de l’IA',
                },
                {
                    shot: 'analysis',
                    tab: 'Synthèse du dossier',
                    title: 'Tout le dossier résumé en une page',
                    body: 'Consultations, ordonnances, mesures et documents analysés ensemble : alertes prioritaires en tête, risques classés par gravité, problèmes actifs, suivi à prévoir et examens à envisager.',
                    alt: 'Analyse IA du dossier d’une patiente : alertes prioritaires, synthèse, risques et suivi',
                },
            ],
            capabilitiesTitle: 'Tout ce que l’IA fait pour vous',
            capabilities: [
                {
                    title: 'Copilote clinique',
                    body: 'Questions en langage courant, réponses sourcées.',
                },
                {
                    title: 'Dictée vocale',
                    body: 'Parlez, le texte se range dans les champs de la visite.',
                },
                {
                    title: 'Rédaction de la visite',
                    body: 'Motif, examen, diagnostic et traitement, structurés.',
                },
                {
                    title: 'Ordonnance suggérée',
                    body: 'Traitement proposé, allergies contrôlées.',
                },
                {
                    title: 'Bilans suggérés',
                    body: 'Les examens adaptés au tableau clinique.',
                },
                {
                    title: 'Analyse de documents',
                    body: 'Comptes rendus et résultats importés, lus et résumés.',
                },
                {
                    title: 'Lecture d’ECG',
                    body: 'Mesures du tracé et lecture assistée.',
                },
                {
                    title: 'Synthèse du dossier',
                    body: 'Risques, suivi et examens sur une page.',
                },
            ],
            principlesTitle: 'Conçue pour la pratique médicale',
            principles: [
                {
                    title: 'Vous gardez la décision',
                    body: 'Chaque suggestion reste une proposition : rien n’entre au dossier sans votre validation.',
                },
                {
                    title: 'Des réponses sourcées',
                    body: 'Le copilote indique les parties du dossier sur lesquelles il s’appuie.',
                },
                {
                    title: 'Allergies vérifiées',
                    body: 'Les propositions sont confrontées aux allergies documentées ; une contre-indication s’affiche en premier.',
                },
                {
                    title: 'Un coût affiché',
                    body: 'Chaque action indique ses crédits avant le clic, et rien n’est décompté si l’IA ne répond pas.',
                },
            ],
            disclaimer:
                'L’IA de Drclick est une aide à la décision. Elle ne remplace ni l’examen clinique ni le jugement du médecin.',
        },
        how: {
            eyebrow: 'Le démarrage est simple',
            title: 'Du téléchargement à la première consultation en trois étapes',
            subtitle:
                'Aucune compétence technique requise. Installation directe et activation immédiate.',
            steps: [
                {
                    title: 'Téléchargez l’application',
                    body: 'Récupérez le fichier d’installation Windows et installez-le sur le poste d’accueil ou du médecin.',
                },
                {
                    title: 'Créez votre cabinet',
                    body: 'Suivez l’assistant de création du cabinet dans l’application. L’activation est immédiate.',
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
        mobileApp: {
            badge: 'Disponible maintenant',
            title: 'L’application mobile de vos patients est disponible',
            body: 'Vos patients trouvent leur médecin par wilaya, commune et spécialité, voient vos créneaux réellement libres et réservent depuis leur téléphone. La demande arrive directement dans l’agenda du cabinet, et le secrétariat garde le dernier mot.',
            points: [
                'Recherche par wilaya, commune et spécialité',
                'Créneaux réellement libres, réservation en quelques secondes',
                'Notifications et rappels qui réduisent les absences',
                'Réservation pour les proches depuis le même compte',
                'Le secrétariat garde la main sur l’agenda',
            ],
            screens: [
                {
                    screen: 'search',
                    label: 'Trouver un médecin',
                    alt: 'Recherche d’un médecin par wilaya et spécialité dans l’application patient Drclick',
                },
                {
                    screen: 'booking',
                    label: 'Choisir un créneau',
                    alt: 'Choix d’un créneau libre dans l’application patient Drclick',
                },
                {
                    screen: 'home',
                    label: 'Suivre ses rendez-vous',
                    alt: 'Accueil de l’application patient avec le prochain rendez-vous',
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
            blurb: 'Drclick — logiciel bureau de gestion de cabinet médical en Algérie.',
            contactTitle: 'Nous contacter',
            phoneLabel: 'Téléphone',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'E-mail',
            emailValue: 'contact@drclick.dz',
            hoursLabel: 'Horaires',
            hoursValue: 'Dimanche à jeudi, 9h00 – 17h00',
            rights: 'Drclick. Tous droits réservés.',
        },
    },
    en: {
        localeLabel: 'English',
        localeShort: 'EN',
        switcherLabel: 'Change page language',
        nav: {
            menuLabel: 'Open the navigation menu',
            features: 'Features',
            ai: 'Clinical AI',
            tour: 'The app',
            how: 'How it works',
            roles: 'Team',
            requirements: 'Requirements',
            contact: 'Contact',
        },
        download: {
            cta: 'Download for Windows',
            ctaShort: 'Download',
            unavailable: 'Download unavailable',
            note: 'A single Windows installer. No complicated setup required.',
        },
        tagline: 'Medical practice management software',
        hero: {
            aiBadge: 'New: clinical AI built into every consultation',
            eyebrow: 'Desktop app for medical practices in Algeria',
            title: 'Run your whole practice from a single app.',
            titleLead: 'Run your whole practice.',
            titleRotating: ['One application', 'One place', 'An easier life'],
            subtitle:
                'Patients, appointments, consultations and prescriptions in one place. A desktop app built for the doctor and the front desk, with three accounts per practice and data kept safely.',
            highlights: [
                'Complete patient record',
                'Clear appointment agenda',
                'Prescriptions and documents in one click',
            ],
            assurances: [
                {
                    title: 'Activated instantly',
                    body: 'Create your practice and start seeing patients the same moment. No waiting, no paperwork to send anyone.',
                },
                {
                    title: 'One install, done',
                    body: 'A single Windows file, with no server and no network setup. Your current computers are enough.',
                },
                {
                    title: 'Your team, one practice',
                    body: 'Doctor and front desk with clear roles and shared records — each sees only what concerns them.',
                },
            ],
        },
        photos: {
            documents: 'Practitioner’s hand filling in a document at a desk',
            roles: 'Doctor in a white coat using their phone at the practice',
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
        showcase: {
            eyebrow: 'Inside the app',
            title: 'Real Drclick screens, not illustrations',
            subtitle:
                'These are captures of the app as doctors use it today. Pick a screen to see it up close.',
            hint: 'Pick a screen',
            items: [
                {
                    shot: 'consultation',
                    tab: 'Consultation',
                    title: 'The whole consultation workspace on one screen',
                    body: 'Record, history, growth charts, prescriptions, lab results, letters, documents and cash desk in a single column. Facing it: the allergy alert, patient identity, then the visit itself — reason, examinations, diagnosis and treatment.',
                    alt: 'Drclick consultation workspace showing the patient record and the medical visit panel',
                },
                {
                    shot: 'prescription',
                    tab: 'Prescription',
                    title: 'Pre-filled medications and templates built for your specialty',
                    body: 'Search a medication and its fields fill themselves in — or start from a ready template: prescription, certificate, sick leave, letter to a colleague, ECG or echocardiography report. Variables write the patient name, age and date for you, and printing comes out on your practice letterhead in A4 or A5.',
                    alt: 'Drclick prescription editor with template selection and a letterhead preview',
                },
                {
                    shot: 'booking',
                    tab: 'Booking',
                    title: 'Free slots visible before you book',
                    body: 'The month calendar shows how many slots are left on each day and marks full days, days off and closed days. Patient, service, time — the appointment is placed in seconds.',
                    alt: 'Drclick booking dialog with a month calendar and available time slots',
                },
                {
                    shot: 'appointments',
                    tab: 'Appointments',
                    title: 'The clinic day, with mobile bookings arriving on their own',
                    body: 'Filter appointments by status, follow the waiting room and the day’s progress live, and receive requests from the patient app straight into your agenda.',
                    alt: 'Drclick appointments screen with status filters and today’s waiting room',
                },
                {
                    shot: 'patients',
                    tab: 'Patients',
                    title: 'Every patient under a clear record number',
                    body: 'Search by name, record number, phone or email. Each row shows the last visit, the next appointment and any alerts, and Drclick spots duplicate records so you can merge them in one click.',
                    alt: 'Drclick patient list with statistics, last visit and next appointment',
                },
                {
                    shot: 'dashboard',
                    tab: 'Dashboard',
                    title: 'The state of your practice at a glance',
                    body: 'Today’s appointments and the next patients, then the finances: collected today, this month and this year, patient debts, recovery rate and net profit.',
                    alt: 'Drclick dashboard with today’s agenda, upcoming patients and financial indicators',
                },
            ],
        },
        ai: {
            eyebrow: 'New · Clinical AI',
            title: 'Clinical AI that works with you,',
            titleAccent: 'not instead of you.',
            subtitle:
                'Built into every consultation, Drclick’s AI reads the patient record, drafts, suggests and double-checks. The decision stays yours: nothing enters the record until you approve it.',
            hint: 'Choose an AI feature',
            items: [
                {
                    shot: 'copilot',
                    tab: 'Copilot',
                    title: 'A copilot that knows the record',
                    body: 'Ask your question as you would a colleague. The copilot reads the history, allergies, prescriptions and the current visit, answers with its sources, then proposes actions (a drug, a test) that you apply in one click.',
                    alt: 'Drclick Copilot answering a clinical question with its sources and proposing a treatment and a test',
                },
                {
                    shot: 'prescription',
                    tab: 'AI prescription',
                    title: 'A suggested prescription, allergies checked',
                    body: 'The AI proposes a treatment suited to the diagnosis and checks it against the patient’s allergies and history. Contraindications appear first, before anything is added, and you keep, edit or drop each line.',
                    alt: 'AI prescription suggestion with an aspirin allergy alert and drugs to add',
                },
                {
                    shot: 'visit',
                    tab: 'Visit write-up',
                    title: 'The visit written up from your notes',
                    body: 'A few typed or dictated words are enough: the AI structures the reason for the visit, the examination, the diagnosis and the treatment, with the points to watch. Each field is taken over separately, in one click.',
                    alt: 'AI suggestion for the visit: reason, examination, diagnosis and treatment',
                },
                {
                    shot: 'ecg',
                    tab: 'ECG reading',
                    title: 'An ECG measured by the software, read by the AI',
                    body: 'Import a photo or scan of the tracing: the software calibrates the grid, detects the beats and measures rate and intervals. The AI suggests a reading checked against those measurements, and only the conclusion you sign enters the record.',
                    alt: 'ECG imported into Drclick with the software measurements (75/min) and the AI reading',
                },
                {
                    shot: 'analysis',
                    tab: 'Record summary',
                    title: 'The whole record on one page',
                    body: 'Consultations, prescriptions, measurements and documents analysed together: priority alerts first, risks ranked by severity, active problems, follow-up to plan and tests to consider.',
                    alt: 'AI analysis of a patient record: priority alerts, summary, risks and follow-up',
                },
            ],
            capabilitiesTitle: 'Everything the AI does for you',
            capabilities: [
                {
                    title: 'Clinical copilot',
                    body: 'Plain-language questions, sourced answers.',
                },
                {
                    title: 'Voice dictation',
                    body: 'Speak, and the text lands in the visit fields.',
                },
                {
                    title: 'Visit write-up',
                    body: 'Reason, exam, diagnosis and treatment, structured.',
                },
                {
                    title: 'Suggested prescription',
                    body: 'Treatment proposed, allergies checked.',
                },
                {
                    title: 'Suggested lab tests',
                    body: 'The tests that fit the clinical picture.',
                },
                {
                    title: 'Document analysis',
                    body: 'Imported reports and results, read and summarised.',
                },
                {
                    title: 'ECG reading',
                    body: 'Tracing measurements and an assisted reading.',
                },
                {
                    title: 'Record summary',
                    body: 'Risks, follow-up and tests on one page.',
                },
            ],
            principlesTitle: 'Designed for medical practice',
            principles: [
                {
                    title: 'You make the call',
                    body: 'Every suggestion stays a suggestion: nothing enters the record without your approval.',
                },
                {
                    title: 'Sourced answers',
                    body: 'The copilot shows which parts of the record it relied on.',
                },
                {
                    title: 'Allergies checked',
                    body: 'Suggestions are checked against documented allergies, and any contraindication is shown first.',
                },
                {
                    title: 'A visible cost',
                    body: 'Each action shows its credits before you click, and nothing is charged if the AI does not answer.',
                },
            ],
            disclaimer:
                'Drclick’s AI is a decision aid. It replaces neither the clinical examination nor the doctor’s judgement.',
        },
        how: {
            eyebrow: 'Getting started is simple',
            title: 'From download to first consultation in three steps',
            subtitle:
                'No technical skills needed. Straightforward install and instant activation.',
            steps: [
                {
                    title: 'Download the app',
                    body: 'Get the Windows installer and install it on the front-desk or doctor’s computer.',
                },
                {
                    title: 'Create your practice',
                    body: 'Follow the practice setup wizard inside the app. Activation happens on the spot.',
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
        mobileApp: {
            badge: 'Available now',
            title: 'The mobile app for your patients is live',
            body: 'Your patients find their doctor by wilaya, municipality and specialty, see the slots you actually have free, and book from their phone. The request lands straight in the practice agenda, and the front desk keeps the final say.',
            points: [
                'Search by wilaya, municipality and specialty',
                'Genuinely free slots, booked in seconds',
                'Notifications and reminders that cut no-shows',
                'Booking for family members from one account',
                'The front desk stays in control of the agenda',
            ],
            screens: [
                {
                    screen: 'search',
                    label: 'Find a doctor',
                    alt: 'Doctor search by wilaya and specialty in the Drclick patient app',
                },
                {
                    screen: 'booking',
                    label: 'Pick a time',
                    alt: 'Choosing a free slot in the Drclick patient app',
                },
                {
                    screen: 'home',
                    label: 'Track appointments',
                    alt: 'Patient app home screen with the next appointment',
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
            blurb: 'Drclick — desktop software for managing medical practices in Algeria.',
            contactTitle: 'Contact us',
            phoneLabel: 'Phone',
            phoneValue: '+213 (0) 00 00 00 00',
            emailLabel: 'Email',
            emailValue: 'contact@drclick.dz',
            hoursLabel: 'Hours',
            hoursValue: 'Sunday to Thursday, 9:00 – 17:00',
            rights: 'Drclick. All rights reserved.',
        },
    },
};

function isLandingLocale(value: unknown): value is LandingLocale {
    return (
        typeof value === 'string' &&
        (LANDING_LOCALES as readonly string[]).includes(value)
    );
}

/**
 * Reactive locale state for the landing page. Persists the chosen locale in
 * localStorage and keeps the document `dir`/`lang` attributes in sync so the
 * whole page flips to RTL when Arabic is selected.
 */
export function useLandingLocale() {
    const locale = ref<LandingLocale>('ar');

    const dir = computed<'rtl' | 'ltr'>(() =>
        locale.value === 'ar' ? 'rtl' : 'ltr',
    );

    const copy = computed<LandingCopy>(() => translations[locale.value]);

    // `dir` and `lang` sit on <html>, which outlives this page. Inertia swaps
    // the component without reloading the document, so the Arabic landing page
    // used to leave `dir="rtl"` behind and every screen visited afterwards —
    // the whole authenticated app, and the desktop shell with it — kept
    // rendering right-to-left. Whatever the server sent is captured on mount
    // and put back on the way out.
    let documentDefaults: { lang: string; dir: string | null } | null = null;

    function captureDocumentAttributes(): void {
        if (typeof document === 'undefined' || documentDefaults !== null) {
            return;
        }

        documentDefaults = {
            lang: document.documentElement.getAttribute('lang') ?? 'fr',
            dir: document.documentElement.getAttribute('dir'),
        };
    }

    function applyDocumentAttributes(): void {
        if (typeof document === 'undefined') {
            return;
        }

        captureDocumentAttributes();

        document.documentElement.setAttribute('lang', locale.value);
        document.documentElement.setAttribute('dir', dir.value);
    }

    function restoreDocumentAttributes(): void {
        if (typeof document === 'undefined' || documentDefaults === null) {
            return;
        }

        document.documentElement.setAttribute('lang', documentDefaults.lang);

        // The server renders no `dir` at all, so removing it is what restores
        // the default direction — writing 'ltr' would not be the same thing.
        if (documentDefaults.dir === null) {
            document.documentElement.removeAttribute('dir');
        } else {
            document.documentElement.setAttribute('dir', documentDefaults.dir);
        }

        documentDefaults = null;
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

    onUnmounted(restoreDocumentAttributes);

    return { locale, dir, copy, setLocale };
}
