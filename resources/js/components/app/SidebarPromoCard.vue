<script setup>
import { Button } from '@/components/ui/button';
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
        required: true,
    },
    actionLabel: {
        type: String,
        default: 'Learn more',
    },
});
</script>

<template>
    <Card class="app-accent-surface mt-8">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm">{{ title }}</CardTitle>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>
        <CardContent>
            <Button variant="outline" size="sm" class="app-soft-control w-full">
                {{ actionLabel }}
            </Button>
        </CardContent>
    </Card>
</template>
