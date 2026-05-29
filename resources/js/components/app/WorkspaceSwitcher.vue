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
                class="mb-6 flex h-14 w-full cursor-pointer items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                type="button"
            >
                <span class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/80 text-slate-700 ring-1 ring-slate-200">
                        <Building2Icon class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold leading-5 text-slate-950">
                            {{ currentWorkspace.label }}
                        </span>
                        <span class="block truncate text-xs leading-4 text-slate-500">
                            {{ currentWorkspace.plan }} plan · Workspace context
                        </span>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="hidden rounded-lg bg-white px-2 py-1 text-xs font-medium text-slate-500 ring-1 ring-slate-200 xl:inline-flex">
                        Switch
                    </span>
                    <ChevronDownIcon class="size-4 text-slate-500" />
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
                        <p class="text-xs text-slate-500">{{ workspace.plan }} plan</p>
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
