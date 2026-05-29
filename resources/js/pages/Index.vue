<script setup>
import { Button } from '@/components/ui/button';

const message = 'Hello World';
</script>

<template>
    <main class="flex min-h-screen flex-col items-center justify-center gap-4">
        <h1 class="text-3xl font-semibold">{{ message }}</h1>
        <Button>Click me</Button>
    </main>
</template>
