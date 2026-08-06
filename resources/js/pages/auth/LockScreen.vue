<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

const props = defineProps<{
    pinConfigured: boolean;
}>();

const method = ref<'pin' | 'password'>(
    props.pinConfigured ? 'pin' : 'password',
);
const form = useForm({
    method: method.value,
    pin: '',
    password: '',
});

const usingPin = computed(() => method.value === 'pin');
const credentialError = computed(
    () => (form.errors as Record<string, string | undefined>).credential ?? '',
);

const switchMethod = () => {
    method.value = usingPin.value ? 'password' : 'pin';
    form.method = method.value;
    form.clearErrors();
    form.reset('pin', 'password');
};

const submit = () => {
    form.method = method.value;
    form.post('/session/unlock', {
        preserveScroll: true,
        onError: () => form.reset('pin', 'password'),
    });
};

const flushBeforeLogout = () => {
    form.cancel();
};

defineOptions({
    layout: {
        title: 'Session verrouillée',
        description:
            'Déverrouillez cette session locale pour reprendre votre travail.',
    },
});
</script>

<template>
    <Head title="Session verrouillée" />

    <Alert v-if="credentialError" variant="destructive" class="mb-6">
        <AlertDescription aria-live="assertive">
            {{ credentialError }}
        </AlertDescription>
    </Alert>

    <form class="space-y-6" novalidate @submit.prevent="submit">
        <div v-if="usingPin" class="grid gap-2">
            <Label for="unlock_pin">Code PIN local</Label>
            <Input
                id="unlock_pin"
                v-model="form.pin"
                type="password"
                inputmode="numeric"
                pattern="[0-9]*"
                minlength="6"
                maxlength="12"
                autocomplete="off"
                autofocus
                required
                :aria-invalid="Boolean(credentialError)"
                aria-describedby="unlock-pin-help"
                data-test="session-unlock-pin"
            />
            <p id="unlock-pin-help" class="text-xs leading-5 text-slate-500">
                6 à 12 chiffres. Ce code fonctionne uniquement pour cette
                session déjà authentifiée.
            </p>
        </div>

        <div v-else class="grid gap-2">
            <Label for="unlock_password">Mot de passe du compte</Label>
            <PasswordInput
                id="unlock_password"
                v-model="form.password"
                autocomplete="current-password"
                autofocus
                required
                :aria-invalid="Boolean(credentialError)"
                data-test="session-unlock-password"
            />
            <p class="text-xs leading-5 text-slate-500">
                Le mot de passe reste le moyen principal d’authentification. Ce
                déverrouillage ne confirme pas les actions sensibles.
            </p>
        </div>

        <InputError :message="credentialError" class="sr-only" />

        <Button
            type="submit"
            class="w-full"
            :disabled="form.processing"
            data-test="session-unlock-submit"
        >
            <Spinner v-if="form.processing" />
            Déverrouiller
        </Button>

        <Button
            v-if="pinConfigured"
            type="button"
            variant="outline"
            class="w-full"
            :disabled="form.processing"
            @click="switchMethod"
        >
            {{
                usingPin
                    ? 'Utiliser le mot de passe'
                    : 'Utiliser le code PIN local'
            }}
        </Button>
    </form>

    <div
        class="mt-7 border-t border-slate-200 pt-6 text-center dark:border-slate-700"
    >
        <p class="mb-3 text-xs leading-5 text-slate-500">
            Besoin de récupérer le compte ? Déconnectez-vous puis utilisez la
            procédure de récupération.
        </p>
        <Link
            :href="logout()"
            method="post"
            as="button"
            class="text-sm font-semibold text-slate-700 underline-offset-4 hover:underline dark:text-slate-200"
            data-session-lock-no-activity
            data-test="locked-session-logout"
            @click="flushBeforeLogout"
        >
            Se déconnecter
        </Link>
    </div>
</template>
