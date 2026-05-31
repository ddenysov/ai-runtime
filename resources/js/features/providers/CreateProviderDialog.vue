<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { LoaderCircleIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { createAiProvider } from '@/lib/api';
import {
    findProviderType,
    providerTypes,
    slugifyProviderName,
} from '@/features/providers/provider-types';

const open = defineModel('open', {
    type: Boolean,
    default: false,
});

const emit = defineEmits(['created']);

const submitting = ref(false);
const slugTouched = ref(false);
const slugFieldActive = ref(false);
const credentialFieldActive = ref(false);
const serverErrors = ref({});

const form = reactive({
    name: '',
    slug: '',
    description: '',
    type: providerTypes[0]?.value ?? 'gemini',
    credentials: {
        key: '',
    },
    is_active: true,
});

const selectedType = computed(() => findProviderType(form.type));

function resetForm() {
    form.name = '';
    form.slug = '';
    form.description = '';
    form.type = providerTypes[0]?.value ?? 'gemini';
    form.credentials = { key: '' };
    form.is_active = true;
    slugTouched.value = false;
    slugFieldActive.value = false;
    credentialFieldActive.value = false;
    serverErrors.value = {};
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function syncSlugFromName() {
    if (slugTouched.value || !form.name.trim()) {
        return;
    }

    form.slug = slugifyProviderName(form.name);
}

watch(
    () => form.name,
    () => {
        if (!slugTouched.value) {
            syncSlugFromName();
        }
    },
);

watch(open, (isOpen) => {
    if (!isOpen) {
        resetForm();
    }
});

async function submit() {
    submitting.value = true;
    serverErrors.value = {};

    try {
        const provider = await createAiProvider({
            slug: form.slug,
            name: form.name,
            description: form.description || null,
            type: form.type,
            credentials: { ...form.credentials },
            is_active: form.is_active,
        });

        toast.success('Provider created', {
            description: `${provider.name} is ready to use.`,
        });

        emit('created', provider);
        open.value = false;
    } catch (error) {
        if (error.status === 422 && error.data?.errors) {
            serverErrors.value = error.data.errors;
            return;
        }

        toast.error('Could not create provider', {
            description: error.message,
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-2xl">
            <form
                class="flex flex-col"
                autocomplete="off"
                @submit.prevent="submit"
            >
                <!-- Decoy fields: browsers pair text + password; fill these instead of slug/API key -->
                <input
                    type="text"
                    name="username"
                    autocomplete="username"
                    tabindex="-1"
                    aria-hidden="true"
                    class="pointer-events-none absolute -left-[9999px] size-0 opacity-0"
                >
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    tabindex="-1"
                    aria-hidden="true"
                    class="pointer-events-none absolute -left-[9999px] size-0 opacity-0"
                >
                <DialogHeader class="border-b px-5 py-4 text-left">
                    <DialogTitle>New AI provider</DialogTitle>
                    <DialogDescription>
                        Connect a model vendor with encrypted credentials. You can attach models after the provider is saved.
                    </DialogDescription>
                </DialogHeader>

                <div class="max-h-[min(70vh,32rem)] overflow-y-auto px-5 py-5">
                    <FieldGroup class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                        <Field>
                            <FieldLabel for="provider-name">Display name</FieldLabel>
                            <Input
                                id="provider-name"
                                v-model="form.name"
                                autocomplete="off"
                                placeholder="Work Gemini"
                                :aria-invalid="!!fieldError('name')"
                                @blur="syncSlugFromName"
                            />
                            <FieldError :errors="fieldError('name')" />
                        </Field>

                        <Field>
                            <FieldLabel for="provider-slug">Slug</FieldLabel>
                            <Input
                                id="provider-slug"
                                v-model="form.slug"
                                name="ai-provider-slug"
                                autocomplete="off"
                                data-1p-ignore
                                data-lpignore="true"
                                placeholder="work-gemini"
                                :readonly="!slugFieldActive"
                                :aria-invalid="!!fieldError('slug')"
                                @focus="slugFieldActive = true"
                                @input="slugTouched = true"
                            />
                            <FieldError :errors="fieldError('slug')" />
                        </Field>

                        <Field>
                            <FieldLabel for="provider-type">Provider type</FieldLabel>
                            <Select v-model="form.type">
                                <SelectTrigger id="provider-type" class="w-full">
                                    <SelectValue placeholder="Select provider type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="type in providerTypes"
                                        :key="type.value"
                                        :value="type.value"
                                    >
                                        {{ type.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <FieldError :errors="fieldError('type')" />
                        </Field>

                        <template v-if="selectedType">
                            <Field
                                v-for="credential in selectedType.credentials"
                                :key="credential.key"
                            >
                                <FieldLabel :for="`credential-${credential.key}`">
                                    {{ credential.label }}
                                </FieldLabel>
                                <Input
                                    :id="`credential-${credential.key}`"
                                    v-model="form.credentials[credential.key]"
                                    :name="`ai-provider-credential-${credential.key}`"
                                    :type="credential.type"
                                    :placeholder="credential.placeholder"
                                    :autocomplete="credential.type === 'password' ? 'new-password' : 'off'"
                                    data-1p-ignore
                                    data-lpignore="true"
                                    :readonly="!credentialFieldActive"
                                    :aria-invalid="!!fieldError(`credentials.${credential.key}`)"
                                    @focus="credentialFieldActive = true"
                                />
                                <FieldError :errors="fieldError(`credentials.${credential.key}`)" />
                            </Field>
                        </template>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="provider-description">Description</FieldLabel>
                            <Textarea
                                id="provider-description"
                                v-model="form.description"
                                rows="2"
                                placeholder="Optional notes for your team"
                            />
                            <FieldError :errors="fieldError('description')" />
                        </Field>

                        <Field orientation="horizontal" class="sm:col-span-2">
                            <FieldLabel for="provider-active">Active</FieldLabel>
                            <Switch
                                id="provider-active"
                                v-model:checked="form.is_active"
                            />
                        </Field>
                    </FieldGroup>
                </div>

                <DialogFooter class="mx-0 mb-0 border-t bg-muted/30 px-5 pt-4 pb-5">
                    <Button
                        type="button"
                        variant="outline"
                        class="app-soft-control"
                        :disabled="submitting"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" class="rounded-app-control" :disabled="submitting">
                        <LoaderCircleIcon
                            v-if="submitting"
                            class="size-4 animate-spin"
                        />
                        Create provider
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
