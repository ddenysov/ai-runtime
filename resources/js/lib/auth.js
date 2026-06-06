import { getCurrentUser } from '@/lib/api';

let currentUser = null;
let resolved = false;

export async function loadCurrentUser({ force = false } = {}) {
    if (resolved && !force) {
        return currentUser;
    }

    try {
        const response = await getCurrentUser();
        currentUser = response.data;
    } catch (error) {
        if (error.status !== 401) {
            throw error;
        }

        currentUser = null;
    } finally {
        resolved = true;
    }

    return currentUser;
}

export function setCurrentUser(user) {
    currentUser = user;
    resolved = true;
}

export function clearCurrentUser() {
    currentUser = null;
    resolved = false;
}
