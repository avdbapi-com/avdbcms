import { Alpine as AlpineType } from 'alpinejs'

export {};

declare global {
    interface Window {
        USER: { id: number, avatar: string, is_premium: boolean } | null,
        PRIVATE_CHANNEL: any;
        LANG: string;
        LOCALE_PREFIX: string;
        AVD_SETTINGS?: {
            effects?: boolean;
            animeEffects?: boolean;
            themeMemory?: boolean;
            theaterMemory?: boolean;
            smoothScroll?: boolean;
            copyToast?: boolean;
            lazyComments?: boolean;
            enhancedSelects?: boolean;
            commentsAllowReplies?: boolean;
            homeWidgetMotion?: {
                enabled?: boolean;
                style?: 'slide' | 'lift' | 'zoom' | 'fade';
                durationMs?: number;
                distancePx?: number;
                staggerMs?: number;
                cardCascade?: boolean;
                autoRun?: boolean;
                pauseOnHover?: boolean;
                speedPxPerSecond?: number;
            };
        }
        Alpine: AlpineType
        Cropper: any
        Sortable: any
        Pusher : any
        Chart: any
        Echo : any
    }
}
