<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CalendarX2,
    Check,
    CircleAlert,
    Clock3,
    KeyRound,
    LockKeyhole,
    RefreshCw,
    ShieldCheck,
    ShieldX,
    Sparkles,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import DesktopPinEnrollment from '@/components/DesktopPinEnrollment.vue';
import InputError from '@/components/InputError.vue';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import { dashboard, logout } from '@/routes';
import { pending } from '@/routes/cabinet';

type AccessStatus =
    'pending' | 'suspended' | 'expired' | 'inactive' | 'upgrade';

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

const props = defineProps<{
    can_redeem_license: boolean;
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

const licenseInput = ref<HTMLInputElement | null>(null);
const licenseForm = useForm({
    license_code: '',
});

const accessStatus = computed<AccessStatus>(() => {
    if (props.cabinet?.access_status) {
        return props.cabinet.access_status;
    }

    return props.cabinet?.status === 'suspended' ? 'suspended' : 'pending';
});
const isSuspended = computed(() => accessStatus.value === 'suspended');
const isExpired = computed(() => accessStatus.value === 'expired');
const isInactive = computed(() => accessStatus.value === 'inactive');
const isUpgrade = computed(() => accessStatus.value === 'upgrade');

const statusEyebrow = computed(() => {
    switch (accessStatus.value) {
        case 'suspended':
            return 'Accès suspendu';
        case 'expired':
            return 'Période d’essai terminée';
        case 'inactive':
            return 'Licence inactive';
        case 'upgrade':
            return 'Nouvelle licence disponible';
        default:
            return 'Demande enregistrée';
    }
});

const statusTitle = computed(() => {
    switch (accessStatus.value) {
        case 'suspended':
            return `Le cabinet ${props.cabinet?.name ?? ''} est suspendu`;
        case 'expired':
            return 'Votre essai de 7 jours est arrivé à échéance';
        case 'inactive':
            return 'Votre licence doit être réactivée';
        case 'upgrade':
            return 'Votre code de mise à niveau est prêt';
        default:
            return 'Activation de la licence en attente';
    }
});

const fallbackMessage = computed(() => {
    switch (accessStatus.value) {
        case 'suspended':
            return 'Contactez l’équipe DrClickDz afin de vérifier votre situation et rétablir l’accès au cabinet.';
        case 'expired':
            return 'Un nouveau code d’essai ou de licence à vie doit être saisi avant de retrouver votre tableau de bord.';
        case 'inactive':
            return 'Saisissez le nouveau code de licence attribué à ce cabinet pour rétablir l’accès.';
        case 'upgrade':
            return 'Saisissez le code reçu pour renouveler votre essai ou passer immédiatement à la licence à vie.';
        default:
            return 'Le superadministrateur doit maintenant vous remettre un code d’essai de 7 jours ou de licence à vie.';
    }
});

function formatExpiry(value: string): string {
    return new Intl.DateTimeFormat('fr-DZ', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(value));
}

function redeemLicense(): void {
    licenseForm.post('/cabinet/license/redeem', {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            licenseInput.value?.focus();
        },
    });
}

onMounted(() => {
    markDesktopOnboardingComplete();
});

defineOptions({
    layout: {
        title: 'Accès à votre cabinet',
        description:
            'Votre compte est sécurisé pendant la vérification de votre accès.',
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
    <AuthBackLink
        v-else
        :href="logout()"
        method="post"
        as="button"
        label="Retour à la connexion"
    />

    <section class="text-center" aria-labelledby="activation-status-title">
        <div
            class="mx-auto flex size-20 items-center justify-center rounded-[1.6rem] shadow-lg"
            :class="
                isSuspended || isExpired || isInactive
                    ? 'bg-red-50 text-red-600 shadow-red-900/5 dark:bg-red-950/50'
                    : 'bg-sky-50 text-[#1268a5] shadow-sky-900/5 dark:bg-sky-950/50 dark:text-sky-300'
            "
        >
            <CircleAlert v-if="isSuspended" class="size-9" aria-hidden="true" />
            <CalendarX2
                v-else-if="isExpired"
                class="size-9"
                aria-hidden="true"
            />
            <ShieldX v-else-if="isInactive" class="size-9" aria-hidden="true" />
            <Sparkles v-else-if="isUpgrade" class="size-9" aria-hidden="true" />
            <Clock3 v-else class="size-9" aria-hidden="true" />
        </div>

        <p
            class="mt-5 text-xs font-bold tracking-[0.16em] uppercase"
            :class="
                isSuspended || isExpired || isInactive
                    ? 'text-red-600'
                    : 'text-[#1268a5]'
            "
        >
            {{ statusEyebrow }}
        </p>
        <h3
            id="activation-status-title"
            class="mt-2 text-xl font-extrabold text-slate-950 dark:text-white"
        >
            {{ statusTitle }}
        </h3>
        <p
            class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400"
        >
            <span
                v-if="accessStatus === 'pending' && cabinet"
                class="font-semibold text-slate-700 dark:text-slate-200"
            >
                {{ cabinet.name }} est bien enregistré.
            </span>
            {{ cabinet?.message ?? fallbackMessage }}
        </p>
    </section>

    <form
        v-if="can_redeem_license"
        class="mt-7 overflow-hidden rounded-3xl border border-blue-200 bg-gradient-to-br from-blue-50 via-white to-cyan-50 text-left shadow-lg shadow-blue-950/5 dark:border-blue-900 dark:from-blue-950/50 dark:via-slate-900 dark:to-cyan-950/30"
        data-test="hosted-license-redemption"
        @submit.prevent="redeemLicense"
    >
        <div
            class="flex items-start gap-3 border-b border-blue-100 p-5 dark:border-blue-900/70"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-md shadow-blue-600/20"
            >
                <KeyRound class="size-5" aria-hidden="true" />
            </span>
            <div>
                <p
                    class="text-base font-extrabold text-slate-950 dark:text-white"
                >
                    Activez votre licence DrClickDz
                </p>
                <p
                    class="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300"
                >
                    Saisissez le code unique remis par le superadministrateur.
                    Il fonctionne uniquement pour ce cabinet et une seule fois.
                </p>
            </div>
        </div>

        <div class="p-5">
            <div
                v-if="pending_license_grant"
                class="mb-4 flex items-start gap-2.5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                data-test="pending-license-grant"
            >
                <Sparkles
                    class="mt-0.5 size-4 shrink-0 text-emerald-600"
                    aria-hidden="true"
                />
                <p class="text-xs leading-5">
                    Une licence
                    <strong>{{ pending_license_grant.plan_label }}</strong>
                    vous a été attribuée<span
                        v-if="pending_license_grant.issued_at"
                    >
                        le
                        {{
                            formatExpiry(pending_license_grant.issued_at)
                        }}</span
                    >. Le code reçu se termine par
                    <strong class="font-mono">{{
                        pending_license_grant.code_suffix
                    }}</strong
                    >.
                </p>
            </div>
            <div
                v-else
                class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                Le code apparaîtra dans l’e-mail envoyé lorsque le
                superadministrateur choisira une licence d’essai de 7 jours ou
                une licence à vie pour votre cabinet.
            </div>

            <label
                for="license_code"
                class="text-xs font-extrabold tracking-wide text-slate-700 uppercase dark:text-slate-200"
            >
                Code de licence
            </label>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                <input
                    id="license_code"
                    ref="licenseInput"
                    v-model="licenseForm.license_code"
                    name="license_code"
                    type="text"
                    inputmode="text"
                    autocomplete="one-time-code"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="80"
                    placeholder="DRDZ-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                    class="h-12 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 font-mono text-sm font-bold tracking-wider text-slate-950 uppercase transition outline-none placeholder:font-sans placeholder:font-normal placeholder:tracking-normal placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-blue-950"
                    :aria-invalid="Boolean(licenseForm.errors.license_code)"
                    :aria-describedby="
                        licenseForm.errors.license_code
                            ? 'license-code-error'
                            : undefined
                    "
                    :disabled="licenseForm.processing"
                    data-test="license-code-input"
                />
                <button
                    type="submit"
                    class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-extrabold text-white shadow-md shadow-blue-600/20 transition hover:bg-blue-700 focus-visible:ring-4 focus-visible:ring-blue-200 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="
                        licenseForm.processing ||
                        !licenseForm.license_code.trim()
                    "
                    data-test="redeem-license-code"
                >
                    <RefreshCw
                        class="size-4"
                        :class="{ 'animate-spin': licenseForm.processing }"
                        aria-hidden="true"
                    />
                    {{
                        licenseForm.processing
                            ? 'Vérification…'
                            : 'Activer la licence'
                    }}
                </button>
            </div>
            <InputError
                id="license-code-error"
                class="mt-2"
                :message="licenseForm.errors.license_code"
            />
        </div>
    </form>

    <dl
        v-if="cabinet?.license"
        class="mt-7 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-left sm:grid-cols-3 dark:border-slate-700 dark:bg-slate-800/40"
        aria-label="Informations de la licence"
    >
        <div>
            <dt
                class="text-[0.68rem] font-bold tracking-wider text-slate-400 uppercase"
            >
                Type de licence
            </dt>
            <dd class="mt-1 text-sm font-bold text-slate-800 dark:text-white">
                {{ cabinet.license.plan_label ?? 'Non attribuée' }}
            </dd>
        </div>
        <div>
            <dt
                class="text-[0.68rem] font-bold tracking-wider text-slate-400 uppercase"
            >
                État
            </dt>
            <dd class="mt-1 text-sm font-bold text-slate-800 dark:text-white">
                {{ cabinet.license.status_label }}
            </dd>
        </div>
        <div v-if="cabinet.license.expires_at">
            <dt
                class="text-[0.68rem] font-bold tracking-wider text-slate-400 uppercase"
            >
                Échéance
            </dt>
            <dd class="mt-1 text-sm font-bold text-slate-800 dark:text-white">
                {{ formatExpiry(cabinet.license.expires_at) }}
            </dd>
        </div>
    </dl>

    <ol
        v-if="!isSuspended && !isUpgrade"
        class="mt-8 grid gap-3 text-left sm:grid-cols-3"
        aria-label="Progression de l’activation"
    >
        <li
            class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/30"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-white"
            >
                <Check class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                Cabinet créé
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                Vos informations sont enregistrées.
            </p>
        </li>
        <li
            class="rounded-2xl p-4 ring-2"
            :class="
                isExpired || isInactive
                    ? 'border border-red-200 bg-red-50/70 ring-red-100 dark:border-red-900 dark:bg-red-950/30 dark:ring-red-950'
                    : 'border border-sky-200 bg-sky-50/70 ring-sky-100 dark:border-sky-900 dark:bg-sky-950/30 dark:ring-sky-950'
            "
            aria-current="step"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full text-white"
                :class="isExpired || isInactive ? 'bg-red-600' : 'bg-[#1268a5]'"
            >
                <CalendarX2
                    v-if="isExpired"
                    class="size-4"
                    aria-hidden="true"
                />
                <ShieldX
                    v-else-if="isInactive"
                    class="size-4"
                    aria-hidden="true"
                />
                <Clock3 v-else class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                {{
                    isExpired
                        ? 'Essai expiré'
                        : isInactive
                          ? 'Licence inactive'
                          : 'Licence'
                }}
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                {{
                    isExpired || isInactive
                        ? 'Un nouveau code est nécessaire.'
                        : 'Saisie du code remis par l’administrateur.'
                }}
            </p>
        </li>
        <li
            class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300"
            >
                <LockKeyhole class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                Tableau de bord
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                Ouverture dès l’activation.
            </p>
        </li>
    </ol>

    <div class="mt-8">
        <Link
            :href="isUpgrade ? dashboard() : pending()"
            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#1268a5] px-5 text-sm font-bold text-white shadow-lg shadow-sky-800/10 hover:bg-[#0d578b]"
            data-test="refresh-activation"
        >
            <RefreshCw class="size-4" aria-hidden="true" />
            {{
                isUpgrade
                    ? 'Retour au tableau de bord'
                    : accessStatus === 'pending'
                      ? 'Vérifier l’activation'
                      : 'Revérifier mon accès'
            }}
        </Link>
    </div>

    <p
        class="mt-6 flex items-center justify-center gap-2 text-center text-xs leading-5 text-slate-400"
    >
        <ShieldCheck
            class="size-4 shrink-0 text-emerald-600"
            aria-hidden="true"
        />
        Vous pouvez fermer DrClickDz et revenir plus tard. Vos informations sont
        conservées.
    </p>
</template>
