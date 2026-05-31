<script setup>
import { ref } from 'vue';
import { PlusIcon } from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import CreateProviderDialog from '@/features/providers/CreateProviderDialog.vue';
import ProvidersRegistry from '@/features/providers/ProvidersRegistry.vue';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const selectedWorkspace = ref('acme-ai');
const createProviderOpen = ref(false);
const providersRegistry = ref(null);

function refreshProviders() {
    providersRegistry.value?.reload();
}
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="navigation"
    >
        <PageHeader title="AI Infrastructure">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Providers']" />
            </template>

            <template #actions>
                <Button variant="outline" class="app-soft-control">
                    Export
                </Button>
                <Button
                    class="rounded-app-control"
                    @click="createProviderOpen = true"
                >
                    <PlusIcon class="size-4" />
                    New provider
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <ProvidersRegistry ref="providersRegistry" />
        </div>

        <CreateProviderDialog
            v-model:open="createProviderOpen"
            @created="refreshProviders"
        />
    </AppShell>
</template>
