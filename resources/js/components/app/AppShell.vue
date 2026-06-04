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
    fixedViewport: {
        type: Boolean,
        default: false,
    },
});

const selectedWorkspace = defineModel('workspace', {
    type: String,
    required: true,
});
</script>

<template>
    <main
        class="app-page-shell"
        :class="fixedViewport ? 'h-screen overflow-hidden' : ''"
    >
        <div
            class="grid lg:grid-cols-[280px_1fr]"
            :class="fixedViewport ? 'h-full overflow-hidden' : 'min-h-screen'"
        >
            <AppSidebar
                v-model:workspace="selectedWorkspace"
                class="min-h-0"
                :class="fixedViewport ? 'h-full overflow-y-auto' : ''"
                :workspaces="workspaces"
                :navigation="navigation"
                :promo="promo"
            />

            <section
                class="flex min-w-0 flex-col"
                :class="fixedViewport ? 'h-full min-h-0 overflow-hidden' : ''"
            >
                <slot />
            </section>
        </div>
    </main>
</template>
