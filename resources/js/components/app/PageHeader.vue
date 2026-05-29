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
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/85 px-4 py-3 backdrop-blur md:px-8">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <slot name="breadcrumbs" />
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ title }}</h1>
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="icon" class="rounded-xl bg-white">
                    <BellIcon class="size-4" />
                </Button>
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
