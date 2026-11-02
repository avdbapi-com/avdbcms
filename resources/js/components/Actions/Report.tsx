import {Modal} from "react-bootstrap";
import {useState} from "preact/hooks";
import {ReportReason, ReportData, ReportDataSchema} from "@/types";
import {useErrorMutation} from "@/hooks";
import {report} from "@/api/avdbclip";
import {Button} from "@/components/Commons";
import {Fragment} from "preact";
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {useTranslation} from "react-i18next";
import {enumToArray} from "@/functions/string";
import {useSheetFocus} from "@/components/Comments/useSheetFocus";
import {localePath} from "@/functions/sitePath";

type Props = {
    buttonClass: string,
    reportedClass: string,
    type: string,
    id: string | number,
}

export function Main ({buttonClass, reportedClass, type, id} : Props)  {

    const [open, setOpen] = useState<boolean>(false);
    const [count, setCount] = useState<number>(0);
    const [reported, setReported] = useState<boolean>(false);
    // Opened from the comments sheet, the dialog takes the focus from the sheet while it is open (watch-2).
    const {release, reArm} = useSheetFocus();
    const reportsUrl = localePath('/profile/reports');

    const {mutate, isPending} = useErrorMutation({
        mutationFn: (data: ReportData) => report(data),
        mutationKey: ['report', type, id],
        onSuccess: () => {
            setReported(true);
        }
    });

    const { t } = useTranslation();

    const handleSubmit = (event: any) => {

        event.preventDefault();
        const formData = new FormData(event.target);
        const data = Object.fromEntries(formData.entries());

        mutate(ReportDataSchema.parse(data))
    }

    return (
        <>
            {
                !reported &&
                <button type="button" className={buttonClass + ' d-flex gap-2 items-center avd-report-action'} onClick={() => { release(); setOpen(true); }}>
                    <i class="fa-regular fa-flag" aria-hidden="true"></i>
                    <span>{t('Report')}</span>
                </button>
            }
            {
                // A short state in the same place: the row never jumps (it grew to "Reported a few seconds ago").
                reported &&
                <div class={reportedClass + ' avd-report-action'} role="status">
                    <i class="fa-solid fa-flag" aria-hidden="true"></i>
                   <span>{t('Reported')}</span>
                </div>
            }
            <Modal size={'lg'} show={open} onHide={() => setOpen(false)} onExited={reArm} restoreFocus={false} animation={true}>
                <Modal.Header closeButton closeLabel={t('Close')}>
                    <h5 className="modal-title">
                        {t('Report {type}', {type: t(type)})}
                    </h5>
                </Modal.Header>
                <Modal.Body className="ms-3 mt-2">
                    {
                        // An icon tile in the look's colours (watch-16: a white stock picture with an off-brand teal, a
                        // white box in the dark looks).
                        reported &&
                        <div className={'text-center avd-report-done'} role="status">
                            <span className="avd-report-done__icon" aria-hidden="true"><i className="fa-solid fa-check"></i></span>
                            <h3 className="h5 mt-3 mb-2">{t('Thanks for reporting')}</h3>
                            <p className="avd-report-done__text mb-0">
                                {t('If we find this content to be in violation of our Community Guidelines, we will remove it.')}
                            </p>
                        </div>
                    }
                    {
                        !reported &&
                        <form onSubmit={handleSubmit} id="report-form">
                            {
                                enumToArray(ReportReason).map(({value, label}) => (
                                    <div className="form-check mb-3">
                                        <input className="form-check-input" type="radio" name="reason" id={'reason-' + value} value={value} required/>
                                        <label className="form-check-label" htmlFor={'reason-' + value}>
                                            {t(label)}
                                        </label>
                                    </div>
                                ))
                            }
                            <hr/>
                            <div className="mb-3">
                                <label htmlFor="comment" className="form-label">{t('Provide additional details')}</label>
                                <textarea
                                    className="form-control"
                                    id="comment"
                                    rows={3}
                                    name="comment"
                                    onChange={(e: any) => setCount(e.currentTarget.value.length)}
                                    maxLength={5000}
                                ></textarea>
                                <div class="form-text">
                                    {count} / 5000
                                </div>
                            </div>
                            <input type="hidden" name="id" value={id}/>
                            <input type="hidden" name="type" value={'App\\Models\\' + type.charAt(0).toUpperCase() + type.slice(1)}/>
                            <p className="text-muted text-sm mt-3 mb-0">
                                {t('Our team reviews reported videos, comments and channels every day. You can follow your reports in your account.')}
                                {' '}
                                <a target="_blank" rel="noopener" href={reportsUrl}>{t('See my reports')}</a>
                            </p>
                        </form>
                    }
                </Modal.Body>
                <Modal.Footer>
                    {
                        !reported &&
                        <Fragment>
                            <button type="button" className="btn btn-secondary" onClick={() => setOpen(false)}>{t('Cancel')}</button>
                            <Button small={false} form={'report-form'} type="submit" loading={isPending} color={'primary'}>
                                {t('Report')}
                            </Button>
                        </Fragment>
                    }
                    {
                        reported &&
                        <button type="button" className="btn btn-secondary" onClick={() => setOpen(false)}>{t('Close')}</button>
                    }

                </Modal.Footer>
            </Modal>
        </>
    )
}

export function Report(props: Props) {

    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    });

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props}/>
        </QueryClientProvider>
    )
}
