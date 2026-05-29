<script setup>
import { SearchIcon } from '@lucide/vue';
import { Input } from '@/components/ui/input';

const model = defineModel({ type: String, default: '' });

defineProps({
    placeholder: {
        type: String,
        default: 'Search...',
    },
});
</script>

<template>
    <div class="relative min-w-64">
        <SearchIcon class="app-muted-text pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2" />
        <Input
            v-model="model"
            class="app-soft-control h-10 pl-9"
            :placeholder="placeholder"
        />
    </div>
</template>
