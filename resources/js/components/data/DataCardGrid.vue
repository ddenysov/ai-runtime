<script setup>
defineProps({
    items: {
        type: Array,
        required: true,
    },
    rowKey: {
        type: String,
        default: 'name',
    },
});
</script>

<template>
    <div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
        <slot
            v-for="item in items"
            :key="item[rowKey]"
            :item="item"
        />
    </div>
</template>
