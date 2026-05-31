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
import { createAiProvider, testAiProviderConnection } from '@/lib/api';
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
const testingModelIndex = ref(null);
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
    models: [
        {
            model: '',
            name: '',
            tested: false,
        },
    ],
    is_active: true,
});

const selectedType = computed(() => findProviderType(form.type));
const modelPlaceholder = computed(() => selectedType.value?.modelPlaceholder ?? 'gemini-1.5-flash');
const displayNamePlaceholder = computed(() => selectedType.value?.modelNamePlaceholder ?? 'Gemini 1.5 Flash');
const hasAtLeastOneModel = computed(() => form.models.some((model) => model.model.trim()));
const canSubmit = computed(() => hasAtLeastOneModel.value);

function resetForm() {
    form.name = '';
    form.slug = '';
    form.description = '';
    form.type = providerTypes[0]?.value ?? 'gemini';
    form.credentials = { key: '' };
    form.models = [
        {
            model: '',
            name: '',
            tested: false,
        },
    ];
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

function modelFieldError(index, name) {
    return fieldError(`models.${index}.${name}`);
}

function addModel() {
    form.models.push({
        model: '',
        name: '',
        tested: false,
    });
}

function removeModel(index) {
    if (form.models.length === 1) {
        form.models[0].model = '';
        form.models[0].name = '';
        form.models[0].tested = false;
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

async function testModel(index) {
    const model = form.models[index];

    if (!model?.model.trim()) {
        setModelError(index, ['Enter a model ID before testing.']);
        return;
    }

    testingModelIndex.value = index;
    setModelError(index, undefined);

    try {
        await testAiProviderConnection({
            type: form.type,
            credentials: { ...form.credentials },
            model: model.model.trim(),
        });

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
    if (!isOpen) {
        resetForm();
    }
});

async function submit() {
    submitting.value = true;
    serverErrors.value = {};

    try {
        const models = form.models
            .map((model) => ({
                model: model.model.trim(),
                name: model.name.trim() || null,
            }))
            .filter((model) => model.model);

        if (!models.length) {
            serverErrors.value = {
                models: ['Add at least one model to test this provider.'],
            };
            return;
        }

        const provider = await createAiProvider({
            slug: form.slug,
            name: form.name,
            description: form.description || null,
            type: form.type,
            credentials: { ...form.credentials },
            models,
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
                        Connect a model vendor with encrypted credentials and the models it supports.
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
                            <div class="flex items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <FieldLabel>Models</FieldLabel>
                                    <p class="text-sm text-muted-foreground">
                                        Add at least one model. Use Test to verify credentials for each model before creating the provider.
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
                                    :key="index"
                                    class="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                                >
                                    <Field>
                                        <div class="flex items-center gap-2">
                                            <FieldLabel :for="`provider-model-${index}`">
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
                                            :id="`provider-model-${index}`"
                                            v-model="model.model"
                                            autocomplete="off"
                                            :placeholder="modelPlaceholder"
                                            :aria-invalid="!!modelFieldError(index, 'model')"
                                            @input="markModelDirty(model)"
                                        />
                                        <FieldError :errors="modelFieldError(index, 'model')" />
                                    </Field>

                                    <Field>
                                        <FieldLabel :for="`provider-model-name-${index}`">
                                            Display name
                                        </FieldLabel>
                                        <Input
                                            :id="`provider-model-name-${index}`"
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
                    <Button
                        type="submit"
                        class="rounded-app-control"
                        :disabled="submitting || !canSubmit"
                    >
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
