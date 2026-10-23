@extends('layouts.admin')

@section('title', __('Languages'))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h2 class="mb-0">🌐 {{ __('Languages') }}</h2>
            <small class="text-muted">{{ __(':count languages — :keys translation keys in reference', ['count' => $stats->count(), 'keys' => $referenceCount]) }}</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary d-flex align-items-center gap-2"
                    type="button" data-bs-toggle="modal" data-bs-target="#importModal"
                    aria-controls="importModal" aria-expanded="false">
                <i class="fa-solid fa-file-import" aria-hidden="true"></i> {{ __('Import JSON') }}
            </button>
            <a href="{{ route('admin.languages.create') }}"
               class="btn btn-success d-flex align-items-center gap-2">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ __('Add Language') }}
            </a>
        </div>
    </div>
    <hr>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
        </div>
    @endif

    <div class="table-responsive" tabindex="0">
        <table class="table table-bordered table-hover align-middle">
            <thead>
                <tr style="border-top: 3px solid #0D6EFD;">
                    <th style="width: 60px">{{__('Flag')}}</th>
                    <th>{{__('Language')}}</th>
                    <th style="width: 80px">{{__('Code')}}</th>
                    <th style="width: 120px">{{__('Status')}}</th>
                    <th style="width: 150px">{{__('File')}}</th>
                    <th style="min-width: 200px">{{__('Coverage')}}</th>
                    <th style="width: 260px">{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stats as $stat)
                    @php $lang = $stat['language']; @endphp
                    <tr class="{{ !$stat['isActive'] ? 'table-secondary opacity-75' : '' }}">
                        <td class="text-center fs-4">
                            <span role="img" aria-label="{{ $stat['label'] }}">{{ $stat['emoji'] }}</span>
                        </td>
                        <td>
                            <span class="fw-semibold">{{ $stat['label'] }}</span>
                            @if($stat['isDefault'])
                                <span class="badge bg-primary ms-1"><i class="fa-solid fa-star me-1" aria-hidden="true"></i>{{__('Default')}}</span>
                            @endif
                            @if(!$stat['isActive'])
                                <span class="badge bg-secondary ms-1">{{__('Inactive')}}</span>
                            @endif
                        </td>
                        <td>
                            <code class="badge bg-secondary fs-6">{{ $stat['locale'] }}</code>
                        </td>
                        <td>
                            @if($stat['isActive'])
                                <span class="badge bg-success">{{__('Active')}}</span>
                            @else
                                <span class="badge bg-secondary">{{__('Inactive')}}</span>
                            @endif
                        </td>
                        <td>
                            @if($stat['hasFile'])
                                <span class="badge bg-success">
                                    <i class="fa-solid fa-check me-1" aria-hidden="true"></i>{{ $stat['locale'] }}.json
                                </span>
                            @elseif($stat['isDefault'])
                                <span class="badge bg-info">{{__('Built-in')}}</span>
                            @else
                                <span class="badge bg-warning text-dark">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>{{ __('Missing') }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 8px">
                                    <div class="progress-bar
                                        @if($stat['percent'] >= 90) bg-success
                                        @elseif($stat['percent'] >= 50) bg-warning
                                        @else bg-danger @endif"
                                         role="progressbar"
                                         aria-label="{{ __(':label translation coverage', ['label' => $stat['label']]) }}"
                                         aria-valuenow="{{ max(0, min(100, (int) $stat['percent'])) }}"
                                         aria-valuemin="0"
                                         aria-valuemax="100"
                                         aria-valuetext="{{ $stat['percent'] }}%"
                                         style="width: {{ $stat['percent'] }}%"></div>
                                </div>
                                <small class="text-nowrap text-muted">
                                    {{ $stat['filled'] }}/{{ $stat['total'] }}
                                    @if($stat['missing'] > 0)
                                        <span class="text-danger">{{ __('(:count missing)', ['count' => $stat['missing']]) }}</span>
                                    @endif
                                </small>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                {{-- Edit translations --}}
                                <a href="{{ route('admin.languages.edit', $stat['locale']) }}"
                                   class="btn btn-sm btn-primary"
                                   title="{{__('Edit translations')}}">
                                    <i class="fa-solid fa-language" aria-hidden="true"></i> {{ __('Translations') }}
                                </a>

                                {{-- Edit metadata --}}
                                <a href="{{ route('admin.languages.edit-meta', $lang) }}"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="{{__('Edit language settings')}}"
                                   aria-label="{{__('Edit language settings')}}">
                                    <i class="fa-solid fa-gear" aria-hidden="true"></i>
                                </a>

                                {{-- Export --}}
                                @if($stat['hasFile'] || $stat['isDefault'])
                                <a href="{{ route('admin.languages.export', $stat['locale']) }}"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="{{__('Export JSON')}}"
                                   aria-label="{{__('Export JSON')}}">
                                    <i class="fa-solid fa-download" aria-hidden="true"></i>
                                </a>
                                @endif

                                {{-- Set as default --}}
                                @if(!$stat['isDefault'])
                                <form method="POST"
                                      action="{{ route('admin.languages.set-default', $lang) }}"
                                      onsubmit='return confirm({!! json_encode(__('Set :label as default language? This will change the bare URL (no prefix) to this language.', ['label' => $stat['label']])) !!})'>
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-warning"
                                            title="{{__('Set as default')}}"
                                            aria-label="{{__('Set as default')}}">
                                        <i class="fa-solid fa-star" aria-hidden="true"></i>
                                    </button>
                                </form>
                                @endif

                                {{-- Toggle active --}}
                                @if(!$stat['isDefault'])
                                <form method="POST"
                                      action="{{ route('admin.languages.toggle-active', $lang) }}"
                                      onsubmit='return confirm({!! json_encode(__(':action :label?', ['action' => $stat['isActive'] ? __('Deactivate') : __('Activate'), 'label' => $stat['label']])) !!})'>
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm {{ $stat['isActive'] ? 'btn-outline-secondary' : 'btn-outline-success' }}"
                                            title="{{ $stat['isActive'] ? __('Deactivate') : __('Activate') }}"
                                            aria-label="{{ $stat['isActive'] ? __('Deactivate') : __('Activate') }}">
                                        <i class="fa-solid fa-toggle-{{ $stat['isActive'] ? 'on' : 'off' }}" aria-hidden="true"></i>
                                    </button>
                                </form>

                                {{-- Delete --}}
                                <form method="POST"
                                      action="{{ route('admin.languages.destroy', $lang) }}"
                                      onsubmit='return confirm({!! json_encode(__('Delete :label? Translation JSON files will be kept.', ['label' => $stat['label']])) !!})'>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="{{__('Delete')}}"
                                            aria-label="{{__('Delete')}}">
                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Import Modal --}}
    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-modal="true"
         aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.languages.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="importModalLabel"><i class="fa-solid fa-file-import me-2" aria-hidden="true"></i>{{__('Import Translations')}}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="import-locale" class="form-label fw-bold">{{__('Language')}}</label>
                            <select id="import-locale" name="locale" class="form-select" required>
                                <option value="">{{__('Select language...')}}</option>
                                @foreach(config('languages') as $code => $info)
                                    <option value="{{ $code }}">{{ $info['emoji'] }} {{ $info['label'] }} ({{ $code }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="import-json-file" class="form-label fw-bold">{{__('JSON File')}}</label>
                            <input id="import-json-file" type="file" name="file" class="form-control" accept=".json" required>
                            <div class="form-text">{{__('Upload a flat key-value JSON translation file.')}}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{__('Cancel')}}</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-upload me-1" aria-hidden="true"></i>{{__('Import')}}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
