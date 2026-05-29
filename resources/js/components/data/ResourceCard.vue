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
        class="app-surface app-card-hover app-focus-ring cursor-pointer"
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
            <div class="app-surface-muted grid grid-cols-3 gap-3 rounded-3xl p-3 text-sm">
                <div v-for="stat in stats" :key="stat.key">
                    <p class="app-muted-text">{{ stat.label }}</p>
                    <p class="font-semibold">{{ item[stat.key] }}</p>
                </div>
            </div>
            <p class="app-muted-text text-sm">
                {{ item.owner }} · {{ item.region }}
            </p>
        </CardContent>
    </Card>
</template>
