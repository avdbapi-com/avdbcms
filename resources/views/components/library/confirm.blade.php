{{-- A confirmation of the library and channel pages (member design 5.11, 6.15 sheets-gallery): a Bootstrap dialog in
     the kit's markup, so it follows the look's "Pop-up window style" like every pop-up of the site (a side sheet, a
     centred dialog, a bottom sheet on phones). The question is the title; the body says what goes with it; the actions
     are the gentle choice when there is one ($gentle: a quiet button, first, a full-width row above the two decisions
     where the row is short), Cancel and the action named with its icon. The final action is the only filled danger
     button of the page.
     <x-library.confirm id="mkClearAll" :title="__('Clear all history?')" :confirm="__('Clear history')" icon="trash-can"
                        confirm-attr="data-history-confirm-all">{{ __('...') }}
         <x-slot:gentle><button type="button" class="mk-btn mk-btn--quiet">...</button></x-slot:gentle>
     </x-library.confirm> --}}
@props(['id', 'title', 'confirm', 'icon' => 'trash-can', 'confirmAttr' => null, 'danger' => true, 'form' => null, 'gentle' => null])
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Title" aria-hidden="true" {{ $attributes }}>
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm avd-mk">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="{{ $id }}Title">{{ $title }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">{{ $slot }}</div>
            <div class="mk-sheet__foot">
                @if($gentle !== null && trim((string) $gentle) !== '')
                    {{ $gentle }}
                @endif
                <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                @if($form)
                    <form method="POST" action="{{ $form }}" style="display: contents">
                        @csrf
                        <button type="submit" @class(['mk-btn', 'mk-btn--danger' => $danger, 'mk-btn--primary' => !$danger])><i class="fa-solid fa-{{ $icon }}" aria-hidden="true"></i><span>{{ $confirm }}</span></button>
                    </form>
                @else
                    <button type="button" @class(['mk-btn', 'mk-btn--danger' => $danger, 'mk-btn--primary' => !$danger]) @if($confirmAttr) {{ $confirmAttr }} @endif><i class="fa-solid fa-{{ $icon }}" aria-hidden="true"></i><span>{{ $confirm }}</span></button>
                @endif
            </div>
        </div>
    </div>
</div>
