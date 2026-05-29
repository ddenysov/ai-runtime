<script setup>
import { ref } from 'vue';
import { PlusIcon } from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatCard from '@/components/data/StatCard.vue';
import { Button } from '@/components/ui/button';
import ProvidersRegistry from '@/features/providers/ProvidersRegistry.vue';
import {
    metrics,
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const selectedWorkspace = ref('acme-ai');
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
                <Button class="rounded-2xl">
                    <PlusIcon class="size-4" />
                    New provider
                </Button>
            </template>
        </PageHeader>

        <div class="space-y-7 px-5 py-7 md:px-8 md:py-8">
            <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="metric in metrics"
                    :key="metric.label"
                    v-bind="metric"
                />
            </section>

            <ProvidersRegistry />
        </div>
    </AppShell>
</template>
