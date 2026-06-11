import { inject, provide, ref } from 'vue';

const MOBILE_SIDEBAR_KEY = Symbol('mobileSidebar');

export function provideMobileSidebar() {
    const open = ref(false);

    function toggle() {
        open.value = !open.value;
    }

    function close() {
        open.value = false;
    }

    const context = { open, toggle, close };

    provide(MOBILE_SIDEBAR_KEY, context);

    return context;
}

export function useMobileSidebar() {
    return inject(MOBILE_SIDEBAR_KEY, null);
}
