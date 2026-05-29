<script setup>
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import ResourceActionsMenu from '@/components/data/ResourceActionsMenu.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';

defineProps({
    item: {
        type: Object,
        required: true,
    },
    stats: {
        type: Array,
        default: () => [
            { key: 'agents', label: 'Agents' },
            { key: 'requests', label: 'Requests' },
            { key: 'cost', label: 'Cost' },
        ],
    },
});

const emit = defineEmits(['open', 'edit', 'delete']);
</script>

<template>
    <Card
        role="button"
        tabindex="0"
        class="cursor-pointer border-slate-200 shadow-none transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
        @click="emit('open')"
        @keydown.enter="emit('open')"
        @keydown.space.prevent="emit('open')"
    >
        <CardHeader>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <CardTitle class="text-base">{{ item.name }}</CardTitle>
                    <CardDescription>{{ item.type }}</CardDescription>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <StatusBadge :status="item.status" :show-icon="false" />
                    <ResourceActionsMenu
                        @open="emit('open')"
                        @edit="emit('edit')"
                        @delete="emit('delete')"
                    />
                </div>
            </div>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid grid-cols-3 gap-3 rounded-2xl bg-slate-50 p-3 text-sm">
                <div v-for="stat in stats" :key="stat.key">
                    <p class="text-slate-500">{{ stat.label }}</p>
                    <p class="font-semibold">{{ item[stat.key] }}</p>
                </div>
            </div>
            <p class="text-sm text-slate-500">
                {{ item.owner }} · {{ item.region }}
            </p>
        </CardContent>
    </Card>
</template>
