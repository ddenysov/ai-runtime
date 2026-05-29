<script setup>
import { ref } from 'vue';
import DataCardGrid from '@/components/data/DataCardGrid.vue';
import DataPanel from '@/components/data/DataPanel.vue';
import DataTable from '@/components/data/DataTable.vue';
import ResourceActionsMenu from '@/components/data/ResourceActionsMenu.vue';
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

function openResource(item) {
    console.info('Open resource', item.name);
}

function editResource(item) {
    console.info('Edit resource', item.name);
}

function deleteResource(item) {
    console.info('Delete resource', item.name);
}
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
            clickable
            :columns="providerColumns"
            :items="filteredItems"
            @row-click="openResource"
        >
            <template #cell-name="{ item }">
                <div>
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="app-muted-text text-sm">{{ item.type }} · Updated {{ item.updated }}</p>
                </div>
            </template>

            <template #cell-status="{ item }">
                <StatusBadge :status="item.status" />
            </template>

            <template #cell-cost="{ item }">
                <span class="font-medium">{{ item.cost }}</span>
            </template>

            <template #row-actions="{ item }">
                <ResourceActionsMenu
                    @open="openResource(item)"
                    @edit="editResource(item)"
                    @delete="deleteResource(item)"
                />
            </template>
        </DataTable>

        <DataCardGrid v-else :items="filteredItems">
            <template #default="{ item }">
                <ResourceCard
                    :item="item"
                    @open="openResource(item)"
                    @edit="editResource(item)"
                    @delete="deleteResource(item)"
                />
            </template>
        </DataCardGrid>
    </DataPanel>
</template>
