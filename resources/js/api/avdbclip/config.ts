function resolveApiUrl(): string {
    if (typeof window !== 'undefined') {
        if (window.location.pathname.includes('/index.php')) {
            return '/index.php/api';
        }
        const base = (window as any).API_BASE;
        if (base && typeof base === 'string') {
            return base;
        }
    }
    return '/api';
}

export const API_URL = resolveApiUrl();
