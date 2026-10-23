{{--
    Settings > Redirects in the redesigned admin (the old admin keeps redirects/index.blade.php): "Add a redirect", the
    rules with their kind and use count, a row menu filled from one template (modules/list-rows.ts: turn on / off with
    Undo, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Redirects'))

@section('content')
    @php ob_start(); @endphp
    @php
        $codes = ['301' => __('Moved for good (301)'), '302' => __('Moved for now (302)'), '307' => __('Moved for now, same method (307)')];
    @endphp

    <x-admin.page-header page="settings.redirects"
        :description="trans_choice('1 rule. Visitors who open an old address are sent to the new one.|:count rules. Visitors who open an old address are sent to the new one.', $redirects->total(), ['count' => number_format($redirects->total())])" />

    <section class="ad-panel ad-console-block" aria-labelledby="ad-redirects-add">
        <div class="ad-panel-h"><h2 id="ad-redirects-add">{{ __('Add a redirect') }}</h2></div>
        <form method="POST" action="{{ route('admin.redirects.store') }}" class="ad-panel-b ad-record-fields is-3">
            @csrf
            <x-admin.field name="source" :label="__('Old address')" placeholder="/old-page" :help="__('A path of your site, e.g. /old-page.')" />
            <x-admin.field name="destination" :label="__('New address')" placeholder="/new-page" :help="__('A path or a full address.')" />
            <x-admin.field name="status_code" :label="__('Kind')">
                <select class="ad-select" id="status_code" name="status_code">
                    @foreach ($codes as $value => $label)
                        <option value="{{ $value }}" @selected(old('status_code', '301') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <input type="hidden" name="is_active" value="1">
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('plus') }}{{ __('Add redirect') }}</button></div>
        </form>
    </section>

    <form id="ad-redirect-toggle" method="POST" action="{{ route('admin.redirects.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-redirect-delete" method="POST" action="{{ route('admin.redirects.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Redirect deleted') }}"
          data-ad-confirm="{{ __('Delete this redirect?') }}" data-ad-confirm-body="{{ __('The old address shows "page not found" again. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete redirect') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-redirect-menu">
        <button type="submit" form="ad-redirect-toggle" formaction="{{ route('admin.redirects.toggle', ['id' => '__ID__'], false) }}" data-ad-when="on" data-ad-expect="off" data-ad-done="{{ __('Redirect turned off') }}" data-ad-undo-url="{{ route('admin.redirects.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('pause') }}{{ __('Turn off') }}</button>
        <button type="submit" form="ad-redirect-toggle" formaction="{{ route('admin.redirects.toggle', ['id' => '__ID__'], false) }}" data-ad-when="off" data-ad-expect="on" data-ad-done="{{ __('Redirect turned on') }}" data-ad-undo-url="{{ route('admin.redirects.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('play') }}{{ __('Turn on') }}</button>
        <hr>
        <button type="submit" form="ad-redirect-delete" formaction="{{ route('admin.redirects.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete redirect...') }}</button>
    </template>

    <x-admin.table :paginator="$redirects" :label="__('Redirects')" list="redirects" table-class="is-list" row-menu="ad-redirect-menu">
        <x-slot:head>
            <th scope="col">{{ __('From') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Kind') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Times used') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="arrow-right" :title="__('No redirects yet')">{{ __('Add one above when a page moves to a new address.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($redirects as $redirect)
            <tr>
                <td class="c-main"><p><b id="ad-rd-{{ $redirect->id }}"><code>{{ $redirect->source }}</code></b><small>{{ AdminTheme::icon('arrow-right', 'sm flip-rtl') }} <code>{{ $redirect->destination }}</code></small></p></td>
                <td class="c-meta"><x-admin.status :type="$redirect->is_active ? 'ok' : 'off'">{{ $redirect->is_active ? __('On') : __('Off') }}</x-admin.status></td>
                <td class="hide-tab">{{ $redirect->status_code }}</td>
                <td class="is-num hide-tab">{{ number_format((int) $redirect->hits) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-rd-{{ $redirect->id }}" data-id="{{ $redirect->id }}" data-state="{{ $redirect->is_active ? 'on' : 'off' }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
