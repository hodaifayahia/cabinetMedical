<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

const props = defineProps<{
    settings: {
        currency: string;
        vat_rate: number;
        default_consultation_fee: number | null;
        receipt_prefix: string;
        fiscal_year_start: string;
    };
}>();

const form = useForm({
    currency: props.settings.currency ?? 'DA',
    vat_rate: props.settings.vat_rate ?? 0,
    default_consultation_fee: props.settings.default_consultation_fee ?? '',
    receipt_prefix: props.settings.receipt_prefix ?? 'FACT-',
    fiscal_year_start: props.settings.fiscal_year_start ?? '01-01',
});

const submit = () => {
    form.put('/app/configuration/accounting', { preserveScroll: true });
};
</script>

<template>
    <Head title="Paramètres comptables" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel p-6">
            <Heading
                title="Paramètres comptables"
                description="Paramètres généraux utilisés pour la facturation et la comptabilité du cabinet."
            />

            <form class="mt-6 max-w-2xl space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="currency">Devise</Label>
                        <Input id="currency" v-model="form.currency" />
                        <InputError :message="form.errors.currency" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="vat_rate">Taux de TVA (%)</Label>
                        <Input
                            id="vat_rate"
                            v-model="form.vat_rate"
                            type="number"
                            min="0"
                            max="100"
                        />
                        <InputError :message="form.errors.vat_rate" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="fee"
                            >Tarif de consultation par défaut (DA)</Label
                        >
                        <Input
                            id="fee"
                            v-model="form.default_consultation_fee"
                            type="number"
                            step="any"
                            min="0"
                        />
                        <InputError
                            :message="form.errors.default_consultation_fee"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="prefix"
                            >Préfixe des reçus et factures</Label
                        >
                        <Input
                            id="prefix"
                            v-model="form.receipt_prefix"
                            placeholder="FACT-"
                        />
                        <InputError :message="form.errors.receipt_prefix" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="fiscal"
                            >Début de l’exercice fiscal (MM-JJ)</Label
                        >
                        <Input
                            id="fiscal"
                            v-model="form.fiscal_year_start"
                            placeholder="01-01"
                        />
                        <InputError :message="form.errors.fiscal_year_start" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing"
                        >Enregistrer les paramètres</Button
                    >
                </div>
            </form>
        </section>
    </div>
</template>
