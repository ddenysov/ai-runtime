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
    <div class="app-muted-text flex items-center gap-2 text-sm">
        <template v-for="(item, index) in items" :key="item">
            <ChevronDownIcon
                v-if="index > 0"
                class="size-3 rotate-[-90deg]"
            />
            <span :class="index === items.length - 1 ? 'font-medium text-foreground/75' : undefined">
                {{ item }}
            </span>
        </template>
    </div>
</template>
