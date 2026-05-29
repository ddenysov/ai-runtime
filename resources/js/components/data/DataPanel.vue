<script setup>
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    count: {
        type: Number,
        default: undefined,
    },
    countLabel: {
        type: String,
        default: 'resources',
    },
});
</script>

<template>
    <Card class="overflow-hidden border-slate-200 bg-white shadow-sm">
        <CardHeader class="border-b border-slate-100">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <CardTitle>{{ title }}</CardTitle>
                        <Badge
                            v-if="count !== undefined"
                            variant="outline"
                            class="rounded-full"
                        >
                            {{ count }} {{ countLabel }}
                        </Badge>
                    </div>
                    <CardDescription v-if="description" class="mt-1 max-w-2xl">
                        {{ description }}
                    </CardDescription>
                </div>

                <div v-if="$slots.toolbar" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <slot name="toolbar" />
                </div>
            </div>
        </CardHeader>

        <CardContent class="p-0">
            <slot />
        </CardContent>
    </Card>
</template>
