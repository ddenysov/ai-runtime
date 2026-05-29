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
    <div class="flex h-10 rounded-2xl bg-slate-100 p-1">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="inline-flex h-8 items-center gap-2 rounded-xl px-3 text-sm font-medium transition"
            :class="model === option.value
                ? 'bg-white text-slate-950 shadow-sm'
                : 'text-slate-500 hover:text-slate-900'"
            @click="model = option.value"
        >
            <component :is="option.icon" class="size-4" />
            {{ option.label }}
        </button>
    </div>
</template>
