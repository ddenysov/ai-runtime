<script setup>
import { BellIcon, MenuIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useMobileSidebar } from '@/composables/useMobileSidebar';

defineProps({
    title: {
        type: String,
        required: true,
    },
});

const mobileSidebar = useMobileSidebar();
</script>

<template>
    <header class="app-page-header shrink-0">
        <div class="app-page-header-content">
            <div class="flex items-start gap-3">
                <Button
                    v-if="mobileSidebar"
                    variant="outline"
                    size="icon"
                    class="app-soft-control mt-0.5 shrink-0 lg:hidden"
                    @click="mobileSidebar.toggle()"
                >
                    <MenuIcon class="size-4" />
                    <span class="sr-only">Open menu</span>
                </Button>

                <div class="min-w-0">
                    <slot name="breadcrumbs" />
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ title }}</h1>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="icon" class="app-soft-control">
                    <BellIcon class="size-4" />
                </Button>
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
