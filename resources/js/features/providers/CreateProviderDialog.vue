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
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field';
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
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-lg">
            <form class="flex flex-col" @submit.prevent="submit">
                <DialogHeader class="border-b px-5 py-4 text-left">
                    <DialogTitle>New AI provider</DialogTitle>
                    <DialogDescription>
                        Connect a model vendor with encrypted credentials. You can attach models after the provider is saved.
                    </DialogDescription>
                </DialogHeader>

                <div class="max-h-[min(70vh,36rem)] overflow-y-auto px-5 py-5">
                    <FieldGroup>
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
                                autocomplete="off"
                                placeholder="work-gemini"
                                :aria-invalid="!!fieldError('slug')"
                                @input="slugTouched = true"
                            />
                            <FieldDescription>
                                Lowercase identifier used in runtime configuration.
                            </FieldDescription>
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
                            <FieldDescription v-if="selectedType">
                                {{ selectedType.description }}
                            </FieldDescription>
                            <FieldError :errors="fieldError('type')" />
                        </Field>

                        <Field>
                            <FieldLabel for="provider-description">Description</FieldLabel>
                            <Textarea
                                id="provider-description"
                                v-model="form.description"
                                rows="3"
                                placeholder="Optional notes for your team"
                            />
                            <FieldError :errors="fieldError('description')" />
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
                                    :type="credential.type"
                                    :placeholder="credential.placeholder"
                                    autocomplete="off"
                                    :aria-invalid="!!fieldError(`credentials.${credential.key}`)"
                                />
                                <FieldDescription v-if="credential.description">
                                    {{ credential.description }}
                                </FieldDescription>
                                <FieldError :errors="fieldError(`credentials.${credential.key}`)" />
                            </Field>
                        </template>

                        <Field orientation="horizontal">
                            <div class="flex flex-1 flex-col gap-1">
                                <FieldLabel for="provider-active">Active</FieldLabel>
                                <FieldDescription>
                                    Inactive providers stay stored but are not used by runtime agents.
                                </FieldDescription>
                            </div>
                            <Switch
                                id="provider-active"
                                v-model:checked="form.is_active"
                            />
                        </Field>
                    </FieldGroup>
                </div>

                <DialogFooter class="border-t bg-muted/30 px-5 py-4">
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
