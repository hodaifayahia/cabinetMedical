<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { DatabaseZap, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

defineProps<{
    hasDemoData: boolean;
    counts: {
        patients: number;
        consultations: number;
        appointments: number;
        expenses: number;
    };
}>();

const processing = ref(false);
const confirmRemoval = ref(false);

const fill = () => {
    router.post(
        '/app/configuration/demo-data',
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
};

const remove = () => {
    router.delete('/app/configuration/demo-data', {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirmRemoval.value = false;
        },
    });
};
</script>

<template>
    <Head title="Données de démonstration" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel space-y-6 p-6">
            <Heading
                title="Données de démonstration"
                description="Remplissez le cabinet avec une semaine d’activité fictive pour essayer Drclick : patients, consultations passées avec ordonnances et paiements, rendez-vous du jour et des jours suivants, dépenses et actes courants."
            />

            <div
                class="rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border"
            >
                <p v-if="hasDemoData" class="font-medium">
                    Présentes dans ce cabinet : {{ counts.patients }} patients,
                    {{ counts.consultations }} consultations,
                    {{ counts.appointments }} rendez-vous,
                    {{ counts.expenses }} dépenses.
                </p>
                <p v-else class="text-muted-foreground">
                    Aucune donnée de démonstration dans ce cabinet.
                </p>
                <p class="mt-2 text-muted-foreground">
                    Vos propres patients et rendez-vous ne sont jamais modifiés
                    : seuls les éléments marqués « [démo] » sont ajoutés ou
                    retirés. Les actes ajoutés (Consultation, Certificat
                    médical, ECG…) restent après le retrait.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button :disabled="processing || hasDemoData" @click="fill">
                    <DatabaseZap class="size-4" />
                    Ajouter les données de démonstration
                </Button>
                <Button
                    v-if="hasDemoData"
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    :disabled="processing"
                    @click="confirmRemoval = true"
                >
                    <Trash2 class="size-4" />
                    Retirer les données de démonstration
                </Button>
            </div>
        </section>

        <Dialog v-model:open="confirmRemoval">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >Retirer les données de démonstration ?</DialogTitle
                    >
                    <DialogDescription>
                        Les {{ counts.patients }} patients de démonstration sont
                        supprimés définitivement, avec leurs consultations,
                        ordonnances, paiements et rendez-vous, ainsi que les
                        dépenses fictives. Vos propres dossiers ne sont pas
                        touchés.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirmRemoval = false">
                        Annuler
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="remove"
                    >
                        Retirer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
