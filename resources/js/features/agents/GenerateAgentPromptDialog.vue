<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { LoaderCircleIcon, SparklesIcon } from '@lucide/vue';
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { generateAgentInstructions, getSettings, updateAgent } from '@/lib/api';
import { linesToList, listToLines } from '@/features/agents/agent-tools';

const open = defineModel('open', {
    type: Boolean,
    default: false,
});

const props = defineProps({
    agent: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['saved']);

const loadingSettings = ref(false);
const generating = ref(false);
const saving = ref(false);
const generatorConfigured = ref(false);
const generatorAgentName = ref('');
const error = ref('');
const hasGenerated = ref(false);

const brief = ref('');
const feedback = ref('');
const draft = reactive({
    background: '',
    steps: '',
    output: '',
});

const canGenerate = computed(() => (
    generatorConfigured.value
    && !generating.value
    && !saving.value
    && brief.value.trim().length > 0
));

const canRefine = computed(() => (
    canGenerate.value
    && hasGenerated.value
    && feedback.value.trim().length > 0
));

const canSave = computed(() => (
    hasGenerated.value
    && !generating.value
    && !saving.value
    && linesToList(draft.background).length > 0
));

function resetState() {
    brief.value = props.agent?.description?.trim() ?? '';
    feedback.value = '';
    draft.background = listToLines(props.agent?.instructions?.background);
    draft.steps = listToLines(props.agent?.instructions?.steps);
    draft.output = listToLines(props.agent?.instructions?.output);
    error.value = '';
    hasGenerated.value = false;
    generating.value = false;
    saving.value = false;
}

function draftInstructionsPayload() {
    if (!hasGenerated.value) {
        return undefined;
    }

    return {
        background: linesToList(draft.background),
        steps: linesToList(draft.steps),
        output: linesToList(draft.output),
    };
}

async function loadSettings() {
    loadingSettings.value = true;

    try {
        const response = await getSettings();
        const generatorAgentId = response.data?.prompts?.prompt_generator_agent_id ?? null;

        generatorConfigured.value = Boolean(generatorAgentId);
        generatorAgentName.value = generatorAgentId ? 'configured agent' : '';
    } catch (fetchError) {
        generatorConfigured.value = false;
        error.value = fetchError.message;
    } finally {
        loadingSettings.value = false;
    }
}

async function generate(options = {}) {
    if (!canGenerate.value && !options.forceRefine) {
        return;
    }

    generating.value = true;
    error.value = '';

    try {
        const response = await generateAgentInstructions(props.agent.id, {
            brief: brief.value.trim(),
            feedback: options.forceRefine ? feedback.value.trim() : undefined,
            draft_instructions: options.forceRefine ? draftInstructionsPayload() : undefined,
        });

        draft.background = listToLines(response.instructions?.background);
        draft.steps = listToLines(response.instructions?.steps);
        draft.output = listToLines(response.instructions?.output);
        generatorAgentName.value = response.generator_agent?.name ?? generatorAgentName.value;
        hasGenerated.value = true;
        feedback.value = '';

        toast.success(options.forceRefine ? 'Instructions refined' : 'Instructions generated');
    } catch (generateError) {
        error.value = generateError.data?.message ?? generateError.message;
    } finally {
        generating.value = false;
    }
}

async function saveInstructions() {
    if (!canSave.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const updated = await updateAgent(props.agent.id, {
            instructions: {
                background: linesToList(draft.background),
                steps: linesToList(draft.steps),
                output: linesToList(draft.output),
            },
        });

        emit('saved', updated);
        open.value = false;
        toast.success('Operating instructions saved');
    } catch (saveError) {
        const validationMessage = saveError.data?.errors?.['instructions.background']?.[0];

        error.value = validationMessage ?? saveError.message;
    } finally {
        saving.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        resetState();
        loadSettings();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Generate operating instructions</DialogTitle>
                <DialogDescription>
                    Use the prompt generator agent to draft background, process, and output rules for
                    <span class="font-medium text-foreground">{{ agent.name }}</span>.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5">
                <div
                    v-if="!loadingSettings && !generatorConfigured"
                    class="rounded-app-container border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                >
                    Configure a prompt generator agent in Settings before using this workflow.
                </div>

                <FieldGroup>
                    <Field>
                        <FieldLabel for="prompt-brief">Brief</FieldLabel>
                        <Textarea
                            id="prompt-brief"
                            v-model="brief"
                            rows="4"
                            class="min-h-[6rem] resize-y"
                            placeholder="Describe what this agent should do, who it serves, and any constraints."
                            :disabled="generating || saving || loadingSettings"
                        />
                    </Field>

                    <template v-if="hasGenerated">
                        <Field>
                            <FieldLabel for="prompt-background">Background</FieldLabel>
                            <Textarea
                                id="prompt-background"
                                v-model="draft.background"
                                rows="4"
                                class="min-h-[6rem] resize-y"
                                placeholder="One instruction per line"
                                :disabled="generating || saving"
                            />
                        </Field>

                        <Field>
                            <FieldLabel for="prompt-steps">Process</FieldLabel>
                            <Textarea
                                id="prompt-steps"
                                v-model="draft.steps"
                                rows="4"
                                class="min-h-[6rem] resize-y"
                                placeholder="One step per line"
                                :disabled="generating || saving"
                            />
                        </Field>

                        <Field>
                            <FieldLabel for="prompt-output">Output contract</FieldLabel>
                            <Textarea
                                id="prompt-output"
                                v-model="draft.output"
                                rows="3"
                                class="min-h-[5rem] resize-y"
                                placeholder="One rule per line"
                                :disabled="generating || saving"
                            />
                        </Field>

                        <Field>
                            <FieldLabel for="prompt-feedback">Refinement feedback</FieldLabel>
                            <Textarea
                                id="prompt-feedback"
                                v-model="feedback"
                                rows="3"
                                class="min-h-[5rem] resize-y"
                                placeholder="Optional notes for the generator, e.g. make tone more formal or add escalation rules."
                                :disabled="generating || saving"
                            />
                        </Field>
                    </template>
                </FieldGroup>

                <p
                    v-if="generatorConfigured && generatorAgentName"
                    class="app-muted-text text-sm"
                >
                    Generator: {{ generatorAgentName }}
                </p>

                <p v-if="error" class="text-sm text-destructive">
                    {{ error }}
                </p>
            </div>

            <DialogFooter class="gap-2 sm:justify-between">
                <Button
                    variant="outline"
                    class="app-soft-control"
                    :disabled="generating || saving"
                    @click="open = false"
                >
                    Cancel
                </Button>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!hasGenerated"
                        :disabled="!canGenerate || loadingSettings"
                        @click="generate()"
                    >
                        <LoaderCircleIcon
                            v-if="generating"
                            class="size-4 animate-spin"
                        />
                        <SparklesIcon v-else class="size-4" />
                        Generate
                    </Button>

                    <template v-else>
                        <Button
                            variant="outline"
                            class="app-soft-control"
                            :disabled="!canGenerate || loadingSettings"
                            @click="generate()"
                        >
                            <LoaderCircleIcon
                                v-if="generating"
                                class="size-4 animate-spin"
                            />
                            <SparklesIcon v-else class="size-4" />
                            Regenerate
                        </Button>

                        <Button
                            variant="outline"
                            class="app-soft-control"
                            :disabled="!canRefine || loadingSettings"
                            @click="generate({ forceRefine: true })"
                        >
                            <LoaderCircleIcon
                                v-if="generating"
                                class="size-4 animate-spin"
                            />
                            Apply feedback
                        </Button>

                        <Button
                            :disabled="!canSave"
                            @click="saveInstructions"
                        >
                            <LoaderCircleIcon
                                v-if="saving"
                                class="size-4 animate-spin"
                            />
                            Save instructions
                        </Button>
                    </template>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
