<script setup>
import { BellIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps({
    title: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <header class="app-page-header shrink-0">
        <div class="app-page-header-content">
            <div>
                <slot name="breadcrumbs" />
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ title }}</h1>
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="icon" class="app-soft-control">
                    <BellIcon class="size-4" />
                </Button>
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
