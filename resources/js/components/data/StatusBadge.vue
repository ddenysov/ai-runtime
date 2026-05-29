<script setup>
import { Badge } from '@/components/ui/badge';
import { statusClasses, statusIcons } from '@/lib/status';

defineProps({
    status: {
        type: String,
        required: true,
    },
    showIcon: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <Badge
        variant="outline"
        class="rounded-full"
        :class="statusClasses[status]"
    >
        <component
            :is="statusIcons[status]"
            v-if="showIcon && statusIcons[status]"
            class="size-3"
        />
        {{ status }}
    </Badge>
</template>
