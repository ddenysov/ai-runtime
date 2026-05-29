<script setup>
import { computed } from 'vue';
import { Building2Icon, ChevronDownIcon, CircleCheckIcon } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = defineProps({
    workspaces: {
        type: Array,
        required: true,
    },
});

const selected = defineModel({
    type: String,
    required: true,
});

const currentWorkspace = computed(
    () => props.workspaces.find((workspace) => workspace.value === selected.value) ?? props.workspaces[0],
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                class="app-interactive-muted app-focus-ring mb-6 flex h-14 w-full cursor-pointer items-center justify-between gap-3 rounded-3xl px-3 py-2 text-left"
                type="button"
            >
                <span class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="app-icon-surface size-9 shrink-0">
                        <Building2Icon class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold leading-5 text-foreground">
                            {{ currentWorkspace.label }}
                        </span>
                        <span class="app-muted-text block truncate text-xs leading-4">
                            {{ currentWorkspace.plan }} plan · Workspace context
                        </span>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="app-soft-pill hidden px-2 py-1 text-xs font-medium xl:inline-flex">
                        Switch
                    </span>
                    <ChevronDownIcon class="app-muted-text size-4" />
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="w-64">
            <DropdownMenuItem
                v-for="workspace in workspaces"
                :key="workspace.value"
                class="cursor-pointer p-2"
                @click="selected = workspace.value"
            >
                <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ workspace.label }}</p>
                        <p class="app-muted-text text-xs">{{ workspace.plan }} plan</p>
                    </div>
                    <CircleCheckIcon
                        v-if="workspace.value === selected"
                        class="size-4 shrink-0 text-emerald-600"
                    />
                </div>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
