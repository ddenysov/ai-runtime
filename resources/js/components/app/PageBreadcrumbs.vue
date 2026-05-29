<script setup>
import { ChevronDownIcon } from '@lucide/vue';

defineProps({
    items: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <div class="flex items-center gap-2 text-sm text-slate-500">
        <template v-for="(item, index) in items" :key="item">
            <ChevronDownIcon
                v-if="index > 0"
                class="size-3 rotate-[-90deg]"
            />
            <span :class="index === items.length - 1 ? 'font-medium text-slate-700' : undefined">
                {{ item }}
            </span>
        </template>
    </div>
</template>
