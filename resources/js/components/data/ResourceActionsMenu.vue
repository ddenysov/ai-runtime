<script setup>
import { ExternalLinkIcon, MoreHorizontalIcon, PencilIcon, Trash2Icon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const emit = defineEmits(['open', 'edit', 'delete']);
</script>

<template>
    <div class="shrink-0" @click.stop>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="icon-sm" class="app-muted-text rounded-app-compact">
                    <MoreHorizontalIcon class="size-4" />
                    <span class="sr-only">Actions</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-40">
                <DropdownMenuItem class="cursor-pointer" @click="emit('open')">
                    <ExternalLinkIcon />
                    Open
                </DropdownMenuItem>
                <DropdownMenuItem class="cursor-pointer" @click="emit('edit')">
                    <PencilIcon />
                    Edit
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    class="cursor-pointer"
                    @click="emit('delete')"
                >
                    <Trash2Icon />
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
