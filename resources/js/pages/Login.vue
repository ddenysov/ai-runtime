<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BotIcon } from '@lucide/vue';
import { login } from '@/lib/api';
import { setCurrentUser } from '@/lib/auth';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const route = useRoute();
const router = useRouter();

const loginValue = ref('');
const password = ref('');
const remember = ref(false);
const loading = ref(false);
const errorMessage = ref('');

const redirectTo = computed(() => {
    const redirect = route.query.redirect;

    return typeof redirect === 'string' && redirect.startsWith('/')
        ? redirect
        : '/';
});

async function submit() {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await login({
            login: loginValue.value,
            password: password.value,
            remember: remember.value,
        });

        setCurrentUser(response.data);
        await router.push(redirectTo.value);
    } catch (error) {
        errorMessage.value = error.status === 422
            ? 'Invalid login or password.'
            : 'Unable to sign in. Please try again.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-background px-4 py-10 text-foreground">
        <Card class="w-full max-w-md border-border/70 shadow-lg shadow-foreground/5">
            <CardHeader class="space-y-4 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-sm shadow-primary/20">
                    <BotIcon class="size-6" />
                </div>
                <div class="space-y-2">
                    <CardTitle class="text-2xl">
                        Sign in
                    </CardTitle>
                    <CardDescription>
                        Use your system login to access the AI runtime.
                    </CardDescription>
                </div>
            </CardHeader>

            <CardContent>
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="login">Login</Label>
                        <Input
                            id="login"
                            v-model="loginValue"
                            autocomplete="username"
                            autofocus
                            placeholder="admin"
                            required
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="password"
                            autocomplete="current-password"
                            placeholder="admin"
                            required
                            type="password"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                        <input
                            v-model="remember"
                            class="size-4 rounded border-border"
                            type="checkbox"
                        >
                        Remember me
                    </label>

                    <p
                        v-if="errorMessage"
                        class="rounded-app-control border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ errorMessage }}
                    </p>

                    <Button
                        class="w-full"
                        size="lg"
                        type="submit"
                        :disabled="loading"
                    >
                        {{ loading ? 'Signing in...' : 'Sign in' }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </main>
</template>
