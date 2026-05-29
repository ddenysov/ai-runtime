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
            class="app-nav-item"
            :class="item.active
                ? 'app-nav-item-active'
                : 'app-nav-item-idle'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </button>
    </nav>
</template>
