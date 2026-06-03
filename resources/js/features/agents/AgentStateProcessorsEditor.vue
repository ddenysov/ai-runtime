<script setup>
import { computed, ref, watch } from 'vue';
import { CheckIcon, LoaderCircleIcon, PencilIcon, PlusIcon, Trash2Icon, XIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
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
    listAgentStateProcessors,
    updateAgent,
} from '@/lib/api';

const editing = defineModel('editing', {
    type: Boolean,
    default: false,
});

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
    assignments: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['saved']);

const processors = ref([]);
const rows = ref([]);
const loadingProcessors = ref(false);
const saving = ref(false);
const error = ref('');
const serverErrors = ref({});

const availableProcessors = computed(() => processors.value.filter((processor) => (
    !rows.value.some((row) => row.agent_state_processor_id === processor.id)
)));

function assignmentToRow(assignment) {
    return {
        agent_state_processor_id: assignment.agent_state_processor_id ?? assignment.processor?.id,
        is_enabled: assignment.is_enabled ?? true,
        trigger: assignment.trigger ?? 'after_response',
        scope: assignment.scope ?? assignment.processor?.default_scope ?? 'conversation',
        injection_title: assignment.injection_title ?? 'Runtime State',
        injection_instructions: assignment.injection_instructions ?? '',
        state_filters: assignment.state_filters ? JSON.stringify(assignment.state_filters, null, 2) : '',
        sort_order: assignment.sort_order ?? 0,
    };
}

function resetRows() {
    rows.value = props.assignments.map(assignmentToRow);
    serverErrors.value = {};
    error.value = '';
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function processorLabel(id) {
    const processor = processors.value.find((item) => item.id === id);

    return processor ? `${processor.name} (${processor.slug})` : `Processor #${id}`;
}

function processorDescription(id) {
    const processor = processors.value.find((item) => item.id === id);

    if (!processor) {
        return 'Configured state processor.';
    }

    return `Extractor: ${processor.extractor_agent?.name ?? 'Unknown'} · Confidence ${processor.min_confidence}`;
}

async function fetchProcessors() {
    loadingProcessors.value = true;
    error.value = '';

    try {
        const response = await listAgentStateProcessors({
            isActive: true,
            perPage: 100,
            sort: 'name',
        });

        processors.value = response.data ?? [];
    } catch (fetchError) {
        error.value = fetchError.message;
    } finally {
        loadingProcessors.value = false;
    }
}

function startEdit() {
    resetRows();
    editing.value = true;

    if (!processors.value.length) {
        fetchProcessors();
    }
}

function cancelEdit() {
    editing.value = false;
    resetRows();
}

function addProcessor(id) {
    const processor = processors.value.find((item) => item.id === Number(id));

    if (!processor) {
        return;
    }

    rows.value.push({
        agent_state_processor_id: processor.id,
        is_enabled: true,
        trigger: 'after_response',
        scope: processor.default_scope ?? 'conversation',
        injection_title: processor.name,
        injection_instructions: '',
        state_filters: '',
        sort_order: rows.value.length,
    });
}

function removeProcessor(index) {
    rows.value.splice(index, 1);
}

function parseFilters(value, index) {
    if (!value.trim()) {
        return null;
    }

    try {
        const parsed = JSON.parse(value);

        if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
            serverErrors.value = {
                ...serverErrors.value,
                [`state_processors.${index}.state_filters`]: ['Enter a JSON object.'],
            };
            return undefined;
        }

        return parsed;
    } catch {
        serverErrors.value = {
            ...serverErrors.value,
            [`state_processors.${index}.state_filters`]: ['Enter valid JSON.'],
        };
        return undefined;
    }
}

async function save() {
    serverErrors.value = {};

    const payloadRows = [];

    for (const [index, row] of rows.value.entries()) {
        const filters = parseFilters(row.state_filters, index);

        if (filters === undefined) {
            return;
        }

        payloadRows.push({
            agent_state_processor_id: row.agent_state_processor_id,
            is_enabled: row.is_enabled,
            trigger: row.trigger,
            scope: row.scope,
            injection_title: row.injection_title || 'Runtime State',
            injection_instructions: row.injection_instructions || null,
            state_filters: filters,
            sort_order: index,
        });
    }

    saving.value = true;
    error.value = '';

    try {
        const updated = await updateAgent(props.agentId, {
            state_processors: payloadRows,
        });

        emit('saved', updated);
        editing.value = false;
        toast.success('State processors updated');
    } catch (saveError) {
        serverErrors.value = saveError.data?.errors ?? {};
        error.value = saveError.message;
    } finally {
        saving.value = false;
    }
}

watch(() => props.assignments, resetRows, {
    immediate: true,
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="font-medium">Assigned state processors</p>
                <p class="app-muted-text text-sm">
                    Run extractor agents after responses and inject their saved state into future prompts.
                </p>
            </div>
            <Button
                v-if="!editing"
                variant="outline"
                size="sm"
                class="app-soft-control"
                @click="startEdit"
            >
                <PencilIcon class="size-4" />
                Edit
            </Button>
        </div>

        <template v-if="editing">
            <div v-if="error" class="rounded-app-container border border-destructive/40 p-3 text-sm text-destructive">
                {{ error }}
            </div>

            <div class="flex items-center gap-2">
                <Select @update:model-value="addProcessor">
                    <SelectTrigger class="app-soft-control max-w-md">
                        <SelectValue :placeholder="loadingProcessors ? 'Loading processors...' : 'Add processor'" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="processor in availableProcessors"
                            :key="processor.id"
                            :value="String(processor.id)"
                        >
                            {{ processor.name }} ({{ processor.slug }})
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    class="app-soft-control"
                    :disabled="loadingProcessors"
                    @click="fetchProcessors"
                >
                    <LoaderCircleIcon v-if="loadingProcessors" class="size-4 animate-spin" />
                    <PlusIcon v-else class="size-4" />
                    Load
                </Button>
            </div>

            <div
                v-for="(row, index) in rows"
                :key="row.agent_state_processor_id"
                class="rounded-app-container space-y-4 border p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ processorLabel(row.agent_state_processor_id) }}</p>
                        <p class="app-muted-text text-sm">{{ processorDescription(row.agent_state_processor_id) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Switch v-model="row.is_enabled" />
                        <Button
                            variant="outline"
                            size="icon"
                            class="app-soft-control text-destructive"
                            @click="removeProcessor(index)"
                        >
                            <Trash2Icon class="size-4" />
                        </Button>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <Field>
                        <FieldLabel>Injection title</FieldLabel>
                        <Input v-model="row.injection_title" />
                    </Field>
                    <Field>
                        <FieldLabel>Scope</FieldLabel>
                        <Select v-model="row.scope">
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
                    <FieldLabel>Injection instructions</FieldLabel>
                    <Textarea
                        v-model="row.injection_instructions"
                        rows="2"
                        placeholder="This is authoritative information about the character."
                    />
                </Field>

                <Field>
                    <FieldLabel>State filters JSON</FieldLabel>
                    <Textarea
                        v-model="row.state_filters"
                        rows="3"
                        placeholder='{"entity_types":["spell_slots","inventory_item"],"limit":50}'
                    />
                    <FieldError :errors="fieldError(`state_processors.${index}.state_filters`)" />
                </Field>
            </div>

            <p v-if="!rows.length" class="app-muted-text rounded-app-container border border-dashed p-4 text-sm">
                No processors selected.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button :disabled="saving" @click="save">
                    <LoaderCircleIcon v-if="saving" class="size-4 animate-spin" />
                    <CheckIcon v-else class="size-4" />
                    Save processors
                </Button>
                <Button
                    variant="outline"
                    class="app-soft-control"
                    :disabled="saving"
                    @click="cancelEdit"
                >
                    <XIcon class="size-4" />
                    Cancel
                </Button>
            </div>
        </template>

        <template v-else>
            <div v-if="assignments.length" class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="assignment in assignments"
                    :key="assignment.id ?? assignment.agent_state_processor_id"
                    class="rounded-app-container border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ assignment.processor?.name ?? 'State processor' }}</p>
                            <p class="app-muted-text text-sm">
                                {{ assignment.injection_title ?? 'Runtime State' }}
                            </p>
                        </div>
                        <span class="app-muted-text text-xs">
                            {{ assignment.is_enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <p class="app-muted-text mt-3 text-sm">
                        {{ assignment.injection_instructions || 'No extra injection instructions.' }}
                    </p>
                </div>
            </div>
            <p v-else class="app-muted-text rounded-app-container border border-dashed p-4 text-sm">
                No state processors assigned.
            </p>
        </template>
    </div>
</template>
