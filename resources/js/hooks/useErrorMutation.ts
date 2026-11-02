
import {useMutation, UseMutationOptions, UseMutationResult} from "@tanstack/react-query";
import {useTranslation} from "react-i18next";
import {show as showToast} from "../functions/toast";
import {errorText} from "../functions/errorText";

export {errorText};

// The toast of a refused or failed request says why in the member's language (R1-016: a raw English "Unauthenticated."
// for a session that ended), then the caller's own onError runs (a rollback) with every argument react-query gives it
// (the error, the variables, what onMutate returned and the mutation's context); before, a caller's onError was
// replaced by the toast.
export function useErrorMutation<TData = unknown, TError = unknown, TVariables = void, TContext = unknown>(options: UseMutationOptions<TData, TError, TVariables, TContext>) : UseMutationResult<TData, TError, TVariables, TContext> {
    const {t} = useTranslation();

    return useMutation({
        ...options,
        onError: (error, variables, onMutateResult, context) => {
            showToast(errorText(error, (key: string) => t(key)));
            return options.onError?.(error, variables, onMutateResult, context);
        },
    });
}
