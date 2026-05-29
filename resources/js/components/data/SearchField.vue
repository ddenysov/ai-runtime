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
        <SearchIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <Input
            v-model="model"
            class="h-10 rounded-2xl pl-9"
            :placeholder="placeholder"
        />
    </div>
</template>
