import {useTranslation} from "react-i18next";
import {CommentType, Paginator} from "@/types";
import {InfiniteData, useQueryClient} from "@tanstack/react-query";
import {useErrorMutation} from "@/hooks/useErrorMutation";
import {deleteComment} from "@/api/avdbclip";
import {Modal, Button} from 'react-bootstrap';
import {useState} from "preact/hooks";
import {produce} from "immer";
import {useSheetFocus} from "@/components/Comments/useSheetFocus";

type Props = {
    comment: CommentType
}

export function DeleteComment ({comment} : Props) {

    const { t } = useTranslation()

    const queryClient = useQueryClient();

    const [confirm, setConfirm] = useState<boolean>(false);
    const {release, reArm} = useSheetFocus();

    const {mutateAsync, isPending} = useErrorMutation({
        mutationFn: () => deleteComment(comment.video_uuid, comment.id),
        mutationKey: ['comments.delete', comment.id],
        onSuccess: () => {

            const queryKey = comment.is_reply ? ['comments', comment.parent_id, 'replies'] : ['comments', comment.video_uuid];

            queryClient.setQueriesData({queryKey: queryKey}, (oldData: InfiniteData<Paginator<CommentType>> | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data = page.data.filter(c => c.id !== comment.id);
                        page.count = page.count! - (comment.is_reply ? 1 : comment.replies_count! + 1);
                    });
                });
            })

            if (comment.is_reply) {
                queryClient.setQueriesData({queryKey: ['comments', comment.video_uuid]}, (oldData: InfiniteData<Paginator<CommentType>> | undefined) => {
                    if(!oldData) return undefined;
                    return produce(oldData, draft => {
                        draft.pages[0].count = draft.pages[0].count! - 1;
                        draft.pages.forEach(page => {
                            page.data.forEach(c => {
                                if (c.id === comment.parent_id) {
                                    c.has_replies = c.replies_count! > 1;
                                    c.replies_count = c.replies_count! - 1;
                                }
                            });
                        });
                    });
                })
            }
        }
    })

    const handleDelete = async () => {
        await mutateAsync()
        setConfirm(false)
    }

    return (
        <>
            <li>
                <button type={'button'} className="dropdown-item d-flex align-items-center gap-3" onClick={() => { release(); setConfirm(true); }}>
                    <i className="fa-solid fa-trash-can" aria-hidden="true"></i>
                    {t('Delete')}
                </button>
            </li>
            <Modal show={confirm} onHide={() => setConfirm(false)} onExited={reArm} restoreFocus={false} centered>
                <Modal.Header closeButton closeLabel={t('Close')}>
                    <h5 className="modal-title">{t('Delete this comment?')}</h5>
                </Modal.Header>
                <Modal.Body>
                    <p className="mb-0">
                        {
                            comment.user.id === window.USER!.id
                                ? t('Your comment and its replies are deleted for good.')
                                : t('The comment of {username} and its replies are deleted for good.', { username: comment.user.username })
                        }
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setConfirm(false)}>
                        {t('Cancel')}
                    </Button>
                    <Button variant="danger" onClick={handleDelete} disabled={isPending} aria-busy={isPending}>
                        {isPending && <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>}
                        <i className="fa-solid fa-trash-can me-2" aria-hidden="true"></i>
                        {t('Delete comment')}
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    )
}
