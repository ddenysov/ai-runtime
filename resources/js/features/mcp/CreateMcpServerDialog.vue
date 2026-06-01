<script setup>
import { reactive, ref, watch } from 'vue';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { createMcpServer } from '@/lib/api';

const open = defineModel('open', {
    type: Boolean,
    default: false,
});

const emit = defineEmits(['created']);

const submitting = ref(false);
const serverErrors = ref({});

const form = reactive({
    name: '',
    command: '',
    args: '',
    cwd: '',
    env: '',
    enabled: true,
});

function resetForm() {
    form.name = '';
    form.command = '';
    form.args = '';
    form.cwd = '';
    form.env = '';
    form.enabled = true;
    serverErrors.value = {};
}

function fieldError(name) {
    const errors = serverErrors.value?.[name];

    return errors?.length ? errors : undefined;
}

function parseEnv() {
    if (!form.env.trim()) {
        return {};
    }

    try {
        const parsed = JSON.parse(form.env);
        if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
            serverErrors.value = {
                ...serverErrors.value,
                env: ['Enter a JSON object.'],
            };

            return undefined;
        }

        return parsed;
    } catch {
        serverErrors.value = {
            ...serverErrors.value,
            env: ['Enter valid JSON.'],
        };

        return undefined;
    }
}

function parseArgs() {
    return form.args
        .split('\n')
        .map((arg) => arg.trim())
        .filter(Boolean);
}

async function submit() {
    submitting.value = true;
    serverErrors.value = {};

    try {
        const env = parseEnv();
        if (env === undefined) {
            return;
        }

        const response = await createMcpServer({
            name: form.name,
            transport: 'stdio',
            command: form.command,
            args: parseArgs(),
            cwd: form.cwd || null,
            env,
            enabled: form.enabled,
        });

        toast.success('MCP server created', {
            description: `${response.data.name} is ready to test.`,
        });

        emit('created', response.data);
        open.value = false;
    } catch (error) {
        if (error.status === 422 && error.data?.errors) {
            serverErrors.value = error.data.errors;
            return;
        }

        toast.error('Could not create MCP server', {
            description: error.message,
        });
    } finally {
        submitting.value = false;
    }
}

watch(open, (isOpen) => {
    if (!isOpen) {
        resetForm();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-2xl">
            <form class="flex flex-col" autocomplete="off" @submit.prevent="submit">
                <DialogHeader class="border-b px-5 py-4 text-left">
                    <DialogTitle>New MCP server</DialogTitle>
                    <DialogDescription>
                        Register a stdio MCP server that runtime agents can use as tools.
                    </DialogDescription>
                </DialogHeader>

                <div class="px-5 py-5">
                    <FieldGroup class="grid gap-5">
                        <Field>
                            <FieldLabel for="mcp-name">Name</FieldLabel>
                            <Input
                                id="mcp-name"
                                v-model="form.name"
                                placeholder="Google Workspace MCP"
                            />
                            <FieldError :errors="fieldError('name')" />
                        </Field>

                        <Field>
                            <FieldLabel for="mcp-command">Command</FieldLabel>
                            <Input
                                id="mcp-command"
                                v-model="form.command"
                                placeholder="npx"
                            />
                            <FieldError :errors="fieldError('command')" />
                        </Field>

                        <Field>
                            <FieldLabel for="mcp-args">Arguments</FieldLabel>
                            <Textarea
                                id="mcp-args"
                                v-model="form.args"
                                rows="3"
                                placeholder="-y&#10;@modelcontextprotocol/server-filesystem&#10;/path/to/root"
                            />
                            <FieldError :errors="fieldError('args')" />
                        </Field>

                        <Field>
                            <FieldLabel for="mcp-cwd">Working directory</FieldLabel>
                            <Input
                                id="mcp-cwd"
                                v-model="form.cwd"
                                placeholder="/absolute/path"
                            />
                            <FieldError :errors="fieldError('cwd')" />
                        </Field>

                        <Field>
                            <FieldLabel for="mcp-env">Environment JSON</FieldLabel>
                            <Textarea
                                id="mcp-env"
                                v-model="form.env"
                                rows="4"
                                placeholder="{ &quot;API_TOKEN&quot;: &quot;...&quot; }"
                            />
                            <FieldError :errors="fieldError('env')" />
                        </Field>

                        <Field orientation="horizontal">
                            <FieldLabel for="mcp-enabled">Enabled</FieldLabel>
                            <Switch id="mcp-enabled" v-model:checked="form.enabled" />
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
                        :disabled="submitting || !form.name || !form.command"
                    >
                        <LoaderCircleIcon
                            v-if="submitting"
                            class="size-4 animate-spin"
                        />
                        Create server
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
