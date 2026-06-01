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
import {
    createAgent,
    listAiProviders,
    listMcpServers,
    listMcpServerTools,
} from '@/lib/api';
import {
    commaList,
    linesToList,
    runtimeTools,
    slugifyAgentName,
} from '@/features/agents/agent-tools';

const open = defineModel('open', {
    type: Boolean,
    default: false,
});

const emit = defineEmits(['created']);

const submitting = ref(false);
const loadingProviders = ref(false);
const slugTouched = ref(false);
const slugFieldActive = ref(false);
const serverErrors = ref({});
const providerError = ref('');
const providerModels = ref([]);
const discoveredMcpTools = ref([]);
const loadingMcpTools = ref(false);
const mcpToolsError = ref('');

const availableTools = computed(() => [
    ...runtimeTools,
    ...discoveredMcpTools.value,
]);

const form = reactive({
    name: '',
    slug: '',
    description: '',
    ai_provider_model_id: '',
    background: '',
    steps: '',
    output: '',
    subagents: '',
    input_modes: 'text/plain',
    output_modes: 'text/plain',
    input_schema: '',
    output_schema: '',
    temperature: '',
    max_tokens: '',
    timeout_seconds: 120,
    history_context_window: 50000,
    is_active: true,
    tools: runtimeTools.map((tool) => ({
        slug: tool.slug,
        is_enabled: false,
    })),
});

const canSubmit = computed(() => (
    form.name.trim()
    && form.slug.trim()
    && form.ai_provider_model_id
    && form.background.trim()
));

function resetForm() {
    form.name = '';
    form.slug = '';
    form.description = '';
    form.ai_provider_model_id = '';
    form.background = '';
    form.steps = '';
    form.output = '';
    form.subagents = '';
    form.input_modes = 'text/plain';
    form.output_modes = 'text/plain';
    form.input_schema = '';
    form.output_schema = '';
    form.temperature = '';
    form.max_tokens = '';
    form.timeout_seconds = 120;
    form.history_context_window = 50000;
    form.is_active = true;
    form.tools = runtimeTools.map((tool) => ({
        slug: tool.slug,
        is_enabled: false,
    }));
    discoveredMcpTools.value = [];
    loadingMcpTools.value = false;
    mcpToolsError.value = '';
    slugTouched.value = false;
    slugFieldActive.value = false;
    serverErrors.value = {};
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function setFieldError(name, errors) {
    serverErrors.value = {
        ...serverErrors.value,
        [name]: errors,
    };
}

function syncSlugFromName() {
    if (slugTouched.value || !form.name.trim()) {
        return;
    }

    form.slug = slugifyAgentName(form.name);
}

function parseJsonObject(value, field) {
    if (!value.trim()) {
        return null;
    }

    try {
        const parsed = JSON.parse(value);

        if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
            setFieldError(field, ['Enter a JSON object.']);
            return undefined;
        }

        return parsed;
    } catch {
        setFieldError(field, ['Enter valid JSON.']);
        return undefined;
    }
}

async function fetchProviderModels() {
    loadingProviders.value = true;
    providerError.value = '';

    try {
        const response = await listAiProviders({
            isActive: true,
            includeModelsCount: false,
            includeModels: true,
            perPage: 50,
            sort: 'name',
        });

        providerModels.value = (response.data ?? [])
            .flatMap((provider) => (provider.models ?? [])
                .filter((model) => model.is_active)
                .map((model) => ({
                    id: String(model.id),
                    label: `${provider.name} / ${model.name}`,
                    description: model.model,
                })));
    } catch (error) {
        providerError.value = error.message;
    } finally {
        loadingProviders.value = false;
    }
}

async function loadMcpTools() {
    loadingMcpTools.value = true;
    mcpToolsError.value = '';

    try {
        const response = await listMcpServers({
            enabled: true,
            perPage: 100,
        });

        const servers = response.data ?? [];
        const tools = [];

        for (const server of servers) {
            const toolResponse = await listMcpServerTools(server.uuid);

            for (const tool of toolResponse.data ?? []) {
                tools.push({
                    slug: `mcp:${server.uuid}:${tool.name}`,
                    label: `${server.name}: ${tool.title || tool.name}`,
                    description: tool.description || `Call ${tool.name} through ${server.name}.`,
                    config: {
                        server_uuid: server.uuid,
                        server_name: server.name,
                        tool_name: tool.name,
                        title: tool.title,
                        description: tool.description,
                        input_schema: tool.input_schema ?? {},
                    },
                });
            }
        }

        discoveredMcpTools.value = tools;

        for (const tool of tools) {
            if (!form.tools.some((item) => item.slug === tool.slug)) {
                form.tools.push({
                    slug: tool.slug,
                    is_enabled: false,
                    config: tool.config,
                });
            }
        }
    } catch (error) {
        mcpToolsError.value = error.message;
    } finally {
        loadingMcpTools.value = false;
    }
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
    if (isOpen) {
        fetchProviderModels();
        return;
    }

    resetForm();
});

async function submit() {
    submitting.value = true;
    serverErrors.value = {};

    try {
        const inputSchema = parseJsonObject(form.input_schema, 'input_schema');
        const outputSchema = parseJsonObject(form.output_schema, 'output_schema');

        if (inputSchema === undefined || outputSchema === undefined) {
            return;
        }

        const agent = await createAgent({
            slug: form.slug,
            name: form.name,
            description: form.description || null,
            ai_provider_model_id: Number(form.ai_provider_model_id),
            instructions: {
                background: linesToList(form.background),
                steps: linesToList(form.steps),
                output: linesToList(form.output),
            },
            input_modes: commaList(form.input_modes),
            output_modes: commaList(form.output_modes),
            subagents: commaList(form.subagents),
            tools: form.tools.map((tool) => ({
                slug: tool.slug,
                is_enabled: tool.is_enabled,
                config: tool.config ?? null,
            })),
            input_schema: inputSchema,
            output_schema: outputSchema,
            temperature: form.temperature === '' ? null : Number(form.temperature),
            max_tokens: form.max_tokens === '' ? null : Number(form.max_tokens),
            timeout_seconds: Number(form.timeout_seconds),
            history_context_window: Number(form.history_context_window),
            is_active: form.is_active,
        });

        toast.success('Agent created', {
            description: `${agent.name} is ready for runtime execution.`,
        });

        emit('created', agent);
        open.value = false;
    } catch (error) {
        if (error.status === 422 && error.data?.errors) {
            serverErrors.value = error.data.errors;
            return;
        }

        toast.error('Could not create agent', {
            description: error.message,
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-3xl">
            <form
                class="flex flex-col"
                autocomplete="off"
                @submit.prevent="submit"
            >
                <DialogHeader class="border-b px-5 py-4 text-left">
                    <DialogTitle>New agent</DialogTitle>
                    <DialogDescription>
                        Configure a runtime-ready agent with provider model, instructions, tools and schemas.
                    </DialogDescription>
                </DialogHeader>

                <div class="max-h-[min(72vh,38rem)] overflow-y-auto px-5 py-5">
                    <FieldGroup class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                        <Field>
                            <FieldLabel for="agent-name">Display name</FieldLabel>
                            <Input
                                id="agent-name"
                                v-model="form.name"
                                autocomplete="off"
                                placeholder="Runtime Assistant"
                                :aria-invalid="!!fieldError('name')"
                                @blur="syncSlugFromName"
                            />
                            <FieldError :errors="fieldError('name')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-slug">Runtime slug</FieldLabel>
                            <Input
                                id="agent-slug"
                                v-model="form.slug"
                                autocomplete="off"
                                placeholder="runtime-assistant"
                                :readonly="!slugFieldActive"
                                :aria-invalid="!!fieldError('slug')"
                                @focus="slugFieldActive = true"
                                @input="slugTouched = true"
                            />
                            <FieldError :errors="fieldError('slug')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="agent-provider-model">Provider model</FieldLabel>
                            <Select v-model="form.ai_provider_model_id" :disabled="loadingProviders">
                                <SelectTrigger id="agent-provider-model" class="w-full">
                                    <SelectValue :placeholder="loadingProviders ? 'Loading models...' : 'Select active provider model'" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="model in providerModels"
                                        :key="model.id"
                                        :value="model.id"
                                    >
                                        {{ model.label }} · {{ model.description }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="providerError" class="text-sm text-destructive">
                                {{ providerError }}
                            </p>
                            <FieldError :errors="fieldError('ai_provider_model_id')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="agent-description">Description</FieldLabel>
                            <Textarea
                                id="agent-description"
                                v-model="form.description"
                                rows="2"
                                placeholder="What this agent is responsible for"
                            />
                            <FieldError :errors="fieldError('description')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="agent-background">Background instructions</FieldLabel>
                            <Textarea
                                id="agent-background"
                                v-model="form.background"
                                rows="4"
                                placeholder="One instruction per line. This becomes the SystemPrompt background."
                                :aria-invalid="!!fieldError('instructions.background')"
                            />
                            <FieldError :errors="fieldError('instructions.background')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-steps">Steps</FieldLabel>
                            <Textarea
                                id="agent-steps"
                                v-model="form.steps"
                                rows="4"
                                placeholder="Understand the task&#10;Use tools only when needed"
                            />
                            <FieldError :errors="fieldError('instructions.steps')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-output">Output rules</FieldLabel>
                            <Textarea
                                id="agent-output"
                                v-model="form.output"
                                rows="4"
                                placeholder="Return concise, verifiable output"
                            />
                            <FieldError :errors="fieldError('instructions.output')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-input-modes">Input modes</FieldLabel>
                            <Input
                                id="agent-input-modes"
                                v-model="form.input_modes"
                                placeholder="text/plain"
                            />
                            <FieldError :errors="fieldError('input_modes')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-output-modes">Output modes</FieldLabel>
                            <Input
                                id="agent-output-modes"
                                v-model="form.output_modes"
                                placeholder="text/plain, application/json"
                            />
                            <FieldError :errors="fieldError('output_modes')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <FieldLabel>Tools</FieldLabel>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="app-soft-control"
                                    :disabled="loadingMcpTools"
                                    @click="loadMcpTools"
                                >
                                    <LoaderCircleIcon
                                        v-if="loadingMcpTools"
                                        class="size-4 animate-spin"
                                    />
                                    Load MCP tools
                                </Button>
                            </div>
                            <p v-if="mcpToolsError" class="mt-2 text-sm text-destructive">
                                {{ mcpToolsError }}
                            </p>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <div
                                    v-for="tool in form.tools"
                                    :key="tool.slug"
                                    class="rounded-lg border bg-muted/20 p-3"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-medium">
                                                {{ availableTools.find((item) => item.slug === tool.slug)?.label }}
                                            </p>
                                            <p class="app-muted-text mt-1 text-sm">
                                                {{ availableTools.find((item) => item.slug === tool.slug)?.description }}
                                            </p>
                                        </div>
                                        <Switch v-model:checked="tool.is_enabled" />
                                    </div>
                                </div>
                            </div>
                            <FieldError :errors="fieldError('tools')" />
                        </Field>

                        <Field class="sm:col-span-2">
                            <FieldLabel for="agent-subagents">Allowed subagents</FieldLabel>
                            <Input
                                id="agent-subagents"
                                v-model="form.subagents"
                                placeholder="docs-assistant, topic-selector-assistant"
                            />
                            <FieldError :errors="fieldError('subagents')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-temperature">Temperature</FieldLabel>
                            <Input
                                id="agent-temperature"
                                v-model="form.temperature"
                                type="number"
                                min="0"
                                max="2"
                                step="0.01"
                                placeholder="0.70"
                            />
                            <FieldError :errors="fieldError('temperature')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-max-tokens">Max tokens</FieldLabel>
                            <Input
                                id="agent-max-tokens"
                                v-model="form.max_tokens"
                                type="number"
                                min="1"
                                placeholder="8192"
                            />
                            <FieldError :errors="fieldError('max_tokens')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-timeout">Timeout seconds</FieldLabel>
                            <Input
                                id="agent-timeout"
                                v-model="form.timeout_seconds"
                                type="number"
                                min="1"
                                max="600"
                            />
                            <FieldError :errors="fieldError('timeout_seconds')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-history-window">History context window</FieldLabel>
                            <Input
                                id="agent-history-window"
                                v-model="form.history_context_window"
                                type="number"
                                min="1000"
                            />
                            <FieldError :errors="fieldError('history_context_window')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-input-schema">Input schema JSON</FieldLabel>
                            <Textarea
                                id="agent-input-schema"
                                v-model="form.input_schema"
                                rows="4"
                                placeholder="{ &quot;type&quot;: &quot;object&quot; }"
                            />
                            <FieldError :errors="fieldError('input_schema')" />
                        </Field>

                        <Field>
                            <FieldLabel for="agent-output-schema">Output schema JSON</FieldLabel>
                            <Textarea
                                id="agent-output-schema"
                                v-model="form.output_schema"
                                rows="4"
                                placeholder="{ &quot;type&quot;: &quot;object&quot; }"
                            />
                            <FieldError :errors="fieldError('output_schema')" />
                        </Field>

                        <Field orientation="horizontal" class="sm:col-span-2">
                            <FieldLabel for="agent-active">Active</FieldLabel>
                            <Switch
                                id="agent-active"
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
                        Create agent
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
