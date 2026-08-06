<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { HeartPulse, Phone, User } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { isValidationError, postJson, putJson } from '@/lib/http';
import type { PatientDetail, PatientOption } from '@/types';

type SavedPatient = { id: number; full_name: string; patient_number: string };

const props = withDefaults(
    defineProps<{
        genders: PatientOption[];
        bloodGroups: PatientOption[];
        method: 'post' | 'put';
        submitUrl: string;
        submitLabel: string;
        cancelUrl?: string;
        patient?: PatientDetail | null;
        mode?: 'inertia' | 'json';
    }>(),
    { mode: 'inertia' },
);

const emit = defineEmits<{
    success: [patient?: SavedPatient];
    cancel: [];
}>();

const form = useForm({
    first_name: props.patient?.first_name ?? '',
    last_name: props.patient?.last_name ?? '',
    date_of_birth: props.patient?.date_of_birth ?? '',
    gender: props.patient?.gender ?? '',
    phone: props.patient?.phone ?? '',
    secondary_phone: props.patient?.secondary_phone ?? '',
    email: props.patient?.email ?? '',
    address: props.patient?.address ?? '',
    city: props.patient?.city ?? '',
    emergency_contact_name: props.patient?.emergency_contact_name ?? '',
    emergency_contact_phone: props.patient?.emergency_contact_phone ?? '',
    blood_group: props.patient?.blood_group ?? '',
    notes: props.patient?.notes ?? '',
});

const jsonProcessing = ref(false);

type TabKey = 'identity' | 'contact' | 'medical';
type FormField = keyof ReturnType<typeof form.data>;

const activeTab = ref<string>('identity');

const tabFields: Record<TabKey, FormField[]> = {
    identity: ['first_name', 'last_name', 'date_of_birth', 'gender'],
    contact: ['phone', 'secondary_phone', 'email', 'address', 'city'],
    medical: [
        'blood_group',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ],
};

const tabHasError = computed(() => ({
    identity: tabFields.identity.some((field) => Boolean(form.errors[field])),
    contact: tabFields.contact.some((field) => Boolean(form.errors[field])),
    medical: tabFields.medical.some((field) => Boolean(form.errors[field])),
}));

const focusFirstTabWithError = () => {
    const target = (Object.keys(tabFields) as TabKey[]).find((tab) =>
        tabFields[tab].some((field) => Boolean(form.errors[field])),
    );

    if (target) {
        activeTab.value = target;
    }
};

const submitJson = async () => {
    jsonProcessing.value = true;
    form.clearErrors();

    try {
        const payload = form.data();
        const response = await (props.method === 'put'
            ? putJson<{ patient: SavedPatient }>(props.submitUrl, payload)
            : postJson<{ patient: SavedPatient }>(props.submitUrl, payload));

        emit('success', response.patient);
    } catch (error) {
        if (isValidationError(error)) {
            for (const [field, messages] of Object.entries(error.errors)) {
                form.setError(
                    field as keyof ReturnType<typeof form.data>,
                    messages[0],
                );
            }

            focusFirstTabWithError();
        } else {
            toast.error(
                "Impossible d'enregistrer le patient. Veuillez réessayer.",
            );
        }
    } finally {
        jsonProcessing.value = false;
    }
};

const submit = () => {
    if (props.mode === 'json') {
        void submitJson();

        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('success'),
        onError: () => focusFirstTabWithError(),
    } as const;

    if (props.method === 'put') {
        form.put(props.submitUrl, options);
    } else {
        form.post(props.submitUrl, options);
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <Tabs v-model="activeTab" class="gap-6">
            <TabsList class="grid w-full grid-cols-3">
                <TabsTrigger value="identity">
                    <User />
                    <span>Identité</span>
                    <span
                        v-if="tabHasError.identity"
                        class="size-2 shrink-0 rounded-full bg-destructive"
                        aria-hidden="true"
                    />
                </TabsTrigger>
                <TabsTrigger value="contact">
                    <Phone />
                    <span>Contact</span>
                    <span
                        v-if="tabHasError.contact"
                        class="size-2 shrink-0 rounded-full bg-destructive"
                        aria-hidden="true"
                    />
                </TabsTrigger>
                <TabsTrigger value="medical">
                    <HeartPulse />
                    <span>Médical</span>
                    <span
                        v-if="tabHasError.medical"
                        class="size-2 shrink-0 rounded-full bg-destructive"
                        aria-hidden="true"
                    />
                </TabsTrigger>
            </TabsList>

            <TabsContent value="identity" class="mt-0 min-h-[18rem]">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="first_name">
                            Prénom
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="first_name"
                            v-model="form.first_name"
                            required
                            autocomplete="given-name"
                        />
                        <InputError :message="form.errors.first_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="last_name">
                            Nom
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="last_name"
                            v-model="form.last_name"
                            required
                            autocomplete="family-name"
                        />
                        <InputError :message="form.errors.last_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="date_of_birth">Date de naissance</Label>
                        <Input
                            id="date_of_birth"
                            type="date"
                            v-model="form.date_of_birth"
                        />
                        <InputError :message="form.errors.date_of_birth" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="gender">Sexe</Label>
                        <Select
                            :model-value="form.gender"
                            @update:model-value="
                                (value) =>
                                    (form.gender = (value as string) ?? '')
                            "
                        >
                            <SelectTrigger id="gender" class="w-full">
                                <SelectValue placeholder="Choisir le sexe" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in genders"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.gender" />
                    </div>
                </div>
            </TabsContent>

            <TabsContent value="contact" class="mt-0 min-h-[18rem]">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="phone">Téléphone</Label>
                        <Input
                            id="phone"
                            v-model="form.phone"
                            autocomplete="tel"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="secondary_phone">
                            Téléphone secondaire
                        </Label>
                        <Input
                            id="secondary_phone"
                            v-model="form.secondary_phone"
                        />
                        <InputError :message="form.errors.secondary_phone" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            v-model="form.email"
                            autocomplete="email"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="address">Adresse</Label>
                        <Input
                            id="address"
                            v-model="form.address"
                            autocomplete="street-address"
                        />
                        <InputError :message="form.errors.address" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="city">Ville</Label>
                        <Input
                            id="city"
                            v-model="form.city"
                            autocomplete="address-level2"
                        />
                        <InputError :message="form.errors.city" />
                    </div>
                </div>
            </TabsContent>

            <TabsContent value="medical" class="mt-0 min-h-[18rem]">
                <div class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="blood_group">Groupe sanguin</Label>
                            <Select
                                :model-value="form.blood_group"
                                @update:model-value="
                                    (value) =>
                                        (form.blood_group =
                                            (value as string) ?? '')
                                "
                            >
                                <SelectTrigger id="blood_group" class="w-full">
                                    <SelectValue
                                        placeholder="Choisir le groupe sanguin"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in bloodGroups"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.blood_group" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <span class="text-sm font-medium text-foreground">
                            Contact d'urgence
                        </span>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="emergency_contact_name">Nom</Label>
                                <Input
                                    id="emergency_contact_name"
                                    v-model="form.emergency_contact_name"
                                />
                                <InputError
                                    :message="
                                        form.errors.emergency_contact_name
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="emergency_contact_phone"
                                    >Téléphone</Label
                                >
                                <Input
                                    id="emergency_contact_phone"
                                    v-model="form.emergency_contact_phone"
                                />
                                <InputError
                                    :message="
                                        form.errors.emergency_contact_phone
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="notes">Notes</Label>
                        <Textarea id="notes" v-model="form.notes" rows="4" />
                        <InputError :message="form.errors.notes" />
                    </div>
                </div>
            </TabsContent>
        </Tabs>

        <div class="flex items-center gap-3 border-t pt-5">
            <Button
                type="submit"
                :disabled="form.processing || jsonProcessing"
                >{{ submitLabel }}</Button
            >
            <Button v-if="cancelUrl" variant="outline" as-child>
                <Link :href="cancelUrl">Annuler</Link>
            </Button>
            <Button
                v-else
                type="button"
                variant="outline"
                @click="emit('cancel')"
                >Annuler</Button
            >
        </div>
    </form>
</template>
