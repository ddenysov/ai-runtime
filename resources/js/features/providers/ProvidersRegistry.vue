<script setup>
import { ref } from 'vue';
import { MoreHorizontalIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import DataCardGrid from '@/components/data/DataCardGrid.vue';
import DataPanel from '@/components/data/DataPanel.vue';
import DataTable from '@/components/data/DataTable.vue';
import ResourceCard from '@/components/data/ResourceCard.vue';
import SearchField from '@/components/data/SearchField.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import ViewModeToggle from '@/components/data/ViewModeToggle.vue';
import { useFilteredList } from '@/composables/useFilteredList';
import { providerColumns, providerItems } from '@/features/providers/providers.mock';

const viewMode = ref('list');
const { query: searchQuery, filtered: filteredItems } = useFilteredList(providerItems, [
    'name',
    'type',
    'status',
    'owner',
    'region',
]);
</script>

<template>
    <DataPanel
        title="Providers registry"
        description="One reusable CRUD pattern for providers, agents, MCP workflows and shared tenant infrastructure."
        :count="filteredItems.length"
    >
        <template #toolbar>
            <SearchField
                v-model="searchQuery"
                placeholder="Search by name, owner, region..."
            />
            <ViewModeToggle v-model="viewMode" />
        </template>

        <DataTable
            v-if="viewMode === 'list'"
            :columns="providerColumns"
            :items="filteredItems"
        >
            <template #cell-name="{ item }">
                <div>
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="text-sm text-slate-500">{{ item.type }} · Updated {{ item.updated }}</p>
                </div>
            </template>

            <template #cell-status="{ item }">
                <StatusBadge :status="item.status" />
            </template>

            <template #cell-cost="{ item }">
                <span class="font-medium">{{ item.cost }}</span>
            </template>

            <template #row-actions>
                <Button variant="ghost" size="icon-sm">
                    <MoreHorizontalIcon class="size-4" />
                </Button>
            </template>
        </DataTable>

        <DataCardGrid v-else :items="filteredItems">
            <template #default="{ item }">
                <ResourceCard :item="item" />
            </template>
        </DataCardGrid>
    </DataPanel>
</template>
