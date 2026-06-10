<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { CalendarClockIcon, LoaderCircleIcon, PlayIcon, PlusIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    createAgentSchedule,
    deleteAgentSchedule,
    getAgentSchedule,
    listAgentChats,
    listAgentSchedules,
    runAgentScheduleNow,
    updateAgentSchedule,
} from '@/lib/api';

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
    agentActive: {
        type: Boolean,
        default: true,
    },
});

const router = useRouter();

const scheduleTypes = ['daily', 'weekly', 'interval', 'cron'];
const scheduleTypeLabels = {
    daily: 'Daily',
    weekly: 'Weekly',
    interval: 'Interval',
    cron: 'Cron',
};

const dayOptions = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 0, label: 'Sun' },
];

const timezoneOptions = typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : [
        'UTC',
        'Europe/Kyiv',
        'Europe/London',
        'Europe/Berlin',
        'America/New_York',
        'America/Los_Angeles',
        'Asia/Tokyo',
    ];

const schedules = ref([]);
const listError = ref('');
const loading = ref(false);
const dialogOpen = ref(false);
const dialogMode = ref('add');
const formError = ref('');
const saving = ref(false);
const editLoading = ref(false);
const editingUuid = ref(null);
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const runNowLoadingUuid = ref(null);
const toggleLoadingUuid = ref(null);
const chatContexts = ref([]);
const loadingContexts = ref(false);

const form = ref({
    name: '',
    enabled: true,
    schedule_type: 'daily',
    time: '09:00',
    days_of_week: [1, 2, 3, 4, 5],
    day_of_week: 1,
    every_minutes: 60,
    cron_expression: '0 9 * * 1-5',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    message: '',
    context_mode: 'new',
    context_id: '',
});

const inactiveWarning = computed(() => !props.agentActive);

function resetForm() {
    form.value = {
        name: '',
        enabled: true,
        schedule_type: 'daily',
        time: '09:00',
        days_of_week: [1, 2, 3, 4, 5],
        day_of_week: 1,
        every_minutes: 60,
        cron_expression: '0 9 * * 1-5',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        message: '',
        context_mode: 'new',
        context_id: '',
    };
}

function firstError(data) {
    if (!data?.errors) {
        return data?.message ?? null;
    }

    const first = Object.values(data.errors).flat()[0];

    return typeof first === 'string' ? first : data?.message ?? null;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function formatDays(days) {
    if (!Array.isArray(days) || !days.length) {
        return 'no days';
    }

    const labels = dayOptions.reduce((acc, day) => {
        acc[day.value] = day.label;

        return acc;
    }, {});

    return days
        .map((day) => labels[Number(day)] ?? String(day))
        .join(', ');
}

function formatScheduleSummary(schedule) {
    const config = schedule?.schedule_config ?? {};
    const type = schedule?.schedule_type;

    if (type === 'daily') {
        return `Daily at ${config.time ?? '?'} (${formatDays(config.days_of_week)})`;
    }

    if (type === 'weekly') {
        const dayLabel = dayOptions.find((d) => d.value === Number(config.day_of_week))?.label
            ?? config.day_of_week;

        return `Weekly on ${dayLabel} at ${config.time ?? '?'}`;
    }

    if (type === 'interval') {
        return `Every ${config.every_minutes ?? '?'} min`;
    }

    if (type === 'cron') {
        return schedule.cron_expression ?? config.expression ?? 'Cron';
    }

    return type ?? 'Unknown';
}

function lastRunBadge(schedule) {
    if (schedule?.last_error) {
        return { label: 'Last run failed', variant: 'outline', class: 'border-destructive/40 text-destructive' };
    }

    if (schedule?.last_run_at) {
        return { label: 'Last run queued', variant: 'secondary', class: '' };
    }

    return { label: 'Never run', variant: 'outline', class: 'text-muted-foreground' };
}

function buildScheduleConfig() {
    const type = form.value.schedule_type;

    if (type === 'daily') {
        return {
            time: form.value.time,
            days_of_week: [...form.value.days_of_week].sort((a, b) => a - b),
        };
    }

    if (type === 'weekly') {
        return {
            time: form.value.time,
            day_of_week: Number(form.value.day_of_week),
        };
    }

    if (type === 'interval') {
        return {
            every_minutes: Number(form.value.every_minutes),
        };
    }

    return {
        expression: form.value.cron_expression.trim(),
    };
}

function applyScheduleToForm(schedule) {
    const config = schedule?.schedule_config ?? {};
    const type = scheduleTypes.includes(schedule?.schedule_type)
        ? schedule.schedule_type
        : 'daily';

    form.value = {
        name: schedule?.name ?? '',
        enabled: !!schedule?.enabled,
        schedule_type: type,
        time: config.time ?? '09:00',
        days_of_week: Array.isArray(config.days_of_week) && config.days_of_week.length
            ? config.days_of_week.map((day) => Number(day))
            : [1, 2, 3, 4, 5],
        day_of_week: Number(config.day_of_week ?? 1),
        every_minutes: Number(config.every_minutes ?? 60),
        cron_expression: config.expression ?? schedule?.cron_expression ?? '0 9 * * 1-5',
        timezone: schedule?.timezone ?? (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'),
        message: schedule?.message ?? '',
        context_mode: schedule?.context_id ? 'fixed' : 'new',
        context_id: schedule?.context_id ?? '',
    };
}

function resolveContextId() {
    if (form.value.context_mode === 'new') {
        return null;
    }

    const id = form.value.context_id.trim();

    return id || null;
}

function toggleDay(dayValue, checked) {
    const days = new Set(form.value.days_of_week);

    if (checked) {
        days.add(dayValue);
    } else {
        days.delete(dayValue);
    }

    form.value.days_of_week = [...days].sort((a, b) => a - b);
}

function isDayChecked(dayValue) {
    return form.value.days_of_week.includes(dayValue);
}

async function loadSchedules() {
    listError.value = '';
    loading.value = true;

    try {
        const data = await listAgentSchedules(props.agentId);
        schedules.value = Array.isArray(data?.data) ? data.data : [];
    } catch (error) {
        schedules.value = [];
        listError.value = error.message ?? 'Could not load schedules.';
    } finally {
        loading.value = false;
    }
}

async function loadChatContexts() {
    loadingContexts.value = true;

    try {
        const data = await listAgentChats({
            id: props.agentId,
            perPage: 50,
            sort: '-last_message_at',
        });
        chatContexts.value = Array.isArray(data?.data) ? data.data : [];
    } catch {
        chatContexts.value = [];
    } finally {
        loadingContexts.value = false;
    }
}

function openAddDialog() {
    dialogMode.value = 'add';
    formError.value = '';
    editLoading.value = false;
    editingUuid.value = null;
    resetForm();
    dialogOpen.value = true;
    loadChatContexts();
}

async function openEditDialog(summary) {
    dialogMode.value = 'edit';
    formError.value = '';
    editLoading.value = true;
    editingUuid.value = summary?.uuid ?? null;
    dialogOpen.value = true;
    resetForm();
    loadChatContexts();

    if (!summary?.uuid) {
        editLoading.value = false;
        formError.value = 'Missing schedule identifier.';

        return;
    }

    try {
        const data = await getAgentSchedule(summary.uuid);
        const schedule = data?.data;

        if (!schedule) {
            throw new Error('Schedule payload was empty.');
        }

        applyScheduleToForm(schedule);
    } catch (error) {
        formError.value = error.message ?? 'Could not load schedule.';
    } finally {
        editLoading.value = false;
    }
}

async function submitForm() {
    formError.value = '';

    const name = form.value.name.trim();
    const message = form.value.message.trim();

    if (!name) {
        formError.value = 'Name is required.';

        return;
    }

    if (!message) {
        formError.value = 'Message is required.';

        return;
    }

    if (form.value.schedule_type === 'daily' && !form.value.days_of_week.length) {
        formError.value = 'Select at least one day of week.';

        return;
    }

    if (form.value.context_mode === 'fixed' && !form.value.context_id.trim()) {
        formError.value = 'Enter a context ID or choose a new context each run.';

        return;
    }

    saving.value = true;

    try {
        const body = {
            name,
            enabled: !!form.value.enabled,
            schedule_type: form.value.schedule_type,
            schedule_config: buildScheduleConfig(),
            timezone: form.value.timezone,
            message,
            context_id: resolveContextId(),
        };

        if (dialogMode.value === 'add') {
            await createAgentSchedule(props.agentId, body);
            toast.success('Schedule created');
        } else {
            await updateAgentSchedule(editingUuid.value, body);
            toast.success('Schedule updated');
        }

        dialogOpen.value = false;
        await loadSchedules();
    } catch (error) {
        formError.value = firstError(error.data) ?? error.message ?? 'Could not save schedule.';
    } finally {
        saving.value = false;
    }
}

function openDeleteDialog(schedule) {
    deleteTarget.value = schedule;
    deleteDialogOpen.value = true;
}

async function confirmDelete() {
    const schedule = deleteTarget.value;

    if (!schedule?.uuid) {
        deleteDialogOpen.value = false;

        return;
    }

    try {
        await deleteAgentSchedule(schedule.uuid);
        toast.success('Schedule deleted');
        await loadSchedules();
    } catch (error) {
        listError.value = firstError(error.data) ?? error.message ?? 'Could not delete schedule.';
    } finally {
        deleteDialogOpen.value = false;
        deleteTarget.value = null;
    }
}

async function toggleEnabled(schedule, enabled) {
    toggleLoadingUuid.value = schedule.uuid;

    try {
        await updateAgentSchedule(schedule.uuid, { enabled });
        toast.success(enabled ? 'Schedule enabled' : 'Schedule disabled');
        await loadSchedules();
    } catch (error) {
        toast.error(firstError(error.data) ?? error.message ?? 'Could not update schedule.');
    } finally {
        toggleLoadingUuid.value = null;
    }
}

async function runNow(schedule) {
    if (!schedule?.uuid) {
        return;
    }

    runNowLoadingUuid.value = schedule.uuid;

    try {
        await runAgentScheduleNow(schedule.uuid);
        toast.success('Run queued', {
            description: 'The agent will start shortly via the queue worker.',
        });
        await loadSchedules();
    } catch (error) {
        toast.error(firstError(error.data) ?? error.message ?? 'Could not queue run.');
    } finally {
        runNowLoadingUuid.value = null;
    }
}

function openLastRun(schedule) {
    if (schedule?.context_id) {
        router.push({
            name: 'agent-chat',
            params: {
                agentId: props.agentId,
                contextId: schedule.context_id,
            },
        });

        return;
    }

    router.push({
        name: 'agent-chat-history',
        params: { agentId: props.agentId },
    });
}

onMounted(loadSchedules);

watch(() => props.agentId, loadSchedules);
</script>

<template>
    <Card class="app-surface">
        <CardHeader class="flex flex-row items-start justify-between gap-3 space-y-0">
            <div>
                <CardTitle class="flex items-center gap-2">
                    <CalendarClockIcon class="app-muted-text size-4" />
                    Schedules
                </CardTitle>
                <CardDescription>
                    Run this agent automatically on a timer with a fixed starting prompt.
                </CardDescription>
            </div>
            <Button size="sm" @click="openAddDialog">
                <PlusIcon class="mr-2 size-4" />
                Add schedule
            </Button>
        </CardHeader>
        <CardContent class="space-y-4">
            <div
                v-if="inactiveWarning"
                class="rounded-app-container border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100"
            >
                This agent is inactive. Schedules are saved but will not run until the agent is enabled.
            </div>

            <div
                v-if="listError"
                class="rounded-app-container border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100"
            >
                {{ listError }}
                <Button class="mt-3" size="sm" variant="outline" @click="loadSchedules">
                    Retry
                </Button>
            </div>
            <Skeleton v-else-if="loading" class="h-24 rounded-app-container" />
            <template v-else-if="schedules.length">
                <div
                    v-for="schedule in schedules"
                    :key="schedule.uuid"
                    class="rounded-app-container border p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ schedule.name }}</p>
                            <p class="app-muted-text mt-1 text-sm">
                                {{ formatScheduleSummary(schedule) }}
                            </p>
                            <p class="app-muted-text mt-1 text-xs">
                                Timezone: {{ schedule.timezone }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <Badge variant="outline" class="rounded-full font-mono text-[11px]">
                                {{ scheduleTypeLabels[schedule.schedule_type] ?? schedule.schedule_type }}
                            </Badge>
                            <Badge :variant="schedule.enabled ? 'secondary' : 'outline'">
                                {{ schedule.enabled ? 'Enabled' : 'Disabled' }}
                            </Badge>
                            <Badge
                                :variant="lastRunBadge(schedule).variant"
                                :class="lastRunBadge(schedule).class"
                            >
                                {{ lastRunBadge(schedule).label }}
                            </Badge>
                        </div>
                    </div>

                    <div class="app-muted-text mt-3 grid gap-1 text-sm sm:grid-cols-2">
                        <p>
                            Next run:
                            <span class="text-foreground">{{ formatDate(schedule.next_run_at) }}</span>
                        </p>
                        <p>
                            Last run:
                            <button
                                v-if="schedule.last_run_at"
                                type="button"
                                class="text-primary underline-offset-4 hover:underline"
                                @click="openLastRun(schedule)"
                            >
                                {{ formatDate(schedule.last_run_at) }}
                            </button>
                            <span v-else class="text-foreground">—</span>
                        </p>
                    </div>

                    <p
                        v-if="schedule.last_error"
                        class="mt-2 text-sm text-destructive"
                    >
                        {{ schedule.last_error }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-2 pr-2">
                            <Switch
                                :id="`schedule-enabled-${schedule.uuid}`"
                                :checked="schedule.enabled"
                                :disabled="toggleLoadingUuid === schedule.uuid"
                                @update:checked="(value) => toggleEnabled(schedule, value)"
                            />
                            <Label :for="`schedule-enabled-${schedule.uuid}`" class="text-sm">
                                Enabled
                            </Label>
                        </div>
                        <Button size="sm" variant="outline" @click="openEditDialog(schedule)">
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!schedule.enabled || runNowLoadingUuid === schedule.uuid"
                            @click="runNow(schedule)"
                        >
                            <LoaderCircleIcon
                                v-if="runNowLoadingUuid === schedule.uuid"
                                class="mr-2 size-4 animate-spin"
                            />
                            <PlayIcon v-else class="mr-2 size-4" />
                            Run now
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="text-destructive"
                            @click="openDeleteDialog(schedule)"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </template>
            <p v-else class="app-muted-text text-sm">
                No schedules yet. Add one to run this agent on a daily or interval timer.
            </p>
        </CardContent>
    </Card>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ dialogMode === 'add' ? 'New schedule' : 'Edit schedule' }}</DialogTitle>
                <DialogDescription>
                    The message is sent as the starting prompt when the schedule fires.
                </DialogDescription>
            </DialogHeader>

            <div v-if="editLoading" class="py-8 text-center text-sm text-muted-foreground">
                Loading schedule…
            </div>

            <div v-else class="space-y-4">
                <div class="space-y-2">
                    <Label for="schedule-name">Name</Label>
                    <Input
                        id="schedule-name"
                        v-model="form.name"
                        autocomplete="off"
                        placeholder="e.g. Morning inbox check"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <Switch id="schedule-enabled" v-model:checked="form.enabled" />
                    <Label for="schedule-enabled">Enabled</Label>
                </div>

                <div class="space-y-2">
                    <Label for="schedule-type">Schedule type</Label>
                    <select
                        id="schedule-type"
                        v-model="form.schedule_type"
                        class="flex h-10 w-full rounded-app-control border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option v-for="type in scheduleTypes" :key="type" :value="type">
                            {{ scheduleTypeLabels[type] ?? type }}
                        </option>
                    </select>
                </div>

                <div
                    v-if="form.schedule_type === 'daily' || form.schedule_type === 'weekly'"
                    class="space-y-2"
                >
                    <Label for="schedule-time">Time</Label>
                    <Input
                        id="schedule-time"
                        v-model="form.time"
                        type="time"
                        step="60"
                    />
                </div>

                <div v-if="form.schedule_type === 'daily'" class="space-y-2">
                    <Label>Days of week</Label>
                    <div class="flex flex-wrap gap-3">
                        <label
                            v-for="day in dayOptions"
                            :key="day.value"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :checked="isDayChecked(day.value)"
                                @update:checked="(checked) => toggleDay(day.value, checked)"
                            />
                            {{ day.label }}
                        </label>
                    </div>
                </div>

                <div v-if="form.schedule_type === 'weekly'" class="space-y-2">
                    <Label for="schedule-weekday">Day of week</Label>
                    <select
                        id="schedule-weekday"
                        v-model.number="form.day_of_week"
                        class="flex h-10 w-full rounded-app-control border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option v-for="day in dayOptions" :key="day.value" :value="day.value">
                            {{ day.label }}
                        </option>
                    </select>
                </div>

                <div v-if="form.schedule_type === 'interval'" class="space-y-2">
                    <Label for="schedule-interval">Every N minutes</Label>
                    <Input
                        id="schedule-interval"
                        v-model.number="form.every_minutes"
                        type="number"
                        min="1"
                        max="525600"
                    />
                </div>

                <div v-if="form.schedule_type === 'cron'" class="space-y-2">
                    <Label for="schedule-cron">Cron expression</Label>
                    <Input
                        id="schedule-cron"
                        v-model="form.cron_expression"
                        class="font-mono text-xs"
                        placeholder="0 9 * * 1-5"
                    />
                    <p class="app-muted-text text-xs">
                        Standard five-field cron syntax (minute hour day month weekday).
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="schedule-timezone">Timezone</Label>
                    <select
                        id="schedule-timezone"
                        v-model="form.timezone"
                        class="flex h-10 w-full rounded-app-control border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option v-for="tz in timezoneOptions" :key="tz" :value="tz">
                            {{ tz }}
                        </option>
                    </select>
                </div>

                <div class="space-y-2">
                    <Label for="schedule-message">Message</Label>
                    <Textarea
                        id="schedule-message"
                        v-model="form.message"
                        rows="4"
                        class="resize-y text-sm"
                        placeholder="Starting prompt for the agent when this schedule runs."
                    />
                </div>

                <div class="space-y-3 rounded-app-container border p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Chat context
                    </p>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.context_mode"
                                type="radio"
                                value="new"
                                class="size-4"
                            >
                            New context each run
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.context_mode"
                                type="radio"
                                value="fixed"
                                class="size-4"
                            >
                            Reuse a fixed context
                        </label>
                    </div>
                    <div v-if="form.context_mode === 'fixed'" class="space-y-2">
                        <Label for="schedule-context">Context ID</Label>
                        <Input
                            id="schedule-context"
                            v-model="form.context_id"
                            class="font-mono text-xs"
                            placeholder="UUID from chat history"
                        />
                        <div v-if="loadingContexts" class="app-muted-text text-xs">
                            Loading recent contexts…
                        </div>
                        <div v-else-if="chatContexts.length" class="space-y-1">
                            <p class="app-muted-text text-xs">Recent contexts:</p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-for="chat in chatContexts.slice(0, 8)"
                                    :key="chat.context_id"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="max-w-full font-mono text-[10px]"
                                    @click="form.context_id = chat.context_id"
                                >
                                    {{ chat.context_id }}
                                </Button>
                            </div>
                        </div>
                    </div>
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
                <DialogTitle>Delete schedule?</DialogTitle>
                <DialogDescription>
                    This removes "{{ deleteTarget?.name }}". Scheduled runs will stop immediately.
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
