<script setup>
import { LayoutGridIcon, ListIcon } from '@lucide/vue';

const model = defineModel({
    type: String,
    default: 'list',
});

const options = [
    { value: 'list', label: 'Listing', icon: ListIcon },
    { value: 'cards', label: 'Cards', icon: LayoutGridIcon },
];
</script>

<template>
    <div class="app-view-toggle">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="app-view-toggle-item"
            :class="model === option.value
                ? 'app-view-toggle-item-active'
                : 'app-view-toggle-item-idle'"
            @click="model = option.value"
        >
            <component :is="option.icon" class="size-4" />
            {{ option.label }}
        </button>
    </div>
</template>
