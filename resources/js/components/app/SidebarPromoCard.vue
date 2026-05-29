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
    <Card class="mt-8 border-sky-100 bg-sky-50/70 shadow-none">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm">{{ title }}</CardTitle>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>
        <CardContent>
            <Button variant="outline" size="sm" class="w-full bg-white">
                {{ actionLabel }}
            </Button>
        </CardContent>
    </Card>
</template>
