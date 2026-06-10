<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { LoaderCircleIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
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
import { getAiProvider, testAiProviderConnection, updateAiProvider } from '@/lib/api';
import { findProviderType, providerTypes } from '@/features/providers/provider-types';

const open = defineModel('open', {
    type: Boolean,
    default: false,
});

const props = defineProps({
    providerId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(['updated']);

const loading = ref(false);
const submitting = ref(false);
const testingModelIndex = ref(null);
const credentialFieldActive = ref(false);
const serverErrors = ref({});

const form = reactive({
    slug: '',
    name: '',
    description: '',
    type: providerTypes[0]?.value ?? 'gemini',
    credentials: {
        key: '',
    },
    models: [],
    is_active: true,
});

const selectedType = computed(() => findProviderType(form.type));
const modelPlaceholder = computed(() => selectedType.value?.modelPlaceholder ?? 'gemini-1.5-flash');
const displayNamePlaceholder = computed(() => selectedType.value?.modelNamePlaceholder ?? 'Gemini 1.5 Flash');
const hasAtLeastOneModel = computed(() => form.models.some((model) => model.model.trim()));
const canSubmit = computed(() => hasAtLeastOneModel.value && !loading.value);

function resetForm() {
    form.slug = '';
    form.name = '';
    form.description = '';
    form.type = providerTypes[0]?.value ?? 'gemini';
    form.credentials = { key: '' };
    form.models = [];
    form.is_active = true;
    credentialFieldActive.value = false;
    serverErrors.value = {};
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function modelFieldError(index, name) {
    return fieldError(`models.${index}.${name}`);
}

function credentialPlaceholder(credential) {
    const masked = form.masked_credentials?.[credential.key];

    return masked ? `Saved: ${masked}` : credential.placeholder;
}

function addModel() {
    form.models.push({
        id: null,
        model: '',
        name: '',
        tested: false,
    });
}

function removeModel(index) {
    if (form.models.length === 1) {
        const model = form.models[0];
        model.id = null;
        model.model = '';
        model.name = '';
        model.tested = false;
        return;
    }

    form.models.splice(index, 1);
}

function markModelDirty(model) {
    model.tested = false;
}

function markAllModelsDirty() {
    form.models.forEach(markModelDirty);
}

function setModelError(index, errors) {
    serverErrors.value = {
        ...serverErrors.value,
        [`models.${index}.model`]: errors,
    };
}

function buildTestCredentials() {
    const credentials = {};

    for (const [key, value] of Object.entries(form.credentials)) {
        if (typeof value === 'string' && value.trim() !== '') {
            credentials[key] = value.trim();
        }
    }

    return credentials;
}

async function testModel(index) {
    const model = form.models[index];

    if (!model?.model.trim()) {
        setModelError(index, ['Enter a model ID before testing.']);
        return;
    }

    testingModelIndex.value = index;
    setModelError(index, undefined);

    try {
        const payload = {
            type: form.type,
            ai_provider_id: Number(props.providerId),
            model: model.model.trim(),
        };

        const credentials = buildTestCredentials();

        if (Object.keys(credentials).length > 0) {
            payload.credentials = credentials;
        }

        await testAiProviderConnection(payload);

        model.tested = true;

        toast.success('Model test passed', {
            description: `${model.model.trim()} accepted the current provider credentials.`,
        });
    } catch (error) {
        model.tested = false;

        if (error.status === 422 && error.data?.errors) {
            const errors = { ...error.data.errors };
            const modelErrors = errors.model ?? ['Could not test this model.'];

            delete errors.model;

            serverErrors.value = {
                ...serverErrors.value,
                ...errors,
                [`models.${index}.model`]: modelErrors,
            };
            return;
        }

        toast.error('Could not test model', {
            description: error.message,
        });
    } finally {
        testingModelIndex.value = null;
    }
}

async function loadProvider() {
    if (!props.providerId) {
        return;
    }

    loading.value = true;
    serverErrors.value = {};

    try {
        const provider = await getAiProvider(props.providerId);

        form.slug = provider.slug ?? '';
        form.name = provider.name ?? '';
        form.description = provider.description ?? '';
        form.type = provider.type ?? providerTypes[0]?.value ?? 'gemini';
        form.masked_credentials = provider.masked_credentials ?? {};
        form.credentials = { key: '' };
        form.is_active = Boolean(provider.is_active ?? true);
        form.models = (provider.models ?? []).map((model) => ({
            id: model.id,
            model: model.model ?? '',
            name: model.name ?? '',
            tested: true,
        }));

        if (!form.models.length) {
            addModel();
        }
    } catch (error) {
        toast.error('Could not load provider', {
            description: error.message,
        });
        open.value = false;
    } finally {
        loading.value = false;
    }
}

watch(
    () => form.type,
    markAllModelsDirty,
);

watch(
    () => form.credentials,
    markAllModelsDirty,
    { deep: true },
);

watch(open, (isOpen) => {
    if (isOpen) {
        loadProvider();
        return;
    }

    resetForm();
});

watch(
    () => props.providerId,
    () => {
        if (open.value) {
            loadProvider();
        }
    },
);

async function submit() {
    submitting.value = true;
    serverErrors.value = {};

    try {
        const models = form.models
            .map((model) => {
                const payload = {
                    model: model.model.trim(),
                    name: model.name.trim() || null,
                };

                if (model.id) {
                    payload.id = model.id;
                }

                return payload;
            })
            .filter((model) => model.model);

        if (!models.length) {
            serverErrors.value = {
                models: ['Add at least one model for this provider.'],
            };
            return;
        }

        const credentials = buildTestCredentials();

        const provider = await updateAiProvider(props.providerId, {
            name: form.name,
            description: form.description || null,
            type: form.type,
            ...(Object.keys(credentials).length ? { credentials } : {}),
            models,
            is_active: form.is_active,
        });

        toast.success('Provider updated', {
            description: `${provider.name} was saved.`,
        });

        emit('updated', provider);
        open.value = false;
    } catch (error) {
        if (error.status === 422 && error.data?.errors) {
            serverErrors.value = error.data.errors;
            return;
        }

        toast.error('Could not update provider', {
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
                    <DialogTitle>Edit AI provider</DialogTitle>
                    <DialogDescription>
                        Update credentials, models, and availability for this provider.
                    </DialogDescription>
                </DialogHeader>

                <div class="max-h-[min(70vh,32rem)] overflow-y-auto px-5 py-5">
                    <div
                        v-if="loading"
                        class="flex items-center justify-center gap-2 py-16 text-sm"
                    >
                        <LoaderCircleIcon class="size-4 animate-spin" />
                        Loading provider...
                    </div>

                    <FieldGroup
                        v-else
                        class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2"
                    >
                        <Field>
                            <FieldLabel for="edit-provider-name">Display name</FieldLabel>
                            <Input
                                id="edit-provider-name"
                                v-model="form.name"
                                autocomplete="off"
                                placeholder="Work Gemini"
                                :aria-invalid="!!fieldError('name')"
                            />
                            <FieldError :errors="fieldError('name')" />
                        </Field>

                        <Field>
                            <FieldLabel for="edit-provider-slug">Slug</FieldLabel>
                            <Input
                                id="edit-provider-slug"
                                :model-value="form.slug"
                                readonly
                                class="bg-muted/40"
                            />
                        </Field>

                        <Field>
                            <FieldLabel for="edit-provider-type">Provider type</FieldLabel>
                            <Select v-model="form.type">
                                <SelectTrigger id="edit-provider-type" class="w-full">
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
                                <FieldLabel :for="`edit-credential-${credential.key}`">
                                    {{ credential.label }}
                                </FieldLabel>
                                <Input
                                    :id="`edit-credential-${credential.key}`"
                                    v-model="form.credentials[credential.key]"
                                    :name="`edit-ai-provider-credential-${credential.key}`"
                                    :type="credential.type"
                                    :placeholder="credentialPlaceholder(credential)"
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
                            <div class="flex items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <FieldLabel>Models</FieldLabel>
                                    <p class="text-sm text-muted-foreground">
                                        Add, remove, or update models. Test each model after changing credentials.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="app-soft-control shrink-0"
                                    @click="addModel"
                                >
                                    <PlusIcon class="size-4" />
                                    Add model
                                </Button>
                            </div>

                            <div class="mt-3 space-y-3">
                                <div
                                    v-for="(model, index) in form.models"
                                    :key="model.id ?? `new-${index}`"
                                    class="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                                >
                                    <Field>
                                        <div class="flex items-center gap-2">
                                            <FieldLabel :for="`edit-provider-model-${index}`">
                                                Model ID
                                            </FieldLabel>
                                            <Badge
                                                v-if="model.tested"
                                                variant="outline"
                                                class="border-emerald-200 bg-emerald-50 text-emerald-700"
                                            >
                                                Tested
                                            </Badge>
                                        </div>
                                        <Input
                                            :id="`edit-provider-model-${index}`"
                                            v-model="model.model"
                                            autocomplete="off"
                                            :placeholder="modelPlaceholder"
                                            :aria-invalid="!!modelFieldError(index, 'model')"
                                            @input="markModelDirty(model)"
                                        />
                                        <FieldError :errors="modelFieldError(index, 'model')" />
                                    </Field>

                                    <Field>
                                        <FieldLabel :for="`edit-provider-model-name-${index}`">
                                            Display name
                                        </FieldLabel>
                                        <Input
                                            :id="`edit-provider-model-name-${index}`"
                                            v-model="model.name"
                                            autocomplete="off"
                                            :placeholder="displayNamePlaceholder"
                                            :aria-invalid="!!modelFieldError(index, 'name')"
                                        />
                                        <FieldError :errors="modelFieldError(index, 'name')" />
                                    </Field>

                                    <div class="flex items-end gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="app-soft-control"
                                            :disabled="submitting || testingModelIndex !== null || !model.model.trim()"
                                            @click="testModel(index)"
                                        >
                                            <LoaderCircleIcon
                                                v-if="testingModelIndex === index"
                                                class="size-4 animate-spin"
                                            />
                                            Test
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-destructive"
                                            :aria-label="`Remove model ${index + 1}`"
                                            @click="removeModel(index)"
                                        >
                                            <Trash2Icon class="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <FieldError :errors="fieldError('models')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="edit-provider-description">Description</FieldLabel>
                            <Textarea
                                id="edit-provider-description"
                                v-model="form.description"
                                rows="2"
                                placeholder="Optional notes for your team"
                            />
                            <FieldError :errors="fieldError('description')" />
                        </Field>

                        <Field orientation="horizontal" class="sm:col-span-2">
                            <FieldLabel for="edit-provider-active">Active</FieldLabel>
                            <Switch
                                id="edit-provider-active"
                                v-model="form.is_active"
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
                    <Button
                        type="submit"
                        class="rounded-app-control"
                        :disabled="submitting || !canSubmit"
                    >
                        <LoaderCircleIcon
                            v-if="submitting"
                            class="size-4 animate-spin"
                        />
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
