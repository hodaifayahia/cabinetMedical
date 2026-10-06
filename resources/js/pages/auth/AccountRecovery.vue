<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    FileKey2,
    KeyRound,
    LifeBuoy,
    Mail,
    MonitorCheck,
    UsersRound,
} from '@lucide/vue';
import { ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/http';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Mot de passe ou PIN oublié',
        description:
            'Retrouvez l’accès à votre compte, même sans e-mail ni Internet.',
    },
});

const props = defineProps<{
    deviceRecoveryAvailable: boolean;
    emailResetAvailable: boolean;
    passwordRules: string;
}>();

type Method = 'code' | 'device' | 'manager';

const method = ref<Method>('code');
const codeForm = useForm({
    email: '',
    code: '',
    password: '',
    password_confirmation: '',
});
const deviceForm = useForm({
    email: '',
    code: '',
    password: '',
    password_confirmation: '',
});
const deviceKeyPath = ref('');
const deviceKeyError = ref('');
const deviceKeyProcessing = ref(false);

const submitCode = () => {
    codeForm.post('/account-recovery/code', {
        onFinish: () => codeForm.reset('password', 'password_confirmation'),
    });
};

const submitDevice = () => {
    deviceForm.post('/account-recovery/device', {
        onFinish: () => deviceForm.reset('password', 'password_confirmation'),
    });
};

const issueDeviceKey = async () => {
    deviceKeyProcessing.value = true;
    deviceKeyError.value = '';

    try {
        const body = await postJson<{ path?: string }>(
            '/account-recovery/device/key',
            {},
        );
        deviceKeyPath.value = body.path ?? '';
    } catch {
        deviceKeyError.value =
            'Impossible de créer la clé du poste. Réessayez dans une minute.';
    } finally {
        deviceKeyProcessing.value = false;
    }
};

const methods: Array<{
    id: Method;
    title: string;
    description: string;
    icon: typeof KeyRound;
    available: boolean;
}> = [
    {
        id: 'code',
        title: 'Code de secours',
        description: 'Un des codes imprimés ou enregistrés à l’avance.',
        icon: KeyRound,
        available: true,
    },
    {
        id: 'device',
        title: 'Clé du poste principal',
        description:
            'Sur l’ordinateur qui contient les données du cabinet, sans Internet.',
        icon: FileKey2,
        available: props.deviceRecoveryAvailable,
    },
    {
        id: 'manager',
        title: 'Demander au responsable',
        description:
            'Le médecin ou l’administrateur du cabinet réinitialise votre accès.',
        icon: UsersRound,
        available: true,
    },
];
</script>

<template>
    <Head title="Mot de passe ou PIN oublié" />

    <AuthBackLink :href="login()" label="Retour à la connexion" />

    <div
        class="mb-6 flex items-start gap-3 rounded-2xl border border-brand/20 bg-brand-soft/50 p-4 text-sm leading-6 text-slate-700 dark:border-brand/30 dark:bg-brand-soft/10 dark:text-slate-200"
    >
        <LifeBuoy
            class="mt-0.5 size-5 shrink-0 text-brand"
            aria-hidden="true"
        />
        <p>
            Un PIN oublié se recrée simplement : connectez-vous avec votre mot
            de passe et Drclick vous demandera un nouveau PIN. Si vous avez
            aussi oublié le mot de passe, choisissez une solution ci-dessous.
        </p>
    </div>

    <div
        class="grid gap-2 sm:grid-cols-3"
        role="tablist"
        aria-label="Méthode de récupération"
    >
        <button
            v-for="item in methods.filter((entry) => entry.available)"
            :key="item.id"
            type="button"
            role="tab"
            :aria-selected="method === item.id"
            class="flex flex-col items-start gap-1 rounded-2xl border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
            :class="
                method === item.id
                    ? 'border-brand bg-brand-soft/60 dark:bg-brand-soft/15'
                    : 'border-slate-200 hover:border-brand/50 dark:border-slate-800'
            "
            :data-test="`recovery-method-${item.id}`"
            @click="method = item.id"
        >
            <component
                :is="item.icon"
                class="size-5 text-brand"
                aria-hidden="true"
            />
            <span class="text-sm font-bold text-slate-900 dark:text-white">{{
                item.title
            }}</span>
            <span class="text-xs leading-5 text-slate-500 dark:text-slate-400">
                {{ item.description }}
            </span>
        </button>
    </div>

    <form
        v-if="method === 'code'"
        class="mt-6 grid gap-5"
        data-test="recovery-code-form"
        @submit.prevent="submitCode"
    >
        <div class="grid gap-2">
            <Label for="recovery-email">Adresse e-mail du compte</Label>
            <Input
                id="recovery-email"
                v-model="codeForm.email"
                type="email"
                autocomplete="email"
                required
            />
            <InputError :message="codeForm.errors.email" />
        </div>
        <div class="grid gap-2">
            <Label for="recovery-code">Code de secours</Label>
            <Input
                id="recovery-code"
                v-model="codeForm.code"
                autocomplete="off"
                spellcheck="false"
                placeholder="XXXX-XXXX-XXXX"
                class="font-mono tracking-widest uppercase"
                required
            />
            <InputError :message="codeForm.errors.code" />
        </div>
        <div class="grid gap-2">
            <Label for="recovery-password">Nouveau mot de passe</Label>
            <PasswordInput
                id="recovery-password"
                v-model="codeForm.password"
                autocomplete="new-password"
                :passwordrules="passwordRules"
                required
            />
            <InputError :message="codeForm.errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="recovery-password-confirmation">
                Confirmer le mot de passe
            </Label>
            <PasswordInput
                id="recovery-password-confirmation"
                v-model="codeForm.password_confirmation"
                autocomplete="new-password"
                required
            />
        </div>
        <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">
            Chaque code ne fonctionne qu’une fois. Les codes se créent dans
            Paramètres › Sécurité › Codes de secours.
        </p>
        <Button
            type="submit"
            class="h-11 w-full"
            :disabled="codeForm.processing"
            data-test="recovery-code-submit"
        >
            <Spinner v-if="codeForm.processing" />
            Réinitialiser le mot de passe
        </Button>
    </form>

    <div
        v-else-if="method === 'device'"
        class="mt-6 grid gap-5"
        data-test="recovery-device"
    >
        <ol
            class="grid gap-2 text-sm leading-6 text-slate-700 dark:text-slate-200"
        >
            <li class="flex gap-2">
                <MonitorCheck
                    class="mt-1 size-4 shrink-0 text-brand"
                    aria-hidden="true"
                />
                Cliquez sur « Créer la clé » : un fichier contenant un code à
                usage unique est écrit dans le dossier des données Drclick de
                cet ordinateur.
            </li>
            <li class="flex gap-2">
                <FileKey2
                    class="mt-1 size-4 shrink-0 text-brand"
                    aria-hidden="true"
                />
                Ouvrez ce fichier avec l’Explorateur Windows, recopiez le code
                ci-dessous puis choisissez un nouveau mot de passe.
            </li>
        </ol>

        <Button
            type="button"
            variant="outline"
            :disabled="deviceKeyProcessing"
            data-test="recovery-device-issue"
            @click="issueDeviceKey"
        >
            <Spinner v-if="deviceKeyProcessing" />
            {{ deviceKeyPath ? 'Créer une nouvelle clé' : 'Créer la clé' }}
        </Button>
        <InputError :message="deviceKeyError" />
        <div
            v-if="deviceKeyPath"
            class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-900"
        >
            <p class="font-semibold text-slate-700 dark:text-slate-200">
                Fichier créé (valable 15 minutes) :
            </p>
            <code
                class="mt-1 block font-mono break-all text-slate-900 select-all dark:text-white"
                data-test="recovery-device-path"
                >{{ deviceKeyPath }}</code
            >
        </div>

        <form class="grid gap-5" @submit.prevent="submitDevice">
            <div class="grid gap-2">
                <Label for="device-email">Adresse e-mail du compte</Label>
                <Input
                    id="device-email"
                    v-model="deviceForm.email"
                    type="email"
                    autocomplete="email"
                    required
                />
                <InputError :message="deviceForm.errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="device-code">Code du fichier</Label>
                <Input
                    id="device-code"
                    v-model="deviceForm.code"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="XXXX-XXXX"
                    class="font-mono tracking-widest uppercase"
                    required
                />
                <InputError :message="deviceForm.errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="device-password">Nouveau mot de passe</Label>
                <PasswordInput
                    id="device-password"
                    v-model="deviceForm.password"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    required
                />
                <InputError :message="deviceForm.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="device-password-confirmation">
                    Confirmer le mot de passe
                </Label>
                <PasswordInput
                    id="device-password-confirmation"
                    v-model="deviceForm.password_confirmation"
                    autocomplete="new-password"
                    required
                />
            </div>
            <Button
                type="submit"
                class="h-11 w-full"
                :disabled="deviceForm.processing"
                data-test="recovery-device-submit"
            >
                <Spinner v-if="deviceForm.processing" />
                Réinitialiser le mot de passe
            </Button>
        </form>
    </div>

    <div
        v-else
        class="mt-6 grid gap-3 text-sm leading-6 text-slate-700 dark:text-slate-200"
        data-test="recovery-manager"
    >
        <p>
            Le médecin ou l’administrateur du cabinet peut, depuis
            <strong>Personnel</strong>, choisir un nouveau mot de passe pour
            votre compte ou réinitialiser votre code PIN. Vous vous connectez
            ensuite avec ce mot de passe et créez un nouveau PIN.
        </p>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Médecin seul sur son poste ? Utilisez la clé du poste principal
            depuis l’ordinateur qui contient les données.
        </p>
    </div>

    <div
        v-if="emailResetAvailable"
        class="mt-8 flex items-center justify-center gap-2 text-sm text-slate-500"
    >
        <Mail class="size-4" aria-hidden="true" />
        <TextLink href="/forgot-password">
            Recevoir plutôt un lien par e-mail
        </TextLink>
    </div>
</template>
