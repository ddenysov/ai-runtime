<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { LoaderCircleIcon, PencilIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
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
import {
    createAgentStateProcessor,
    deleteAgentStateProcessor,
    listAgents,
    listAgentStateProcessors,
    updateAgentStateProcessor,
} from '@/lib/api';
import { slugifyAgentName } from '@/features/agents/agent-tools';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const saving = ref(false);
const deletingId = ref(null);
const error = ref('');
const processors = ref([]);
const agents = ref([]);
const dialogOpen = ref(false);
const editingProcessor = ref(null);
const serverErrors = ref({});

const form = reactive({
    name: '',
    slug: '',
    extractor_agent_id: '',
    instructions: '',
    entity_types: '',
    schema_fields: [],
    default_scope: 'conversation',
    min_confidence: 0.7,
    is_active: true,
});

const schemaFieldTypes = [
    { value: 'string', label: 'Text' },
    { value: 'number', label: 'Number' },
    { value: 'boolean', label: 'Boolean' },
    { value: 'object', label: 'Object' },
    { value: 'array', label: 'List' },
];

const processorNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'State Processors',
})));

const canSave = computed(() => (
    form.name.trim()
    && form.slug.trim()
    && form.extractor_agent_id
    && form.instructions.trim()
));

function resetForm() {
    editingProcessor.value = null;
    serverErrors.value = {};
    form.name = '';
    form.slug = '';
    form.extractor_agent_id = '';
    form.instructions = '';
    form.entity_types = '';
    form.schema_fields = [];
    form.default_scope = 'conversation';
    form.min_confidence = 0.7;
    form.is_active = true;
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function parseEntityTypes(value) {
    return value
        .split(/[\n,]/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function createSchemaField(overrides = {}) {
    return {
        name: '',
        type: 'string',
        required: true,
        description: '',
        ...overrides,
    };
}

function addSchemaField() {
    form.schema_fields.push(createSchemaField());
}

function removeSchemaField(index) {
    form.schema_fields.splice(index, 1);
}

function schemaForField(field) {
    const schema = {
        type: field.type,
    };

    if (field.type === 'array') {
        schema.items = { type: 'string' };
    }

    if (field.description.trim()) {
        schema.description = field.description.trim();
    }

    return schema;
}

function buildResponseSchema() {
    const contentProperties = {};
    const requiredContentFields = [];

    for (const field of form.schema_fields) {
        const name = field.name.trim();

        if (!name) {
            continue;
        }

        contentProperties[name] = schemaForField(field);

        if (field.required) {
            requiredContentFields.push(name);
        }
    }

    const entityTypes = parseEntityTypes(form.entity_types);
    const contentSchema = {
        type: 'object',
        properties: contentProperties,
        additionalProperties: true,
    };

    if (requiredContentFields.length) {
        contentSchema.required = requiredContentFields;
    }

    const mutationProperties = {
        operation: {
            type: 'string',
            enum: ['create', 'upsert', 'delete'],
            description: 'Use upsert when source_key identifies persistent state.',
        },
        scope: {
            type: 'string',
            enum: ['conversation', 'global'],
        },
        entity_type: {
            type: 'string',
            ...(entityTypes.length ? { enum: entityTypes } : {}),
        },
        source_key: {
            type: ['string', 'null'],
            description: 'Stable key for upsert/delete, for example inventory:healing-potion.',
        },
        title: {
            type: ['string', 'null'],
        },
        summary: {
            type: ['string', 'null'],
        },
        content: contentSchema,
        group: {
            type: ['string', 'null'],
        },
        tags: {
            type: 'array',
            items: { type: 'string' },
        },
        confidence: {
            type: 'number',
            minimum: 0,
            maximum: 1,
        },
        evidence: {
            type: ['string', 'null'],
        },
    };

    return {
        type: 'object',
        additionalProperties: false,
        properties: {
            mutations: {
                type: 'array',
                items: {
                    type: 'object',
                    additionalProperties: false,
                    properties: mutationProperties,
                    required: ['operation', 'entity_type'],
                },
            },
        },
        required: ['mutations'],
    };
}

function schemaFieldsFromResponseSchema(schema) {
    const properties = schema?.properties?.mutations?.items?.properties?.content?.properties;
    const required = schema?.properties?.mutations?.items?.properties?.content?.required ?? [];

    if (!properties || typeof properties !== 'object' || Array.isArray(properties)) {
        return [];
    }

    return Object.entries(properties).map(([name, fieldSchema]) => {
        const normalizedType = Array.isArray(fieldSchema?.type)
            ? fieldSchema.type.find((type) => type !== 'null') ?? 'string'
            : fieldSchema?.type;
        const type = schemaFieldTypes.some((item) => item.value === normalizedType)
            ? normalizedType
            : 'string';

        return createSchemaField({
            name,
            type,
            required: required.includes(name),
            description: fieldSchema?.description ?? '',
        });
    });
}

async function fetchProcessors() {
    loading.value = true;
    error.value = '';

    try {
        const response = await listAgentStateProcessors({
            perPage: 50,
            sort: 'name',
        });

        processors.value = response.data ?? [];
    } catch (fetchError) {
        error.value = fetchError.message;
    } finally {
        loading.value = false;
    }
}

async function fetchAgents() {
    const response = await listAgents({
        isActive: true,
        perPage: 100,
        sort: 'name',
        includeProviderModel: false,
        includeToolsCount: false,
        includeVersionsCount: false,
    });

    agents.value = response.data ?? [];
}

function openCreateDialog() {
    resetForm();
    dialogOpen.value = true;
}

function openEditDialog(processor) {
    editingProcessor.value = processor;
    serverErrors.value = {};
    form.name = processor.name ?? '';
    form.slug = processor.slug ?? '';
    form.extractor_agent_id = String(processor.extractor_agent_id ?? '');
    form.instructions = processor.instructions ?? '';
    form.entity_types = (processor.entity_types ?? []).join('\n');
    form.schema_fields = schemaFieldsFromResponseSchema(processor.response_schema);
    form.default_scope = processor.default_scope ?? 'conversation';
    form.min_confidence = processor.min_confidence ?? 0.7;
    form.is_active = Boolean(processor.is_active);
    dialogOpen.value = true;
}

async function saveProcessor() {
    serverErrors.value = {};

    const payload = {
        name: form.name.trim(),
        slug: form.slug.trim(),
        extractor_agent_id: Number(form.extractor_agent_id),
        instructions: form.instructions.trim(),
        entity_types: parseEntityTypes(form.entity_types),
        response_schema: buildResponseSchema(),
        default_scope: form.default_scope,
        min_confidence: Number(form.min_confidence),
        is_active: form.is_active,
    };

    saving.value = true;

    try {
        if (editingProcessor.value) {
            await updateAgentStateProcessor(editingProcessor.value.id, payload);
            toast.success('State processor updated');
        } else {
            await createAgentStateProcessor(payload);
            toast.success('State processor created');
        }

        dialogOpen.value = false;
        resetForm();
        await fetchProcessors();
    } catch (saveError) {
        serverErrors.value = saveError.data?.errors ?? {};
        toast.error('Could not save processor', {
            description: saveError.message,
        });
    } finally {
        saving.value = false;
    }
}

async function deleteProcessor(processor) {
    deletingId.value = processor.id;

    try {
        await deleteAgentStateProcessor(processor.id);
        toast.success('State processor deleted');
        await fetchProcessors();
    } catch (deleteError) {
        toast.error('Could not delete processor', {
            description: deleteError.message,
        });
    } finally {
        deletingId.value = null;
    }
}

function statusLabel(processor) {
    return processor.is_active ? 'Active' : 'Inactive';
}

watch(() => form.name, (name) => {
    if (!editingProcessor.value && name.trim()) {
        form.slug = slugifyAgentName(name);
    }
});

watch(dialogOpen, (isOpen) => {
    if (!isOpen) {
        resetForm();
    }
});

onMounted(async () => {
    await Promise.all([
        fetchProcessors(),
        fetchAgents(),
    ]);
});
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="processorNavigation"
    >
        <PageHeader title="State Processors">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'State Processors']" />
            </template>

            <template #actions>
                <Button class="rounded-app-control" @click="openCreateDialog">
                    <PlusIcon class="size-4" />
                    New processor
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <Card class="app-surface">
                <CardHeader>
                    <CardTitle>Configured processors</CardTitle>
                    <CardDescription>
                        Post-response extractors that convert agent conversations into validated runtime state.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="loading" class="app-muted-text flex items-center gap-2">
                        <LoaderCircleIcon class="size-4 animate-spin" />
                        Loading processors...
                    </div>
                    <div v-else-if="error" class="rounded-app-container border border-destructive/40 p-4 text-sm text-destructive">
                        {{ error }}
                    </div>
                    <div v-else-if="!processors.length" class="rounded-app-container border border-dashed px-4 py-10 text-center">
                        <p class="font-medium">No processors yet</p>
                        <p class="app-muted-text mt-1 text-sm">
                            Create one and assign it to an agent from the agent details page.
                        </p>
                    </div>
                    <div v-else class="grid gap-4 lg:grid-cols-2">
                        <div
                            v-for="processor in processors"
                            :key="processor.id"
                            class="rounded-app-container border p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate font-semibold">{{ processor.name }}</h3>
                                        <Badge :variant="processor.is_active ? 'default' : 'secondary'">
                                            {{ statusLabel(processor) }}
                                        </Badge>
                                    </div>
                                    <p class="app-muted-text mt-1 text-sm">{{ processor.slug }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="app-soft-control"
                                        @click="openEditDialog(processor)"
                                    >
                                        <PencilIcon class="size-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="app-soft-control text-destructive"
                                        :disabled="deletingId === processor.id"
                                        @click="deleteProcessor(processor)"
                                    >
                                        <LoaderCircleIcon v-if="deletingId === processor.id" class="size-4 animate-spin" />
                                        <Trash2Icon v-else class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <div class="app-muted-text mt-4 grid gap-2 text-sm md:grid-cols-2">
                                <p>Extractor: {{ processor.extractor_agent?.name ?? 'Unknown agent' }}</p>
                                <p>Scope: {{ processor.default_scope }}</p>
                                <p>Min confidence: {{ processor.min_confidence }}</p>
                                <p>Assignments: {{ processor.assignments_count ?? 0 }}</p>
                            </div>

                            <p class="mt-4 line-clamp-3 text-sm">
                                {{ processor.instructions }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogScrollContent class="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{{ editingProcessor ? 'Edit state processor' : 'Create state processor' }}</DialogTitle>
                    <DialogDescription>
                        Configure an extractor agent and the structured response contract used for state mutations.
                    </DialogDescription>
                </DialogHeader>

                <FieldGroup class="grid gap-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Field>
                            <FieldLabel>Name</FieldLabel>
                            <Input v-model="form.name" />
                            <FieldError :errors="fieldError('name')" />
                        </Field>
                        <Field>
                            <FieldLabel>Slug</FieldLabel>
                            <Input v-model="form.slug" />
                            <FieldError :errors="fieldError('slug')" />
                        </Field>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <Field class="md:col-span-2">
                            <FieldLabel>Extractor agent</FieldLabel>
                            <Select v-model="form.extractor_agent_id">
                                <SelectTrigger class="app-soft-control">
                                    <SelectValue placeholder="Select extractor agent" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="agent in agents"
                                        :key="agent.id"
                                        :value="String(agent.id)"
                                    >
                                        {{ agent.name }} ({{ agent.slug }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <FieldError :errors="fieldError('extractor_agent_id')" />
                        </Field>
                        <Field>
                            <FieldLabel>Default scope</FieldLabel>
                            <Select v-model="form.default_scope">
                                <SelectTrigger class="app-soft-control">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="conversation">Conversation</SelectItem>
                                    <SelectItem value="global">Global</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>

                    <Field>
                        <FieldLabel>Instructions</FieldLabel>
                        <Textarea
                            v-model="form.instructions"
                            rows="6"
                            placeholder="Extract character inventory, spell slots and conditions from the latest turn."
                        />
                        <FieldError :errors="fieldError('instructions')" />
                    </Field>

                    <Field>
                        <FieldLabel>Entity types</FieldLabel>
                        <Textarea
                            v-model="form.entity_types"
                            rows="3"
                            placeholder="spell_slots&#10;inventory_item&#10;condition"
                        />
                        <FieldError :errors="fieldError('entity_types')" />
                    </Field>

                    <Field>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <FieldLabel>Content schema fields</FieldLabel>
                                <p class="app-muted-text text-sm">
                                    Add only the fields saved into state content. The mutations wrapper is generated automatically.
                                </p>
                            </div>
                            <Button type="button" variant="outline" size="sm" class="app-soft-control" @click="addSchemaField">
                                <PlusIcon class="size-4" />
                                Add field
                            </Button>
                        </div>

                        <div v-if="form.schema_fields.length" class="mt-3 grid gap-3">
                            <div
                                v-for="(field, index) in form.schema_fields"
                                :key="index"
                                class="rounded-app-container grid gap-3 border p-3 md:grid-cols-[1fr_150px_110px_auto]"
                            >
                                <Input v-model="field.name" placeholder="field_name" />
                                <Select v-model="field.type">
                                    <SelectTrigger class="app-soft-control">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="type in schemaFieldTypes"
                                            :key="type.value"
                                            :value="type.value"
                                        >
                                            {{ type.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <div class="flex items-center justify-between gap-2 rounded-app-container border px-3">
                                    <span class="text-sm">Required</span>
                                    <Switch v-model="field.required" />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="app-soft-control text-destructive"
                                    @click="removeSchemaField(index)"
                                >
                                    <Trash2Icon class="size-4" />
                                </Button>
                                <Input
                                    v-model="field.description"
                                    class="md:col-span-4"
                                    placeholder="Optional description for the extractor"
                                />
                            </div>
                        </div>
                        <p v-else class="app-muted-text mt-3 rounded-app-container border border-dashed p-4 text-sm">
                            No content fields yet. The processor can still return mutations with free-form content.
                        </p>
                        <p class="app-muted-text mt-2 text-sm">
                            Example: item_name as Text, quantity as Number, equipped as Boolean.
                        </p>
                        <FieldError :errors="fieldError('response_schema')" />
                    </Field>

                    <div class="grid gap-4 md:grid-cols-2">
                        <Field>
                            <FieldLabel>Minimum confidence</FieldLabel>
                            <Input v-model="form.min_confidence" type="number" step="0.05" min="0" max="1" />
                        </Field>
                        <Field class="flex flex-row items-center justify-between rounded-app-container border p-4">
                            <div>
                                <FieldLabel>Active</FieldLabel>
                                <p class="app-muted-text text-sm">Inactive processors are ignored at runtime.</p>
                            </div>
                            <Switch v-model="form.is_active" />
                        </Field>
                    </div>
                </FieldGroup>

                <DialogFooter>
                    <Button variant="outline" class="app-soft-control" :disabled="saving" @click="dialogOpen = false">
                        Cancel
                    </Button>
                    <Button :disabled="saving || !canSave" @click="saveProcessor">
                        <LoaderCircleIcon v-if="saving" class="size-4 animate-spin" />
                        {{ editingProcessor ? 'Save changes' : 'Create processor' }}
                    </Button>
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>
    </AppShell>
</template>
