<script setup>
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    actionLabel: {
        type: String,
        default: 'Open',
    },
});
</script>

<template>
    <Card class="border-slate-200 shadow-none transition hover:-translate-y-0.5 hover:shadow-md">
        <CardHeader>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <CardTitle class="text-base">{{ item.name }}</CardTitle>
                    <CardDescription>{{ item.type }}</CardDescription>
                </div>
                <StatusBadge :status="item.status" :show-icon="false" />
            </div>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid grid-cols-3 gap-3 rounded-2xl bg-slate-50 p-3 text-sm">
                <div v-for="stat in stats" :key="stat.key">
                    <p class="text-slate-500">{{ stat.label }}</p>
                    <p class="font-semibold">{{ item[stat.key] }}</p>
                </div>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ item.owner }} · {{ item.region }}</span>
                <Button variant="outline" size="sm">{{ actionLabel }}</Button>
            </div>
        </CardContent>
    </Card>
</template>
