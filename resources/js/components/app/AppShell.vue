<script setup>
import AppSidebar from '@/components/app/AppSidebar.vue';

defineProps({
    workspaces: {
        type: Array,
        required: true,
    },
    navigation: {
        type: Array,
        required: true,
    },
    promo: {
        type: Object,
        default: undefined,
    },
});

const selectedWorkspace = defineModel('workspace', {
    type: String,
    required: true,
});
</script>

<template>
    <main class="app-page-shell">
        <div class="grid min-h-screen lg:grid-cols-[280px_1fr]">
            <AppSidebar
                v-model:workspace="selectedWorkspace"
                :workspaces="workspaces"
                :navigation="navigation"
                :promo="promo"
            />

            <section class="flex min-w-0 flex-col">
                <slot />
            </section>
        </div>
    </main>
</template>
