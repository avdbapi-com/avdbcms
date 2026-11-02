import {useTranslation} from "react-i18next";
import {CommentType} from "@/types";
import {Modal, Button} from 'react-bootstrap';
import {useState} from "preact/hooks";
import {Report} from "@/components/Actions";
import {useSheetFocus} from "@/components/Comments/useSheetFocus";
import {signInUrl as commentsSignInUrl} from "@/components/Comments/signInUrl";


type Props = {
    comment: CommentType
}

export function ReportComment ({comment} : Props) {

    const { t } = useTranslation()

    const [open, setOpen] = useState<boolean>(false);
    const {release, reArm} = useSheetFocus();
    // auth-8, auth-18: back to this page after signing in, in the page's language (R3-018: the comments' one sign-in
    // address, with the site's folder and /index.php where its links carry them).
    const signInUrl = commentsSignInUrl();

    return (
        <li>
            {
                window.USER ?
                    comment.can_report ?
                        <Report
                            buttonClass="dropdown-item d-flex align-items-center gap-3"
                            reportedClass={'dropdown-item d-flex align-items-center gap-2 mb-0 text-sm py-2'}
                            type={'comment'}
                            id={comment.id}
                        />
                        :
                        comment.reported_at &&
                        <div className="dropdown-item d-flex align-items-center gap-2 mb-0 text-sm py-2">
                            <i className="fa-solid fa-flag"></i>
                            <span>{t('Reported')} {comment.reported_at}</span>
                        </div>
                    :
                    <>
                        <Modal centered show={open} onHide={() => setOpen(false)} onExited={reArm} restoreFocus={false}>
                            <Modal.Header closeButton closeLabel={t('Close')}>
                                <h5 className="modal-title">{t('Report this comment?')}</h5>
                            </Modal.Header>
                            <Modal.Body>
                                <p className="mb-0">
                                    {t('Sign in to report inappropriate content.')}
                                </p>
                            </Modal.Body>
                            <Modal.Footer>
                                <Button variant="secondary" onClick={() => setOpen(false)}>
                                    {t('Cancel')}
                                </Button>
                                <Button variant="primary" as={'a'} href={signInUrl}>
                                    {t('Sign in')}
                                </Button>
                            </Modal.Footer>
                        </Modal>
                        <button type="button" className="dropdown-item d-flex align-items-center gap-3" onClick={() => { release(); setOpen(true); }}>
                            <i className="fa-solid fa-flag" aria-hidden="true"></i>
                            {t('Report')}
                        </button>
                    </>
            }
        </li>
    )
}
