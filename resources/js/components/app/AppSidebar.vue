<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { LogOutIcon } from '@lucide/vue';
import AppLogo from '@/components/app/AppLogo.vue';
import SidebarNav from '@/components/app/SidebarNav.vue';
import SidebarPromoCard from '@/components/app/SidebarPromoCard.vue';
import WorkspaceSwitcher from '@/components/app/WorkspaceSwitcher.vue';
import { Button } from '@/components/ui/button';
import { logout as logoutRequest } from '@/lib/api';
import { clearCurrentUser } from '@/lib/auth';

defineProps({
    mobile: {
        type: Boolean,
        default: false,
    },
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

const router = useRouter();
const signingOut = ref(false);

async function signOut() {
    if (signingOut.value) {
        return;
    }

    signingOut.value = true;

    try {
        await logoutRequest();
    } finally {
        clearCurrentUser();
        signingOut.value = false;
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <aside class="app-sidebar" :class="{ 'app-sidebar-desktop': !mobile }">
        <AppLogo />
        <WorkspaceSwitcher v-model="selectedWorkspace" :workspaces="workspaces" />
        <SidebarNav :items="navigation" />
        <SidebarPromoCard
            :title="promo.title"
            :description="promo.description"
            :action-label="promo.actionLabel"
        />
        <Button
            class="mt-4 w-full justify-start"
            variant="ghost"
            :disabled="signingOut"
            @click="signOut"
        >
            <LogOutIcon class="size-4" />
            {{ signingOut ? 'Signing out...' : 'Sign out' }}
        </Button>
    </aside>
</template>
