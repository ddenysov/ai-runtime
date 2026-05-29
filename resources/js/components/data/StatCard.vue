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
        default: 'app-muted-text',
    },
});
</script>

<template>
    <Card class="app-surface">
        <CardHeader class="pb-2">
            <CardDescription>{{ label }}</CardDescription>
            <CardTitle class="text-2xl">{{ value }}</CardTitle>
        </CardHeader>
        <CardContent>
            <p class="text-sm font-medium" :class="tone">{{ change }}</p>
        </CardContent>
    </Card>
</template>
