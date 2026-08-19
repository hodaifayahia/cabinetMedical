<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarX2,
    CircleAlert,
    Clock3,
    KeyRound,
    RefreshCw,
    ShieldCheck,
    ShieldX,
    Sparkles,
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

const page = usePage();
const licenseCode = ref('');
const csrfToken = ref('');
const licenseError = computed(
    () =>
        (page.props.errors as Record<string, string | undefined> | undefined)
            ?.license_code,
);

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

    <form
        v-if="can_redeem_license && pending_license_grant"
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
