<script setup>
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

defineProps({
    columns: {
        type: Array,
        required: true,
    },
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
    <div class="overflow-x-auto">
        <Table>
            <TableHeader>
                <TableRow class="bg-slate-50/80">
                    <TableHead
                        v-for="column in columns"
                        :key="column.key"
                        :class="column.align === 'right' ? 'text-right' : undefined"
                    >
                        {{ column.label }}
                    </TableHead>
                    <TableHead v-if="$slots['row-actions']" class="w-12" />
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="item in items"
                    :key="item[rowKey]"
                    class="hover:bg-slate-50"
                >
                    <TableCell
                        v-for="column in columns"
                        :key="column.key"
                        :class="[
                            column.align === 'right' ? 'text-right' : undefined,
                            column.cellClass,
                        ]"
                    >
                        <slot :name="`cell-${column.key}`" :item="item" :column="column">
                            {{ item[column.key] }}
                        </slot>
                    </TableCell>
                    <TableCell v-if="$slots['row-actions']">
                        <slot name="row-actions" :item="item" />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
