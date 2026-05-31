<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
});

const routeItems = computed(() => props.items.filter((item) => item.route));
const buttonItems = computed(() => props.items.filter((item) => !item.route));
</script>

<template>
    <nav class="space-y-1">
        <RouterLink
            v-for="item in routeItems"
            :key="item.label"
            :to="item.route"
            class="app-nav-item"
            :class="item.active
                ? 'app-nav-item-active'
                : 'app-nav-item-idle'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </RouterLink>
        <button
            v-for="item in buttonItems"
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
