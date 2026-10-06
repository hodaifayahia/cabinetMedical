<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarX2,
    CircleAlert,
    Clock3,
    Cloud,
    FileKey,
    KeyRound,
    RefreshCw,
    ShieldCheck,
    ShieldX,
    Sparkles,
    WifiOff,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import DesktopPinEnrollment from '@/components/DesktopPinEnrollment.vue';
import InputError from '@/components/InputError.vue';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import { dashboard } from '@/routes';
import { pending } from '@/routes/cabinet';

type AccessStatus =
    'pending' | 'inactive' | 'suspended' | 'expired' | 'upgrade';

type LicenseSummary = {
    plan: string | null;
    plan_label: string | null;
    status: string;
    status_label: string;
    expires_at: string | null;
};

type PendingLicenseGrant = {
    plan: string;
    plan_label: string;
    issued_at: string | null;
    code_suffix: string;
};

type DesktopActivation = {
    online: boolean;
    license_file: boolean;
    owner_email: string | null;
    offline: boolean;
};

const props = defineProps<{
    can_redeem_license: boolean;
    desktop_activation?: DesktopActivation;
    pending_license_grant: PendingLicenseGrant | null;
    cabinet: {
        name: string;
        status: string;
        access_status: AccessStatus | null;
        access_reason: string | null;
        message: string | null;
        license: LicenseSummary | null;
    } | null;
}>();

const page = usePage();
const licenseCode = ref('');
const manualLicenseCode = ref('');
const showManualEntry = ref(false);
const csrfToken = ref('');
const errors = computed(
    () =>
        (page.props.errors as Record<string, string | undefined> | undefined) ??
        {},
);
const licenseError = computed(() => errors.value.license_code);
const onlineError = computed(
    () => errors.value.online_email ?? errors.value.online_password,
);
const fileError = computed(
    () => errors.value.entitlement ?? errors.value.entitlement_file,
);

const desktop = computed<DesktopActivation>(
    () =>
        props.desktop_activation ?? {
            online: false,
            license_file: false,
            owner_email: null,
            offline: false,
        },
);
// An installed desktop never holds a grant of its own: its code is checked
// once on the online service, so the code form is always offered there.
const showCodeForm = computed(
    () =>
        props.can_redeem_license &&
        (props.pending_license_grant !== null || desktop.value.online),
);
const onlineEmail = ref(props.desktop_activation?.owner_email ?? '');
const onlinePassword = ref('');
const showOnlineAccount = ref(Boolean(onlineError.value));
const showLicenseFile = ref(Boolean(fileError.value));
const licenseFileName = ref('');

function onLicenseFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    licenseFileName.value = input.files?.[0]?.name ?? '';
}

const accessStatus = computed<AccessStatus>(() => {
    if (props.cabinet?.access_status) {
        return props.cabinet.access_status;
    }

    return props.cabinet?.status === 'suspended' ? 'suspended' : 'pending';
});

const isUpgrade = computed(() => accessStatus.value === 'upgrade');
const isBlocked = computed(() =>
    ['inactive', 'suspended', 'expired'].includes(accessStatus.value),
);

const statusTitle = computed(() => {
    switch (accessStatus.value) {
        case 'suspended':
            return 'Accès au cabinet suspendu';
        case 'expired':
            return 'Votre période d’essai est terminée';
        case 'inactive':
            return 'Votre licence doit être réactivée';
        case 'upgrade':
            return 'Votre mise à niveau est prête';
        default:
            return 'Activation de la licence en attente';
    }
});

const fallbackMessage = computed(() => {
    switch (accessStatus.value) {
        case 'suspended':
            return 'Contactez l’équipe Drclick pour vérifier la situation de votre cabinet.';
        case 'expired':
            return 'Saisissez un nouveau code pour renouveler votre licence.';
        case 'inactive':
            return 'Saisissez le code attribué à ce cabinet pour rétablir votre accès.';
        case 'upgrade':
            return 'Votre cabinet reste accessible pendant l’application de la nouvelle licence.';
        default:
            return 'Saisissez le code d’activation remis par l’administrateur.';
    }
});

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('fr-DZ', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(value));
}

onMounted(() => {
    csrfToken.value =
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';
    markDesktopOnboardingComplete();
});

defineOptions({
    layout: {
        title: 'Accès à votre cabinet',
        description: 'Activez la licence associée à votre cabinet.',
    },
});
</script>

<template>
    <DesktopPinEnrollment />
    <Head title="Activation de la licence" />

    <AuthBackLink
        v-if="isUpgrade"
        :href="dashboard()"
        label="Retour au tableau de bord"
    />
    <form
        v-else
        action="/cabinet/sign-out"
        method="post"
        class="mb-6 w-full"
        data-test="activation-sign-in-return"
    >
        <input type="hidden" name="_token" :value="csrfToken" />
        <button
            type="submit"
            class="group inline-flex h-11 w-full items-center justify-center gap-3 rounded-xl border border-border bg-muted px-5 text-sm font-semibold text-foreground shadow-sm transition hover:border-brand hover:bg-brand-soft hover:text-brand focus-visible:ring-2 focus-visible:ring-brand/30 focus-visible:outline-none dark:hover:text-brand-mint"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full border border-slate-300 bg-background text-muted-foreground transition group-hover:-translate-x-0.5 group-hover:border-brand group-hover:text-brand dark:border-slate-700"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
            </span>
            <span>Retour à la page de connexion</span>
        </button>
    </form>

    <section class="space-y-5 text-center" aria-labelledby="status-title">
        <div
            class="mx-auto flex size-16 items-center justify-center rounded-2xl border"
            :class="
                isBlocked
                    ? 'border-destructive/20 bg-destructive/10 text-destructive'
                    : 'border-brand/20 bg-brand-soft text-brand'
            "
        >
            <ShieldX
                v-if="accessStatus === 'suspended'"
                class="size-8"
                aria-hidden="true"
            />
            <CalendarX2
                v-else-if="accessStatus === 'expired'"
                class="size-8"
                aria-hidden="true"
            />
            <CircleAlert
                v-else-if="accessStatus === 'inactive'"
                class="size-8"
                aria-hidden="true"
            />
            <Sparkles
                v-else-if="accessStatus === 'upgrade'"
                class="size-8"
                aria-hidden="true"
            />
            <Clock3 v-else class="size-8" aria-hidden="true" />
        </div>

        <div>
            <h2 id="status-title" class="text-xl font-bold text-foreground">
                {{ statusTitle }}
            </h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                <strong v-if="cabinet" class="text-foreground">
                    {{ cabinet.name }}.
                </strong>
                {{ cabinet?.message ?? fallbackMessage }}
            </p>
        </div>
    </section>

    <div
        v-if="desktop.offline"
        class="mt-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-left text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
        role="alert"
        data-test="activation-offline"
    >
        <WifiOff class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p>
            <strong>Pas de connexion Internet.</strong> L’activation d’un poste
            demande Internet une seule fois. Reconnectez ce poste puis
            réessayez, ou activez-le avec un fichier de licence fourni par
            Drclick.
        </p>
    </div>

    <form
        v-if="showCodeForm"
        action="/cabinet/license/redeem"
        method="post"
        class="mt-6 rounded-2xl border border-border bg-card p-5 text-left"
        data-test="hosted-license-redemption"
    >
        <input type="hidden" name="_token" :value="csrfToken" />
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand text-white"
            >
                <KeyRound class="size-5" aria-hidden="true" />
            </span>
            <div>
                <h3 class="font-bold text-foreground">Code de licence</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Activez une licence d’essai de 7 jours ou une licence à vie.
                    Le code est réservé à ce cabinet et ne fonctionne qu’une
                    fois.
                </p>
                <p
                    v-if="desktop.online && !pending_license_grant"
                    class="mt-1 text-sm text-muted-foreground"
                    data-test="desktop-activation-notice"
                >
                    Le code est vérifié une seule fois auprès du service en
                    ligne Drclick. Ensuite, ce poste fonctionne sans Internet.
                </p>
            </div>
        </div>

        <p
            v-if="pending_license_grant"
            class="mt-4 rounded-xl border border-brand/20 bg-brand-soft p-3 text-sm text-foreground"
            data-test="pending-license-grant"
        >
            Licence
            <strong>{{ pending_license_grant.plan_label }}</strong>
            attribuée<span v-if="pending_license_grant.issued_at">
                le {{ formatDate(pending_license_grant.issued_at) }}</span
            >. Le code se termine par
            <strong class="font-mono">{{
                pending_license_grant.code_suffix
            }}</strong
            >.
        </p>

        <label
            for="license_code"
            class="mt-4 block text-sm font-semibold text-foreground"
        >
            Code reçu
        </label>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row">
            <input
                id="license_code"
                v-model="licenseCode"
                name="license_code"
                type="text"
                autocomplete="one-time-code"
                autocapitalize="characters"
                spellcheck="false"
                maxlength="80"
                class="h-11 min-w-0 flex-1 rounded-xl border border-input bg-background px-3 font-mono text-sm uppercase outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                :aria-invalid="Boolean(licenseError)"
            />
            <button
                type="submit"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-white disabled:opacity-60"
                :disabled="!licenseCode.trim() || !csrfToken"
                data-test="redeem-license-code"
            >
                <RefreshCw class="size-4" aria-hidden="true" />
                Activer
            </button>
        </div>
        <InputError class="mt-2" :message="licenseError" />
    </form>

    <section
        v-else-if="can_redeem_license"
        class="mt-6 rounded-2xl border border-dashed border-border bg-muted/40 p-5 text-left"
        data-test="hosted-license-redemption-unavailable"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
            >
                <KeyRound class="size-5" aria-hidden="true" />
            </span>
            <div>
                <h3 class="font-bold text-foreground">
                    Aucun code actif disponible
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Demandez à l’administration Drclick de générer un nouveau
                    code pour ce cabinet. Tant qu’aucun code actif n’est en
                    attente, la saisie d’un ancien code sera refusée.
                </p>
            </div>
        </div>
        <button
            type="button"
            class="mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-border bg-background px-5 text-sm font-semibold text-foreground transition hover:border-brand hover:bg-brand-soft hover:text-brand focus-visible:ring-2 focus-visible:ring-brand/30 focus-visible:outline-none"
            data-test="toggle-manual-activation"
            @click="showManualEntry = !showManualEntry"
        >
            <KeyRound class="size-4" aria-hidden="true" />
            {{ showManualEntry ? 'Annuler la saisie' : 'Activer avec un code' }}
        </button>

        <form
            v-if="showManualEntry"
            action="/cabinet/license/redeem"
            method="post"
            class="mt-4"
            data-test="manual-license-redemption"
        >
            <input type="hidden" name="_token" :value="csrfToken" />
            <label
                for="manual_license_code"
                class="block text-sm font-semibold text-foreground"
            >
                Code d'activation
            </label>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                <input
                    id="manual_license_code"
                    v-model="manualLicenseCode"
                    name="license_code"
                    type="text"
                    autocomplete="one-time-code"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="80"
                    class="h-11 min-w-0 flex-1 rounded-xl border border-input bg-background px-3 font-mono text-sm uppercase outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    :aria-invalid="Boolean(licenseError)"
                />
                <button
                    type="submit"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="!manualLicenseCode.trim() || !csrfToken"
                    data-test="submit-manual-license-code"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Activer
                </button>
            </div>
            <InputError class="mt-2" :message="licenseError" />
        </form>
    </section>

    <section
        v-if="can_redeem_license && (desktop.online || desktop.license_file)"
        class="mt-4 grid gap-3 text-left"
        data-test="desktop-activation-alternatives"
    >
        <div
            v-if="desktop.online"
            class="rounded-2xl border border-border bg-card p-4"
        >
            <button
                type="button"
                class="flex w-full items-center gap-3 text-left text-sm font-semibold text-foreground"
                data-test="toggle-online-account-activation"
                :aria-expanded="showOnlineAccount"
                @click="showOnlineAccount = !showOnlineAccount"
            >
                <Cloud class="size-5 text-brand" aria-hidden="true" />
                <span class="flex-1">
                    Activer avec mon compte Drclick en ligne
                    <span
                        class="block text-xs font-normal text-muted-foreground"
                    >
                        Votre cabinet est déjà actif sur drclickdz.com ? Ce
                        poste reçoit la même licence et reçoit aussi les
                        rendez-vous de l’application mobile.
                    </span>
                </span>
            </button>

            <form
                v-if="showOnlineAccount"
                action="/cabinet/license/online-account"
                method="post"
                class="mt-4 grid gap-3"
                data-test="online-account-activation"
            >
                <input type="hidden" name="_token" :value="csrfToken" />
                <label
                    for="online_email"
                    class="text-sm font-semibold text-foreground"
                >
                    E-mail du compte en ligne
                </label>
                <input
                    id="online_email"
                    v-model="onlineEmail"
                    name="online_email"
                    type="email"
                    autocomplete="username"
                    maxlength="190"
                    class="h-11 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    :aria-invalid="Boolean(onlineError)"
                />
                <label
                    for="online_password"
                    class="text-sm font-semibold text-foreground"
                >
                    Mot de passe du compte en ligne
                </label>
                <input
                    id="online_password"
                    v-model="onlinePassword"
                    name="online_password"
                    type="password"
                    autocomplete="current-password"
                    maxlength="255"
                    class="h-11 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    :aria-invalid="Boolean(onlineError)"
                />
                <p class="text-xs text-muted-foreground">
                    Le mot de passe sert une seule fois et n’est pas conservé
                    sur ce poste.
                </p>
                <button
                    type="submit"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="
                        !onlineEmail.trim() || !onlinePassword || !csrfToken
                    "
                    data-test="submit-online-account-activation"
                >
                    <Cloud class="size-4" aria-hidden="true" />
                    Activer et relier ce poste
                </button>
                <InputError :message="onlineError" />
            </form>
        </div>

        <div
            v-if="desktop.license_file"
            class="rounded-2xl border border-border bg-card p-4"
        >
            <button
                type="button"
                class="flex w-full items-center gap-3 text-left text-sm font-semibold text-foreground"
                data-test="toggle-license-file-activation"
                :aria-expanded="showLicenseFile"
                @click="showLicenseFile = !showLicenseFile"
            >
                <FileKey class="size-5 text-brand" aria-hidden="true" />
                <span class="flex-1">
                    Activer avec un fichier de licence
                    <span
                        class="block text-xs font-normal text-muted-foreground"
                    >
                        Sans aucune connexion Internet : importez le fichier de
                        licence signé envoyé par Drclick.
                    </span>
                </span>
            </button>

            <form
                v-if="showLicenseFile"
                action="/cabinet/license/file"
                method="post"
                enctype="multipart/form-data"
                class="mt-4 grid gap-3"
                data-test="license-file-activation"
            >
                <input type="hidden" name="_token" :value="csrfToken" />
                <label
                    for="entitlement_file"
                    class="text-sm font-semibold text-foreground"
                >
                    Fichier de licence
                </label>
                <input
                    id="entitlement_file"
                    name="entitlement_file"
                    type="file"
                    accept=".json,.lic,.txt,application/json,text/plain"
                    class="text-sm"
                    :aria-invalid="Boolean(fileError)"
                    @change="onLicenseFileChange"
                />
                <button
                    type="submit"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="!licenseFileName || !csrfToken"
                    data-test="submit-license-file-activation"
                >
                    <FileKey class="size-4" aria-hidden="true" />
                    Activer avec ce fichier
                </button>
                <InputError :message="fileError" />
            </form>
        </div>
    </section>

    <dl
        v-if="cabinet?.license"
        class="mt-6 grid gap-3 rounded-2xl border border-border bg-muted/40 p-4 text-left sm:grid-cols-3"
    >
        <div>
            <dt class="text-xs text-muted-foreground">Type</dt>
            <dd class="mt-1 text-sm font-semibold text-foreground">
                {{ cabinet.license.plan_label ?? 'Non attribuée' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">État</dt>
            <dd class="mt-1 text-sm font-semibold text-foreground">
                {{ cabinet.license.status_label }}
            </dd>
        </div>
        <div v-if="cabinet.license.expires_at">
            <dt class="text-xs text-muted-foreground">Échéance</dt>
            <dd class="mt-1 text-sm font-semibold text-foreground">
                {{ formatDate(cabinet.license.expires_at) }}
            </dd>
        </div>
    </dl>

    <Link
        :href="isUpgrade ? dashboard() : pending()"
        class="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-border bg-muted px-5 text-sm font-semibold text-foreground hover:bg-muted/80"
        data-test="refresh-activation"
    >
        <RefreshCw class="size-4" aria-hidden="true" />
        {{ isUpgrade ? 'Retour au tableau de bord' : 'Vérifier mon accès' }}
    </Link>

    <p
        class="mt-5 flex items-center justify-center gap-2 text-center text-xs text-muted-foreground"
    >
        <ShieldCheck class="size-4 text-brand" aria-hidden="true" />
        Vos informations restent enregistrées pendant l’activation.
    </p>
</template>
