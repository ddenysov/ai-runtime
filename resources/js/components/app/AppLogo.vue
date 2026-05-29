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
        <div class="flex size-10 items-center justify-center rounded-2xl bg-slate-950 text-white shadow-sm">
            <BotIcon class="size-5" />
        </div>
        <div>
            <p class="text-sm font-semibold">{{ name }}</p>
            <p class="text-xs text-slate-500">{{ tagline }}</p>
        </div>
    </div>
</template>
