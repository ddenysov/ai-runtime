<script setup>
import { ArrowDownIcon, ArrowUpIcon, ChevronsUpDownIcon } from '@lucide/vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

const props = defineProps({
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
    clickable: {
        type: Boolean,
        default: false,
    },
    sort: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['row-click', 'sort']);

function sortField(column) {
    return column.sortKey ?? column.key;
}

function sortDirection(column) {
    const field = sortField(column);

    if (!field || !column.sortable) {
        return undefined;
    }

    if (props.sort === field) {
        return 'asc';
    }

    if (props.sort === `-${field}`) {
        return 'desc';
    }

    return undefined;
}

function nextSort(column) {
    const field = sortField(column);
    const direction = sortDirection(column);

    if (!field || !column.sortable) {
        return;
    }

    emit('sort', direction === 'asc' ? `-${field}` : field);
}

</script>

<template>
    <div class="overflow-x-auto">
        <Table>
            <TableHeader>
                <TableRow class="app-surface-muted">
                    <TableHead
                        v-for="column in columns"
                        :key="column.key"
                        :class="column.align === 'right' ? 'text-right' : undefined"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 font-medium"
                            :class="column.align === 'right' ? 'justify-end' : undefined"
                            @click="nextSort(column)"
                        >
                            {{ column.label }}
                            <ArrowUpIcon v-if="sortDirection(column) === 'asc'" class="size-3.5" />
                            <ArrowDownIcon v-else-if="sortDirection(column) === 'desc'" class="size-3.5" />
                            <ChevronsUpDownIcon v-else class="app-muted-text size-3.5" />
                        </button>
                        <template v-else>
                            {{ column.label }}
                        </template>
                    </TableHead>
                    <TableHead v-if="$slots['row-actions']" class="w-12" />
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="item in items"
                    :key="item[rowKey]"
                    :class="[
                        'app-table-row-hover',
                        clickable ? 'cursor-pointer' : undefined,
                    ]"
                    @click="clickable ? $emit('row-click', item) : undefined"
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
                    <TableCell v-if="$slots['row-actions']" @click.stop>
                        <slot name="row-actions" :item="item" />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
