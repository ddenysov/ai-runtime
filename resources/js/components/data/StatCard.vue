<script setup>
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineProps({
    label: {
        type: String,
        required: true,
    },
    value: {
        type: String,
        required: true,
    },
    change: {
        type: String,
        required: true,
    },
    tone: {
        type: String,
        default: 'text-slate-600',
    },
});
</script>

<template>
    <Card class="border-slate-200 bg-white shadow-sm">
        <CardHeader class="pb-2">
            <CardDescription>{{ label }}</CardDescription>
            <CardTitle class="text-2xl">{{ value }}</CardTitle>
        </CardHeader>
        <CardContent>
            <p class="text-sm font-medium" :class="tone">{{ change }}</p>
        </CardContent>
    </Card>
</template>
