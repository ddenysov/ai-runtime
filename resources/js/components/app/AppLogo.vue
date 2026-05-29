<script setup>
import { BotIcon } from '@lucide/vue';

defineProps({
    name: {
        type: String,
        default: 'InfraPilot',
    },
    tagline: {
        type: String,
        default: 'AI infrastructure admin',
    },
});
</script>

<template>
    <div class="mb-8 flex items-center gap-3 px-2">
        <div class="app-primary-mark flex size-10 items-center justify-center rounded-2xl">
            <BotIcon class="size-5" />
        </div>
        <div>
            <p class="text-sm font-semibold">{{ name }}</p>
            <p class="app-muted-text text-xs">{{ tagline }}</p>
        </div>
    </div>
</template>
