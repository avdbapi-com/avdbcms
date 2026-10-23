<div class="modal fade" tabindex="-1" id="cancel_report" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{__('Cancel all reports ?')}}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 p-2 bg-body-secondary rounded">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <img style="width: 120px;height: 68px" :src="reportable.poster || '{{asset('images/placeholders/no-image.svg')}}'" :alt="reportable.title ? `${reportable.title} poster` : '{{__('Poster')}}'">
                        <div>
                            <small  x-text="reportable.title"></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{__('Cancel')}}</button>
                <form method="POST" :action="reportable.route">
                    @csrf
                    <button type="submit" class="btn btn-secondary">
                        <i class="fa-solid fa-xmark"></i> &nbsp;
                        {{__('Cancel all reports')}}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
