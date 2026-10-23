@use('Illuminate\Support\Facades\Auth')

@php
    $modalStyle = (string) config('site.modal_presentation_style', 'modal_glassmorphic_center');
@endphp
<div class="modal fade avd-modal-archetype" data-modal-style="{{ $modalStyle }}" tabindex="-1" id="premium" aria-hidden="true">
    <div @class([
        'modal-dialog modal-lg',
        'modal-dialog-centered' => $modalStyle === 'modal_glassmorphic_center',
        'modal-fullscreen' => $modalStyle === 'modal_fullscreen_cinema',
        'avd-modal-slide-sheet' => $modalStyle === 'modal_slide_sheet',
        'avd-modal-brutalist' => $modalStyle === 'modal_brutalist_sharp',
    ])>
        <div class="modal-content">
            <div class="modal-body">
                <div class="row">
                    <div class="col-12 col-lg-5">
                        <img class="img-fluid" src="{{asset('images/premium/success.jpg')}}" alt="{{__('Premium welcome')}}">
                    </div>
                    <div class="col-12 col-lg-7 text-center text-lg-start">
                        <h3 class="my-4">{{ __('Welcome to :app_name Premium !', ['app_name' => config('app.name')]) }}</h3>
                        <p class="text-muted">{{ __('Your membership is now active and benefits start immediately') }}</p>
                        <p class="text-muted">{!! __('An email receipt has been sent to <span class="fw-bold">:email<span>', ['email' => Auth::user()->email]) !!}</p>
                        <p class="text-muted">{!! __('Your membership information is available in your <a class="text-decoration-none" href=":account_url">account</a>', ['account_url' => route('user.edit')]) !!}</p>
                        <div class="text-end mt-5">
                            <button type="button" class="btn-link btn text-decoration-none fw-bold" data-bs-dismiss="modal" aria-label="{{__('Close')}}">{{ __('Done') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal('#premium').show()
    })
</script>
