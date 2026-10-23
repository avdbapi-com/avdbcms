import {HistoryType, VideoType} from "@/types";
import {useErrorMutation} from "@/hooks";
import {clearHistoryDay} from "@/api/avdbclip";
import {useQueryClient} from "@tanstack/react-query";
import {produce} from "immer";
import HistoryItem from "@/components/History/HistoryItem";
import {Button, Modal} from "react-bootstrap";
import {useState} from "preact/hooks";
import {useTranslation} from "react-i18next";
import {formatCalendarDate, sameCalendarDay} from "@/utils/format";

type Props = {
    item: {
        date: Date;
        views: {
            id: number;
            video: VideoType;
        }[];
    }
}

export default function HistoryDate ({item} : Props) {

    const queryClient = useQueryClient();

    const [confirm, setConfirm] = useState<boolean>(false);

    const { t } = useTranslation();

    const {
        mutateAsync,
        isPending
    } = useErrorMutation({
        mutationFn: () => clearHistoryDay(item.date),
        mutationKey: ['history.clearDay'],
        onSuccess: () => {
            queryClient.setQueryData(['history'], (oldData: HistoryType | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.data = draft.data.filter(i => !sameCalendarDay(i.date, item.date));
                });
            });
        }
    });

    const calendarDate = formatCalendarDate(item.date, {
        today: t('Today'),
        yesterday: t('Yesterday'),
    });

    const handleDelete = async () => {
        await mutateAsync();
        setConfirm(false);
    }

    return (
        <>
            <div className={'mb-5'}>
                <div className={'d-flex justify-content-between align-items-center bg-secondary-subtle p-2'}>
                    <h5 className={'mb-0 text-secondary-emphasis'}>{calendarDate}</h5>
                    <button type={'button'} className="btn btn-danger btn-sm" onClick={() => setConfirm(true)} >
                        <i className="fa-solid fa-trash"></i>
                    </button>
                </div>
                <hr/>
                <div className={'row mx-0 gx-3 gy-3 gy-sm-4 row-cols-1 row-cols-sm-2 row-cols-md-2 row-cols-lg-2 row-cols-xl-3 row-cols-xxl-3'}>
                    {item.views.map((view) => <HistoryItem key={view.id} view={view}/>)}
                </div>
            </div>
            <Modal show={confirm} onHide={() => setConfirm(false)}>
                <Modal.Header closeButton>
                    <h5 className="modal-title">
                        {t('Clear {date} history', {date: calendarDate})}
                    </h5>
                </Modal.Header>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setConfirm(false)}>
                        {t('Close')}
                    </Button>
                    <Button variant="danger" onClick={handleDelete} disabled={isPending}>
                        {isPending && <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>}
                        {t('Delete')}
                    </Button>
                </Modal.Footer>
            </Modal>
        </>

    )
}
