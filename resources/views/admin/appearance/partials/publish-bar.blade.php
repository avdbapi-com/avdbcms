{{--
    The bar under the preview: what is not published yet, Cancel (discard the draft) and Publish. The Publish button
    submits the page form, so a page without JavaScript publishes its fields directly.
--}}
@php
    $count = (int) ($draftState['count'] ?? 0);
    $title = $draftState['action_label'] ?? null
        ? __('Previewing :name', ['name' => $draftState['action_label']])
        : ($count === 0 ? __('No changes') : trans_choice('1 change not published|:count changes not published', $count, ['count' => $count]));
    $action = $draftState['action'] ?? null;
    $detail = match (true) {
        $count === 0 => __('Everything is published.'),
        ($action['type'] ?? null) === 'look' => __('Not published. Changes colours, fonts, homepage, video page and cards. Your logo, texts, menus, ads and custom code stay.'),
        ($action['type'] ?? null) === 'theme' => ! empty($action['layouts'])
            ? __('Not published. Changes colours, fonts, shapes and the page layouts. Your logo, texts, menus and ads stay.')
            : __('Not published. Changes colours, fonts and shapes. Your homepage layout, logo, texts, menus and ads stay.'),
        default => implode(', ', array_slice(array_unique(array_column($draftState['changes'] ?? [], 'label')), 0, 4)),
    };
@endphp
<span class="ad-pub-text" aria-live="polite">
    <b data-ad-pub-title>{{ $title }}</b>
    <span data-ad-pub-detail>{{ $detail !== '' ? $detail : __('Not published. Only you see it, in the preview.') }}</span>
</span>
<button type="button" class="ad-btn" data-ad-discard @if($count === 0) hidden @endif>{{ __('Cancel') }}</button>
<button type="submit" class="ad-btn is-primary" form="ad-appearance-form" data-ad-publish>{{ $publishLabel ?? __('Publish') }}</button>
