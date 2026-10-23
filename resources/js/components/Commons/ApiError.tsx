import {useTranslation} from "react-i18next";

type Props = {
    refetch: Function
    error: Error
}

export function ApiError ({refetch, error}: Props) {
    const { t } = useTranslation();

    return (
        <div className="avd-error-state text-center p-4 p-md-5">
            <i className="fa-solid fa-triangle-exclamation fa-3x mb-3 text-danger"></i>
            <h3 className="h5 my-2 fw-black">{t('Something went wrong')}</h3>
            <p className="text-muted">{error.message ?? t('Unknown error')}</p>
            <button className="btn btn-primary btn-sm px-4" onClick={() => refetch()}>
                {t('Try again')}
            </button>
        </div>
    )
}
