<script setup>
import AppLogo from '@/components/app/AppLogo.vue';
import SidebarNav from '@/components/app/SidebarNav.vue';
import SidebarPromoCard from '@/components/app/SidebarPromoCard.vue';
import WorkspaceSwitcher from '@/components/app/WorkspaceSwitcher.vue';

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
        default: () => ({
            title: 'Usage guardrails',
            description: 'Budget, rate limits and model policies are inherited per workspace.',
            actionLabel: 'Review policies',
        }),
    },
});

const selectedWorkspace = defineModel('workspace', {
    type: String,
    required: true,
});
</script>

<template>
    <aside class="app-sidebar">
        <AppLogo />
        <WorkspaceSwitcher v-model="selectedWorkspace" :workspaces="workspaces" />
        <SidebarNav :items="navigation" />
        <SidebarPromoCard
            :title="promo.title"
            :description="promo.description"
            :action-label="promo.actionLabel"
        />
    </aside>
</template>
