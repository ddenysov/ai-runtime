<script setup>
import { onMounted, ref, watch } from 'vue';
import { LoaderCircleIcon, PlusIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
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
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    createAgentChannel,
    deleteAgentChannel,
    deleteAgentChannelTelegramWebhook,
    getAgentChannel,
    listAgentChannels,
    setAgentChannelTelegramWebhook,
    updateAgentChannel,
} from '@/lib/api';

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
});

const channelTypes = ['telegram', 'slack', 'webhook'];
const channelTypeLabels = {
    telegram: 'Telegram',
    slack: 'Slack',
    webhook: 'Webhook',
};

const channels = ref([]);
const listError = ref('');
const loading = ref(false);
const dialogOpen = ref(false);
const dialogMode = ref('add');
const formError = ref('');
const saving = ref(false);
const editLoading = ref(false);
const editingUuid = ref(null);
const editingVersion = ref(0);
const editingTelegramMeta = ref({
    telegram_webhook_https_ready: false,
    telegram_has_bot_token: false,
});
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const webhookLoadingUuid = ref(null);

const form = ref({
    name: '',
    description: '',
    type: 'telegram',
    enabled: true,
    metadataText: '',
    telegram_bot_token: '',
    telegram_webhook_secret: '',
    slack_bot_token: '',
    slack_signing_secret: '',
    webhook_url: '',
    webhook_secret: '',
});

function resetForm() {
    form.value = {
        name: '',
        description: '',
        type: 'telegram',
        enabled: true,
        metadataText: '',
        telegram_bot_token: '',
        telegram_webhook_secret: '',
        slack_bot_token: '',
        slack_signing_secret: '',
        webhook_url: '',
        webhook_secret: '',
    };
}

function firstError(data) {
    if (!data?.errors) {
        return data?.message ?? null;
    }

    const first = Object.values(data.errors).flat()[0];

    return typeof first === 'string' ? first : data?.message ?? null;
}

function valueToString(value) {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch {
            return '';
        }
    }

    return String(value);
}

function parseMetadata() {
    const raw = form.value.metadataText.trim();

    if (!raw) {
        return { ok: true, value: null };
    }

    try {
        return { ok: true, value: JSON.parse(raw) };
    } catch {
        return { ok: false, error: 'Metadata must be valid JSON.' };
    }
}

function buildSettings() {
    const type = form.value.type;

    if (type === 'telegram') {
        const out = {};
        const token = form.value.telegram_bot_token.trim();
        const secret = form.value.telegram_webhook_secret.trim();

        if (token) {
            out.bot_token = token;
        }

        if (secret) {
            out.webhook_secret = secret;
        }

        return out;
    }

    if (type === 'slack') {
        const out = {};
        const bot = form.value.slack_bot_token.trim();
        const signing = form.value.slack_signing_secret.trim();

        if (bot) {
            out.bot_token = bot;
        }

        if (signing) {
            out.signing_secret = signing;
        }

        return out;
    }

    if (type === 'webhook') {
        const out = {};
        const url = form.value.webhook_url.trim();
        const secret = form.value.webhook_secret.trim();

        if (url) {
            out.url = url;
        }

        if (secret) {
            out.secret = secret;
        }

        return out;
    }

    return {};
}

function applySettingsToForm(settings, type) {
    const s = settings && typeof settings === 'object' ? settings : {};
    const typ = channelTypes.includes(type) ? type : 'telegram';

    form.value.telegram_bot_token = '';
    form.value.telegram_webhook_secret = '';
    form.value.slack_bot_token = '';
    form.value.slack_signing_secret = '';
    form.value.webhook_url = '';
    form.value.webhook_secret = '';

    if (typ === 'telegram') {
        form.value.telegram_bot_token = valueToString(s.bot_token);
        form.value.telegram_webhook_secret = valueToString(s.webhook_secret);
    } else if (typ === 'slack') {
        form.value.slack_bot_token = valueToString(s.bot_token);
        form.value.slack_signing_secret = valueToString(s.signing_secret);
    } else if (typ === 'webhook') {
        form.value.webhook_url = valueToString(s.url);
        form.value.webhook_secret = valueToString(s.secret);
    }
}

async function loadChannels() {
    listError.value = '';
    loading.value = true;

    try {
        const data = await listAgentChannels({ agentId: props.agentId });
        channels.value = Array.isArray(data?.data) ? data.data : [];
    } catch (error) {
        channels.value = [];
        listError.value = error.message ?? 'Could not load delivery channels.';
    } finally {
        loading.value = false;
    }
}

function openAddDialog() {
    dialogMode.value = 'add';
    formError.value = '';
    editLoading.value = false;
    editingUuid.value = null;
    editingVersion.value = 0;
    editingTelegramMeta.value = {
        telegram_webhook_https_ready: false,
        telegram_has_bot_token: false,
    };
    resetForm();
    dialogOpen.value = true;
}

async function openEditDialog(summary) {
    dialogMode.value = 'edit';
    formError.value = '';
    editLoading.value = true;
    editingUuid.value = summary?.uuid ?? null;
    dialogOpen.value = true;
    resetForm();

    if (!summary?.uuid) {
        editLoading.value = false;
        formError.value = 'Missing channel identifier.';

        return;
    }

    try {
        const data = await getAgentChannel(summary.uuid);
        const channel = data?.data;

        if (!channel) {
            throw new Error('Channel payload was empty.');
        }

        const typ = channelTypes.includes(channel.type) ? channel.type : 'telegram';

        form.value = {
            name: channel.name ?? '',
            description: channel.description ?? '',
            type: typ,
            enabled: !!channel.enabled,
            metadataText: channel.metadata && Object.keys(channel.metadata).length
                ? JSON.stringify(channel.metadata, null, 2)
                : '',
            telegram_bot_token: '',
            telegram_webhook_secret: '',
            slack_bot_token: '',
            slack_signing_secret: '',
            webhook_url: '',
            webhook_secret: '',
        };
        applySettingsToForm(channel.settings ?? {}, typ);
        editingVersion.value = Number(channel.version ?? 0);
        editingTelegramMeta.value = {
            telegram_webhook_https_ready: !!channel.telegram_webhook_https_ready,
            telegram_has_bot_token: !!channel.telegram_has_bot_token,
        };
    } catch (error) {
        formError.value = error.message ?? 'Could not load channel.';
    } finally {
        editLoading.value = false;
    }
}

async function submitForm() {
    formError.value = '';

    const name = form.value.name.trim();

    if (!name) {
        formError.value = 'Name is required.';

        return;
    }

    const meta = parseMetadata();

    if (!meta.ok) {
        formError.value = meta.error;

        return;
    }

    saving.value = true;

    try {
        const body = {
            name,
            description: form.value.description.trim() || null,
            type: form.value.type,
            settings: buildSettings(),
            enabled: !!form.value.enabled,
        };

        if (meta.value !== null) {
            body.metadata = meta.value;
        }

        if (dialogMode.value === 'add') {
            await createAgentChannel({
                agent_id: Number(props.agentId),
                ...body,
            });
            toast.success('Delivery channel created');
        } else {
            await updateAgentChannel(editingUuid.value, {
                ...body,
                expected_version: editingVersion.value,
            });
            toast.success('Delivery channel updated');
        }

        dialogOpen.value = false;
        await loadChannels();
    } catch (error) {
        formError.value = firstError(error.data) ?? error.message ?? 'Could not save channel.';
    } finally {
        saving.value = false;
    }
}

function openDeleteDialog(channel) {
    deleteTarget.value = channel;
    deleteDialogOpen.value = true;
}

async function confirmDelete() {
    const channel = deleteTarget.value;

    if (!channel?.uuid) {
        deleteDialogOpen.value = false;

        return;
    }

    try {
        await deleteAgentChannel(channel.uuid, channel.version ?? 0);
        toast.success('Delivery channel deleted');
        await loadChannels();
    } catch (error) {
        listError.value = firstError(error.data) ?? error.message ?? 'Could not delete channel.';
    } finally {
        deleteDialogOpen.value = false;
        deleteTarget.value = null;
    }
}

function channelHasBotToken(channel) {
    if (channel?.telegram_has_bot_token) {
        return true;
    }

    return form.value.telegram_bot_token.trim() !== '';
}

function canRegisterTelegramWebhook(channel) {
    return channel?.type === 'telegram'
        && channel.telegram_webhook_https_ready
        && channelHasBotToken(channel);
}

function editDialogTelegramChannel() {
    return {
        uuid: editingUuid.value,
        type: 'telegram',
        telegram_webhook_https_ready: editingTelegramMeta.value.telegram_webhook_https_ready,
        telegram_has_bot_token: channelHasBotToken(editingTelegramMeta.value),
    };
}

function telegramWebhookRegisterHint(channel) {
    if (channel?.type !== 'telegram') {
        return '';
    }

    if (!channel.telegram_webhook_https_ready) {
        return 'Set PUBLIC_APP_URL to a public HTTPS URL (e.g. ngrok) before registering.';
    }

    if (!channel.telegram_has_bot_token) {
        return 'Save a bot token for this channel first.';
    }

    return '';
}

async function registerTelegramWebhook(channel) {
    const uuid = channel?.uuid ?? editingUuid.value;

    if (!uuid) {
        toast.error('Save the channel before registering a webhook.');

        return;
    }

    if (!canRegisterTelegramWebhook(channel)) {
        toast.error(telegramWebhookRegisterHint(channel) || 'Cannot register webhook for this channel.');

        return;
    }

    webhookLoadingUuid.value = uuid;

    try {
        const data = await setAgentChannelTelegramWebhook(uuid);
        toast.success('Telegram webhook registered', {
            description: data?.data?.webhook_url ?? channel?.telegram_webhook_url,
        });
        await loadChannels();
    } catch (error) {
        toast.error(firstError(error.data) ?? error.message ?? 'Webhook registration failed');
    } finally {
        webhookLoadingUuid.value = null;
    }
}

async function removeTelegramWebhook(channel) {
    webhookLoadingUuid.value = channel.uuid;

    try {
        await deleteAgentChannelTelegramWebhook(channel.uuid);
        toast.success('Telegram webhook removed');
    } catch (error) {
        toast.error(firstError(error.data) ?? error.message ?? 'Could not remove webhook');
    } finally {
        webhookLoadingUuid.value = null;
    }
}

onMounted(loadChannels);

watch(() => props.agentId, loadChannels);
</script>

<template>
    <Card class="app-surface">
        <CardHeader class="flex flex-row items-start justify-between gap-3 space-y-0">
            <div>
                <CardTitle>Delivery channels</CardTitle>
                <CardDescription>
                    Connect Telegram (and more surfaces later). Secrets are stored encrypted.
                </CardDescription>
            </div>
            <Button size="sm" @click="openAddDialog">
                <PlusIcon class="mr-2 size-4" />
                Add channel
            </Button>
        </CardHeader>
        <CardContent class="space-y-4">
            <div
                v-if="listError"
                class="rounded-app-container border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100"
            >
                {{ listError }}
                <Button class="mt-3" size="sm" variant="outline" @click="loadChannels">
                    Retry
                </Button>
            </div>
            <Skeleton v-else-if="loading" class="h-24 rounded-app-container" />
            <template v-else-if="channels.length">
                <div
                    v-for="channel in channels"
                    :key="channel.uuid"
                    class="rounded-app-container border p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ channel.name }}</p>
                            <p v-if="channel.description" class="app-muted-text mt-1 line-clamp-2 text-sm">
                                {{ channel.description }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <Badge variant="outline" class="rounded-full font-mono text-[11px]">
                                {{ channelTypeLabels[channel.type] ?? channel.type }}
                            </Badge>
                            <Badge :variant="channel.enabled ? 'secondary' : 'outline'">
                                {{ channel.enabled ? 'Enabled' : 'Disabled' }}
                            </Badge>
                        </div>
                    </div>
                    <p
                        v-if="channel.type === 'telegram' && channel.telegram_webhook_url"
                        class="app-muted-text mt-2 break-all font-mono text-[11px]"
                    >
                        Webhook URL: {{ channel.telegram_webhook_url }}
                    </p>
                    <p
                        v-else-if="channel.type === 'telegram' && telegramWebhookRegisterHint(channel)"
                        class="mt-2 text-sm text-amber-900 dark:text-amber-100"
                    >
                        {{ telegramWebhookRegisterHint(channel) }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" @click="openEditDialog(channel)">
                            Edit
                        </Button>
                        <Button
                            v-if="channel.type === 'telegram'"
                            size="sm"
                            variant="outline"
                            :disabled="webhookLoadingUuid === channel.uuid || !canRegisterTelegramWebhook(channel)"
                            :title="telegramWebhookRegisterHint(channel) || undefined"
                            @click="registerTelegramWebhook(channel)"
                        >
                            <LoaderCircleIcon
                                v-if="webhookLoadingUuid === channel.uuid"
                                class="mr-2 size-4 animate-spin"
                            />
                            Register webhook
                        </Button>
                        <Button
                            v-if="channel.type === 'telegram'"
                            size="sm"
                            variant="outline"
                            :disabled="webhookLoadingUuid === channel.uuid"
                            @click="removeTelegramWebhook(channel)"
                        >
                            Remove webhook
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="text-destructive"
                            @click="openDeleteDialog(channel)"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </template>
            <p v-else class="app-muted-text text-sm">
                No channels yet. Add a Telegram bot to chat with this agent from Telegram.
            </p>
        </CardContent>
    </Card>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ dialogMode === 'add' ? 'New delivery channel' : 'Edit delivery channel' }}</DialogTitle>
                <DialogDescription>
                    Type-specific settings are stored encrypted. Edits require the current record version.
                </DialogDescription>
            </DialogHeader>

            <div v-if="editLoading" class="py-8 text-center text-sm text-muted-foreground">
                Loading channel…
            </div>

            <div v-else class="space-y-4">
                <div class="space-y-2">
                    <Label for="channel-name">Name</Label>
                    <Input
                        id="channel-name"
                        v-model="form.name"
                        autocomplete="new-password"
                        placeholder="e.g. Support Telegram"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="channel-description">Description (optional)</Label>
                    <Textarea
                        id="channel-description"
                        v-model="form.description"
                        autocomplete="off"
                        rows="2"
                        class="resize-y text-sm"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="channel-type">Type</Label>
                    <select
                        id="channel-type"
                        v-model="form.type"
                        class="flex h-10 w-full rounded-app-control border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option v-for="type in channelTypes" :key="type" :value="type">
                            {{ channelTypeLabels[type] ?? type }}
                        </option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <Switch id="channel-enabled" v-model:checked="form.enabled" />
                    <Label for="channel-enabled">Enabled</Label>
                </div>

                <div
                    v-if="form.type === 'telegram'"
                    class="space-y-3 rounded-app-container border p-4"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Telegram
                    </p>
                    <div class="space-y-2">
                        <Label for="channel-tg-token">Bot token</Label>
                        <Input
                            id="channel-tg-token"
                            v-model="form.telegram_bot_token"
                            type="password"
                            autocomplete="new-password"
                            class="font-mono text-xs"
                            placeholder="From BotFather"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="channel-tg-secret">Webhook secret (optional)</Label>
                        <Input
                            id="channel-tg-secret"
                            v-model="form.telegram_webhook_secret"
                            type="password"
                            autocomplete="new-password"
                            class="font-mono text-xs"
                        />
                    </div>
                    <Button
                        v-if="dialogMode === 'edit' && editingUuid"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="w-full"
                        :disabled="webhookLoadingUuid === editingUuid || saving || editLoading || !canRegisterTelegramWebhook(editDialogTelegramChannel())"
                        :title="telegramWebhookRegisterHint(editDialogTelegramChannel()) || undefined"
                        @click="registerTelegramWebhook(editDialogTelegramChannel())"
                    >
                        <LoaderCircleIcon
                            v-if="webhookLoadingUuid === editingUuid"
                            class="mr-2 size-4 animate-spin"
                        />
                        Register webhook with Telegram
                    </Button>
                    <p class="app-muted-text text-xs">
                        Calls Telegram setWebhook using PUBLIC_APP_URL. Save the channel first if you changed the bot token.
                    </p>
                </div>

                <div
                    v-else-if="form.type === 'slack'"
                    class="space-y-3 rounded-app-container border p-4"
                >
                    <p class="app-muted-text text-sm">Slack delivery is not wired yet; you can save credentials for later.</p>
                    <div class="space-y-2">
                        <Label for="channel-slack-token">Bot token</Label>
                        <Input
                            id="channel-slack-token"
                            v-model="form.slack_bot_token"
                            type="password"
                            autocomplete="new-password"
                            class="font-mono text-xs"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="channel-slack-signing">Signing secret</Label>
                        <Input
                            id="channel-slack-signing"
                            v-model="form.slack_signing_secret"
                            type="password"
                            autocomplete="new-password"
                            class="font-mono text-xs"
                        />
                    </div>
                </div>

                <div
                    v-else-if="form.type === 'webhook'"
                    class="space-y-3 rounded-app-container border p-4"
                >
                    <p class="app-muted-text text-sm">Outbound webhook channel placeholder for future use.</p>
                    <div class="space-y-2">
                        <Label for="channel-webhook-url">URL</Label>
                        <Input
                            id="channel-webhook-url"
                            v-model="form.webhook_url"
                            autocomplete="off"
                            class="font-mono text-xs"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="channel-webhook-secret">Secret</Label>
                        <Input
                            id="channel-webhook-secret"
                            v-model="form.webhook_secret"
                            type="password"
                            autocomplete="new-password"
                            class="font-mono text-xs"
                        />
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="channel-metadata">Metadata JSON (optional)</Label>
                    <Textarea
                        id="channel-metadata"
                        v-model="form.metadataText"
                        autocomplete="off"
                        rows="3"
                        class="resize-y font-mono text-xs"
                        placeholder="{}"
                    />
                </div>

                <p v-if="formError" class="text-sm text-destructive">
                    {{ formError }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false">
                    Cancel
                </Button>
                <Button :disabled="saving || editLoading" @click="submitForm">
                    <LoaderCircleIcon v-if="saving" class="mr-2 size-4 animate-spin" />
                    Save
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="deleteDialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete delivery channel?</DialogTitle>
                <DialogDescription>
                    This removes "{{ deleteTarget?.name }}" and its thread mappings. Telegram webhooks should be removed first.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleteDialogOpen = false">
                    Cancel
                </Button>
                <Button variant="destructive" @click="confirmDelete">
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
