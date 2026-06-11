<script setup>
import { watch } from 'vue';
import { useRoute } from 'vue-router';
import AppSidebar from '@/components/app/AppSidebar.vue';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { provideMobileSidebar } from '@/composables/useMobileSidebar';

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

const route = useRoute();
const { open: mobileMenuOpen, close: closeMobileMenu } = provideMobileSidebar();

watch(() => route.fullPath, () => {
    closeMobileMenu();
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

        <Sheet :open="mobileMenuOpen" @update:open="mobileMenuOpen = $event">
            <SheetContent
                side="left"
                class="w-[280px] gap-0 border-r border-border/70 bg-sidebar p-0 sm:max-w-[280px]"
            >
                <AppSidebar
                    v-model:workspace="selectedWorkspace"
                    mobile
                    class="h-full overflow-y-auto"
                    :workspaces="workspaces"
                    :navigation="navigation"
                    :promo="promo"
                />
            </SheetContent>
        </Sheet>
    </main>
</template>
