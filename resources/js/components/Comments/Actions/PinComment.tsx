import {useTranslation} from "react-i18next";
import {CommentType} from "@/types";
import {useQueryClient} from "@tanstack/react-query";
import {useErrorMutation} from "@/hooks/useErrorMutation";
import {pinComment} from "@/api/avdbclip";
import {Button, Modal} from "react-bootstrap";
import {useState} from "preact/hooks";
import {useSheetFocus} from "@/components/Comments/useSheetFocus";

type Props = {
    comment: CommentType
}

export function PinComment ({comment} : Props) {

    const { t } = useTranslation()

    const queryClient = useQueryClient();

    const [confirm, setConfirm] = useState<boolean>(false);
    const {release, reArm} = useSheetFocus();

    const {mutateAsync, mutate, isPending} = useErrorMutation({
        mutationFn: (type: 'pin' | 'unpin') => pinComment(type, comment.video_uuid, comment.id),
        mutationKey: ['comments.pin', comment.id],
        onSuccess: () => {
            queryClient.resetQueries({queryKey: ['comments', comment.video_uuid]})
        }
    });

    const type = comment.is_pinned ? 'unpin' : 'pin';

    const handlePin = async () => {
        await mutateAsync(type)
        setConfirm(false)
    }

    return (
        <>
            <li>
                <button type="button" onClick={() => { if (comment.is_pinned) { mutate('unpin'); } else { release(); setConfirm(true); } }} className="dropdown-item d-flex align-items-center gap-3">
                    <i className="fa-solid fa-thumbtack" aria-hidden="true"></i>
                    {comment.is_pinned ? t('Unpin') : t('Pin')}
                </button>
            </li>
            <Modal show={confirm} onHide={() => setConfirm(false)} onExited={reArm} restoreFocus={false} centered>
                <Modal.Header closeButton closeLabel={t('Close')}>
                    <h5 className="modal-title">{t('Pin this comment?')}</h5>
                </Modal.Header>
                <Modal.Body>
                    <p className="mb-0">
                     {t('If you already pinned a comment, this will replace it.')}
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setConfirm(false)}>
                        {t('Cancel')}
                    </Button>
                    <Button variant="primary" onClick={handlePin} disabled={isPending}>
                        {isPending && <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>}
                        {t('Pin')}
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    )
}
