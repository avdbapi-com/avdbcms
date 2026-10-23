import { useState } from 'preact/hooks';
import {useTranslation} from "react-i18next";
import {subscribe} from "@/api/avdbclip";
import {QueryClient, QueryClientProvider} from '@tanstack/react-query'
import {useErrorMutation} from "@/hooks/useErrorMutation";
import {clsx} from "clsx";

type Props = {
    isSubscribe?: boolean,
    user: number,
    size?: string | null,
    class?: string,
    className?: string,
}

function Main ({isSubscribe = true, user, size = null, class: elementClass = '', className = ''} : Props) {

    const {isPending, mutate} = useErrorMutation({
        mutationKey: ['subscribe', user],
        mutationFn: () => subscribe(user),
        onSuccess: () => setSubscribed(v => !v),
    })

    const { t } = useTranslation();

    const [subscribed, setSubscribed] = useState<boolean>(isSubscribe);

    return (
        <button
            type="button"
            onClick={() => mutate()}
            className={clsx("avd-subscribe-button btn rounded-4 px-3 d-inline-flex align-items-center justify-content-center text-nowrap", elementClass, className, {
                "bg-light-dark text-body": subscribed,
                "btn-dark": !subscribed,
                ['btn-' + size]: size,
            })}
            disabled={isPending}
            aria-pressed={subscribed}
            aria-busy={isPending}
            data-avd-control="subscribe"
        >
            {isPending ? (
                <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            ) : (
                <i className={clsx('fa-solid me-2', subscribed ? 'fa-check' : 'fa-user-plus')} aria-hidden="true"></i>
            )}
            <span className="avd-subscribe-label text-sm">{subscribed ? t('Subscribed') : t('Subscribe')}</span>
        </button>
    )
}

export function Subscribe (props : Props) {

    const queryClient = new QueryClient();

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
