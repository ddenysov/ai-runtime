<script setup>
import { computed, onMounted, ref } from 'vue';
import { LoaderCircleIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    deleteGatekeeperTelegramWebhook,
    getSettings,
    listAgents,
    setGatekeeperTelegramWebhook,
    testGatekeeperBot,
    updateSettings,
} from '@/lib/api';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const NONE_AGENT = 'none';

const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const saving = ref(false);
const agentsLoading = ref(false);
const agents = ref([]);
const promptGeneratorAgentId = ref(NONE_AGENT);
const savedPromptGeneratorAgentId = ref(NONE_AGENT);
const gatekeeperEnabled = ref(false);
const savedGatekeeperEnabled = ref(false);
const gatekeeperBotToken = ref('');
const gatekeeperTelegramChatId = ref('');
const savedGatekeeperTelegramChatId = ref('');
const gatekeeperBotTokenConfigured = ref(false);
const gatekeeperWebhookUrl = ref('');
const gatekeeperWebhookHttpsReady = ref(false);
const savingGatekeeper = ref(false);
const testingGatekeeper = ref(false);
const registeringGatekeeperWebhook = ref(false);
const gatekeeperTestResult = ref(null);

const settingsNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Settings',
})));

const promptGeneratorDirty = computed(() => (
    promptGeneratorAgentId.value !== savedPromptGeneratorAgentId.value
));

const gatekeeperDirty = computed(() => (
    gatekeeperEnabled.value !== savedGatekeeperEnabled.value
    || gatekeeperTelegramChatId.value !== savedGatekeeperTelegramChatId.value
    || gatekeeperBotToken.value !== ''
));

const canTestGatekeeper = computed(() => {
    const hasToken = gatekeeperBotTokenConfigured.value || gatekeeperBotToken.value.trim() !== '';
    const hasChatId = gatekeeperTelegramChatId.value.trim() !== '';

    return hasToken && hasChatId;
});

const canRegisterGatekeeperWebhook = computed(() => (
    gatekeeperWebhookHttpsReady.value
    && (gatekeeperBotTokenConfigured.value || gatekeeperBotToken.value.trim() !== '')
));

function gatekeeperWebhookRegisterHint() {
    if (!gatekeeperWebhookHttpsReady.value) {
        return 'Set PUBLIC_APP_URL to a public HTTPS URL (e.g. ngrok) before registering.';
    }

    if (!gatekeeperBotTokenConfigured.value && gatekeeperBotToken.value.trim() === '') {
        return 'Save a bot token first.';
    }

    return '';
}

function gatekeeperWebhookPayload() {
    const payload = {};

    if (gatekeeperBotToken.value.trim() !== '') {
        payload.bot_token = gatekeeperBotToken.value.trim();
    }

    return payload;
}

function agentSelectValue(agentId) {
    return agentId ? String(agentId) : NONE_AGENT;
}

function agentIdFromSelect(value) {
    return value === NONE_AGENT ? null : Number(value);
}

async function loadAgents() {
    agentsLoading.value = true;

    try {
        const response = await listAgents({
            perPage: 100,
            sort: 'name',
            includeProviderModel: false,
            includeToolsCount: false,
            includeVersionsCount: false,
        });

        agents.value = response.data ?? [];
    } catch (fetchError) {
        toast.error('Could not load agents', {
            description: fetchError.message,
        });
    } finally {
        agentsLoading.value = false;
    }
}

async function loadSettings() {
    loading.value = true;

    try {
        const response = await getSettings();
        const agentId = response.data?.prompts?.prompt_generator_agent_id ?? null;
        const selectValue = agentSelectValue(agentId);

        promptGeneratorAgentId.value = selectValue;
        savedPromptGeneratorAgentId.value = selectValue;

        const gatekeeper = response.data?.gatekeeper ?? {};

        gatekeeperEnabled.value = Boolean(gatekeeper.enabled);
        savedGatekeeperEnabled.value = gatekeeperEnabled.value;
        gatekeeperTelegramChatId.value = gatekeeper.telegram_chat_id ?? '';
        savedGatekeeperTelegramChatId.value = gatekeeperTelegramChatId.value;
        gatekeeperBotTokenConfigured.value = Boolean(gatekeeper.bot_token_configured);
        gatekeeperWebhookUrl.value = gatekeeper.webhook_url ?? '';
        gatekeeperWebhookHttpsReady.value = Boolean(gatekeeper.webhook_https_ready);
        gatekeeperBotToken.value = '';
    } catch (fetchError) {
        toast.error('Could not load settings', {
            description: fetchError.message,
        });
    } finally {
        loading.value = false;
    }
}

async function savePromptGenerator() {
    saving.value = true;

    try {
        const response = await updateSettings({
            prompts: {
                prompt_generator_agent_id: agentIdFromSelect(promptGeneratorAgentId.value),
            },
        });

        const agentId = response.data?.prompts?.prompt_generator_agent_id ?? null;
        const selectValue = agentSelectValue(agentId);

        promptGeneratorAgentId.value = selectValue;
        savedPromptGeneratorAgentId.value = selectValue;

        toast.success('Settings saved');
    } catch (saveError) {
        toast.error('Could not save settings', {
            description: saveError.data?.message ?? saveError.message,
        });
    } finally {
        saving.value = false;
    }
}

async function testGatekeeper() {
    testingGatekeeper.value = true;
    gatekeeperTestResult.value = null;

    try {
        const payload = {
            telegram_chat_id: gatekeeperTelegramChatId.value.trim(),
        };

        if (gatekeeperBotToken.value.trim() !== '') {
            payload.bot_token = gatekeeperBotToken.value.trim();
        }

        const response = await testGatekeeperBot(payload);

        gatekeeperTestResult.value = response.data ?? null;

        if (response.data?.ok) {
            toast.success('Test message sent', {
                description: response.data.message,
            });
        } else {
            toast.error('Bot test failed', {
                description: response.data?.message ?? 'Telegram API returned an error.',
            });
        }
    } catch (testError) {
        gatekeeperTestResult.value = testError.data?.data ?? {
            ok: false,
            message: testError.data?.message ?? testError.message,
        };

        toast.error('Bot test failed', {
            description: gatekeeperTestResult.value.message,
        });
    } finally {
        testingGatekeeper.value = false;
    }
}

async function registerGatekeeperWebhook() {
    if (!canRegisterGatekeeperWebhook.value) {
        toast.error(gatekeeperWebhookRegisterHint() || 'Cannot register webhook.');

        return;
    }

    registeringGatekeeperWebhook.value = true;

    try {
        const response = await setGatekeeperTelegramWebhook(gatekeeperWebhookPayload());
        toast.success('Telegram webhook registered', {
            description: response.data?.webhook_url ?? gatekeeperWebhookUrl.value,
        });
    } catch (error) {
        toast.error('Webhook registration failed', {
            description: error.data?.message ?? error.message,
        });
    } finally {
        registeringGatekeeperWebhook.value = false;
    }
}

async function removeGatekeeperWebhook() {
    if (!gatekeeperBotTokenConfigured.value && gatekeeperBotToken.value.trim() === '') {
        toast.error('Save a bot token first.');

        return;
    }

    registeringGatekeeperWebhook.value = true;

    try {
        await deleteGatekeeperTelegramWebhook(gatekeeperWebhookPayload());
        toast.success('Telegram webhook removed');
    } catch (error) {
        toast.error('Could not remove webhook', {
            description: error.data?.message ?? error.message,
        });
    } finally {
        registeringGatekeeperWebhook.value = false;
    }
}

async function saveGatekeeper() {
    savingGatekeeper.value = true;

    try {
        const payload = {
            gatekeeper: {
                enabled: gatekeeperEnabled.value,
                telegram_chat_id: gatekeeperTelegramChatId.value.trim() || null,
            },
        };

        if (gatekeeperBotToken.value.trim() !== '') {
            payload.gatekeeper.bot_token = gatekeeperBotToken.value.trim();
        }

        const response = await updateSettings(payload);
        const gatekeeper = response.data?.gatekeeper ?? {};

        gatekeeperEnabled.value = Boolean(gatekeeper.enabled);
        savedGatekeeperEnabled.value = gatekeeperEnabled.value;
        gatekeeperTelegramChatId.value = gatekeeper.telegram_chat_id ?? '';
        savedGatekeeperTelegramChatId.value = gatekeeperTelegramChatId.value;
        gatekeeperBotTokenConfigured.value = Boolean(gatekeeper.bot_token_configured);
        gatekeeperWebhookUrl.value = gatekeeper.webhook_url ?? '';
        gatekeeperWebhookHttpsReady.value = Boolean(gatekeeper.webhook_https_ready);
        gatekeeperBotToken.value = '';

        toast.success('Gatekeeper settings saved');
    } catch (saveError) {
        toast.error('Could not save gatekeeper settings', {
            description: saveError.data?.message ?? saveError.message,
        });
    } finally {
        savingGatekeeper.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadAgents(), loadSettings()]);
});
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="settingsNavigation"
    >
        <PageHeader title="Settings">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Platform', 'Settings']" />
            </template>
        </PageHeader>

        <div class="space-y-6 px-5 py-7 md:px-8 md:py-8">
            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        AI &amp; prompts
                    </h2>
                    <p class="app-muted-text mt-1 text-sm">
                        Configure agents used across the platform for prompt-related workflows.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Prompt generator</CardTitle>
                        <CardDescription>
                            Optional agent that generates or refines prompts for other agents and tools.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="max-w-xl space-y-2">
                            <Label for="prompt-generator-agent">Generator agent</Label>
                            <Select
                                id="prompt-generator-agent"
                                v-model="promptGeneratorAgentId"
                                :disabled="loading || agentsLoading || saving"
                            >
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        :placeholder="agentsLoading ? 'Loading agents...' : 'No agent selected'"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE_AGENT">
                                        No agent selected
                                    </SelectItem>
                                    <SelectItem
                                        v-for="agent in agents"
                                        :key="agent.id"
                                        :value="String(agent.id)"
                                    >
                                        {{ agent.name }} · {{ agent.slug }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                size="sm"
                                :disabled="!promptGeneratorDirty || saving || loading"
                                @click="savePromptGenerator"
                            >
                                <LoaderCircleIcon
                                    v-if="saving"
                                    class="size-4 animate-spin"
                                />
                                Save
                            </Button>
                            <p
                                v-if="promptGeneratorDirty"
                                class="app-muted-text text-sm"
                            >
                                Unsaved changes
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Access gatekeeper
                    </h2>
                    <p class="app-muted-text mt-1 text-sm">
                        Hide the app behind an nginx-like 404 until you open login from Telegram.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Telegram gatekeeper</CardTitle>
                        <CardDescription>
                            Requires <code class="text-xs">GATE_ENABLED=true</code> in the server environment.
                            Logged-in sessions keep working after the 2-minute window closes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <div class="flex max-w-xl items-center justify-between gap-4">
                            <div class="space-y-1">
                                <Label for="gatekeeper-enabled">Enabled</Label>
                                <p class="app-muted-text text-sm">
                                    Block visitors until you approve access from Telegram.
                                </p>
                            </div>
                            <Switch
                                id="gatekeeper-enabled"
                                v-model="gatekeeperEnabled"
                                :disabled="loading || savingGatekeeper"
                            />
                        </div>

                        <div class="max-w-xl space-y-2">
                            <Label for="gatekeeper-bot-token">Bot token</Label>
                            <Input
                                id="gatekeeper-bot-token"
                                v-model="gatekeeperBotToken"
                                autocomplete="off"
                                :disabled="loading || savingGatekeeper"
                                :placeholder="gatekeeperBotTokenConfigured ? 'Token saved — leave blank to keep' : '123456:ABC...'"
                                type="password"
                            />
                        </div>

                        <div class="max-w-xl space-y-2">
                            <Label for="gatekeeper-chat-id">Your Telegram chat ID</Label>
                            <Input
                                id="gatekeeper-chat-id"
                                v-model="gatekeeperTelegramChatId"
                                autocomplete="off"
                                :disabled="loading || savingGatekeeper"
                                placeholder="123456789"
                            />
                        </div>

                        <div
                            v-if="gatekeeperWebhookUrl"
                            class="max-w-xl space-y-3"
                        >
                            <Label>Webhook URL</Label>
                            <p class="rounded-app-control border border-border bg-muted/30 px-3 py-2 font-mono text-xs break-all">
                                {{ gatekeeperWebhookUrl }}
                            </p>
                            <p
                                v-if="gatekeeperWebhookRegisterHint()"
                                class="app-muted-text text-sm"
                            >
                                {{ gatekeeperWebhookRegisterHint() }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="!canRegisterGatekeeperWebhook || registeringGatekeeperWebhook || savingGatekeeper || loading"
                                    @click="registerGatekeeperWebhook"
                                >
                                    <LoaderCircleIcon
                                        v-if="registeringGatekeeperWebhook"
                                        class="size-4 animate-spin"
                                    />
                                    Register webhook
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :disabled="registeringGatekeeperWebhook || savingGatekeeper || loading || (!gatekeeperBotTokenConfigured && gatekeeperBotToken.trim() === '')"
                                    @click="removeGatekeeperWebhook"
                                >
                                    Remove webhook
                                </Button>
                            </div>
                            <p class="app-muted-text text-sm">
                                Calls Telegram setWebhook using PUBLIC_APP_URL. Save settings first if you changed the bot token.
                            </p>
                        </div>

                        <div class="max-w-xl space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    :disabled="!gatekeeperDirty || savingGatekeeper || loading"
                                    @click="saveGatekeeper"
                                >
                                    <LoaderCircleIcon
                                        v-if="savingGatekeeper"
                                        class="size-4 animate-spin"
                                    />
                                    Save
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="!canTestGatekeeper || testingGatekeeper || savingGatekeeper || loading"
                                    @click="testGatekeeper"
                                >
                                    <LoaderCircleIcon
                                        v-if="testingGatekeeper"
                                        class="size-4 animate-spin"
                                    />
                                    Test bot
                                </Button>
                                <p
                                    v-if="gatekeeperDirty"
                                    class="app-muted-text text-sm"
                                >
                                    Unsaved changes
                                </p>
                            </div>
                            <p class="app-muted-text text-sm">
                                Test bot sends a short message to your Telegram chat and shows the API response below.
                            </p>
                            <div
                                v-if="gatekeeperTestResult"
                                class="space-y-2"
                            >
                                <Label>Test response</Label>
                                <p
                                    class="text-sm"
                                    :class="gatekeeperTestResult.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'"
                                >
                                    {{ gatekeeperTestResult.message }}
                                </p>
                                <pre
                                    v-if="gatekeeperTestResult.response"
                                    class="max-h-48 overflow-auto rounded-app-control border border-border bg-muted/30 p-3 font-mono text-xs break-all whitespace-pre-wrap"
                                >{{ JSON.stringify(gatekeeperTestResult.response, null, 2) }}</pre>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </div>
    </AppShell>
</template>
