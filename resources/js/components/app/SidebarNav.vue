<script setup>
defineProps({
    items: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <nav class="space-y-1">
        <button
            v-for="item in items"
            :key="item.label"
            type="button"
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
            :class="item.active
                ? 'bg-slate-950 text-white shadow-sm'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </button>
    </nav>
</template>
