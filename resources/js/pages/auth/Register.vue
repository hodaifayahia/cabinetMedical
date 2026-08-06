<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
    specialtySuggestions: string[];
}>();

defineOptions({
    layout: {
        title: 'Créer le compte propriétaire',
        description:
            'Configuration initiale unique de cette installation MediSmart',
    },
});
</script>

<template>
    <Head title="Configuration initiale" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Nom complet</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Dr Nadia Benali"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="specialty">Spécialité médicale</Label>
                <Input
                    id="specialty"
                    type="text"
                    required
                    :tabindex="2"
                    name="specialty"
                    list="medical-specialties"
                    autocomplete="organization-title"
                    placeholder="Médecine générale"
                />
                <datalist id="medical-specialties">
                    <option
                        v-for="specialty in specialtySuggestions"
                        :key="specialty"
                        :value="specialty"
                    />
                </datalist>
                <p class="text-xs text-muted-foreground">
                    Cette valeur apparaîtra sur les documents médicaux et sera
                    verrouillée après la création du compte.
                </p>
                <InputError :message="errors.specialty" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Adresse e-mail</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="3"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Mot de passe</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Mot de passe"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    Confirmer le mot de passe
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="5"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirmer le mot de passe"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="6"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Créer le compte propriétaire
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Un compte existe déjà ?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="7"
                >Se connecter</TextLink
            >
        </div>
    </Form>
</template>
