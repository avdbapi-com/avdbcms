@extends('layouts.admin')

@section('title', __('Edit Language: :locale', ['locale' => $locale]))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h2 class="mb-0">{{ $language->emoji }} {{ __('Edit') }} — {{ $language->label }} <code class="fs-5">{{ $locale }}</code></h2>
            <small class="text-muted">
                {{ __(':done/:total keys translated', ['done' => count(array_filter($rows, fn($r) => !$r['missing'])), 'total' => count($rows)]) }}
                &nbsp;·&nbsp;
                <span class="text-danger">{{ __(':count missing', ['count' => count(array_filter($rows, fn($r) => $r['missing']))]) }}</span>
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.languages.edit-meta', $language) }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-gear me-1"></i>{{__('Language Settings')}}</a>
            <a href="{{ route('admin.languages.export', $locale) }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-download me-1"></i>{{__('Export JSON')}}</a>
            <a href="{{ route('admin.languages.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i>{{ __('Back') }}
            </a>
        </div>
    </div>
    <hr>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filter / Search --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-6">
                    <input type="text" id="searchFilter" class="form-control form-control-sm" placeholder="{{__('Search keys or translations...')}}">
                </div>
                <div class="col-md-3">
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="all">{{__('Show all keys')}}</option>
                        <option value="missing">{{__('Missing only')}}</option>
                        <option value="filled">{{__('Translated only')}}</option>
                        <option value="json">{{__('JSON keys only')}}</option>
                        <option value="php">{{__('PHP file keys only')}}</option>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <button type="button" class="btn btn-sm btn-outline-warning" id="fillMissingBtn">
                        <i class="fa-solid fa-magic me-1"></i>{{ __('Copy English for missing') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form action="{{ route('admin.languages.update', $locale) }}" method="POST" id="translationsForm">
        @csrf @method('PUT')

        <div class="table-responsive" tabindex="0">
            <table class="table table-bordered table-sm align-middle" id="translationsTable">
                <thead>
                    <tr style="border-top: 3px solid #0D6EFD;">
                        <th style="width: 40px">#</th>
                        <th style="width: 80px">{{__('Source')}}</th>
                        <th style="min-width: 200px">{{ __('Key') }}</th>
                        <th style="min-width: 250px">{{__('English (reference)')}}</th>
                        <th style="min-width: 250px">{{ __(':label translation', ['label' => $language->label]) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $key => $row)
                        <tr class="translation-row {{ $row['missing'] ? 'table-warning' : '' }}"
                            data-missing="{{ $row['missing'] ? '1' : '0' }}"
                            data-source="{{ $row['source'] ?? 'json' }}">
                            <td class="text-muted small">{{ $loop->index + 1 }}</td>
                            <td class="text-center">
                                @if(($row['source'] ?? 'json') === 'php')
                                    <span class="badge bg-secondary" title="{{__('PHP translation file')}}">PHP</span>
                                @else
                                    <span class="badge bg-primary" title="{{__('JSON translation file')}}">JSON</span>
                                @endif
                            </td>
                            <td>
                                <code class="small text-break">{{ $key }}</code>
                            </td>
                            <td class="small text-muted" data-english="{{ htmlspecialchars($row['english']) }}">
                                {{ $row['english'] }}
                            </td>
                            <td>
                                @if(str_contains($row['english'], "\n") || strlen($row['english']) > 100)
                                    <textarea
                                        name="translations[{{ $key }}]"
                                        class="form-control form-control-sm translation-input"
                                        rows="2"
                                        data-english="{{ htmlspecialchars($row['english']) }}"
                                    >{{ $row['translation'] }}</textarea>
                                @else
                                    <input
                                        type="text"
                                        name="translations[{{ $key }}]"
                                        class="form-control form-control-sm translation-input {{ $row['missing'] ? 'border-warning' : '' }}"
                                        value="{{ $row['translation'] }}"
                                        placeholder="{{ $row['english'] }}"
                                        data-english="{{ htmlspecialchars($row['english']) }}"
                                    >
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
            <a href="{{ route('admin.languages.index') }}" class="btn btn-secondary">{{__('Cancel')}}</a>
            <button type="submit" class="btn btn-success btn-lg px-5">
                <i class="fa-solid fa-save me-2"></i>{{ __('Save Translations') }}
            </button>
        </div>
    </form>

    {{-- Add New Translation Key --}}
    <div class="card border-primary mt-4 mb-5">
        <div class="card-header fw-bold text-primary">
            <i class="fa-solid fa-plus-circle me-1"></i> {{ __('Add New Translation Key') }}
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                {{ __("Add a new key that doesn't exist yet. After adding, it will appear in the table above for all languages.") }}
                {!! __('Keys should be the <strong>English text</strong> exactly as used in the app code.') !!}
                {{ __('Examples: Sign In, Upload video.') }}
            </p>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">{{__('Key (English reference)')}}</label>
                    <input type="text" id="newKeyInput" class="form-control"
                           placeholder="{{ __('e.g. Watch Later') }}">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">{{ __('Translation for :label', ['label' => $language->label]) }}</label>
                    <input type="text" id="newValInput" class="form-control"
                           placeholder="{{ __('Translation in :label', ['label' => $language->label]) }}">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-primary w-100" id="addKeyBtn">
                        <i class="fa-solid fa-plus me-1"></i> {{ __('Add & Save') }}
                    </button>
                </div>
            </div>
            <div id="addKeyAlert" class="alert mt-2 d-none"></div>
        </div>
    </div>


    <script>
    (function () {
        // Search/filter
        const searchInput  = document.getElementById('searchFilter');
        const statusSelect = document.getElementById('statusFilter');
        const rows         = document.querySelectorAll('.translation-row');

        function filterRows() {
            const q      = searchInput.value.toLowerCase();
            const status = statusSelect.value;
            rows.forEach(row => {
                const key     = row.querySelector('code')?.textContent.toLowerCase() ?? '';
                const english = row.querySelector('td:nth-child(4)')?.textContent.toLowerCase() ?? '';
                const trans   = row.querySelector('input,textarea')?.value.toLowerCase() ?? '';
                const missing = row.dataset.missing === '1';
                const source  = row.dataset.source ?? 'json';

                const matchText   = !q || key.includes(q) || english.includes(q) || trans.includes(q);
                const matchStatus = status === 'all'
                    || (status === 'missing' && missing)
                    || (status === 'filled'  && !missing)
                    || (status === 'json'    && source === 'json')
                    || (status === 'php'     && source === 'php');
                row.style.display = matchText && matchStatus ? '' : 'none';
            });
        }

        searchInput.addEventListener('input', filterRows);
        statusSelect.addEventListener('change', filterRows);

        // Fill missing with English
        document.getElementById('fillMissingBtn').addEventListener('click', function () {
            rows.forEach(row => {
                if (row.dataset.missing !== '1') return;
                const input = row.querySelector('input.translation-input, textarea.translation-input');
                if (input && !input.value) {
                    input.value = input.dataset.english ?? '';
                    row.classList.remove('table-warning');
                    row.dataset.missing = '0';
                    if (input.classList.contains('border-warning')) input.classList.remove('border-warning');
                }
            });
        });

        // Add new key — inject into the main form and submit
        document.getElementById('addKeyBtn').addEventListener('click', function () {
            const keyEl   = document.getElementById('newKeyInput');
            const valEl   = document.getElementById('newValInput');
            const alert   = document.getElementById('addKeyAlert');
            const key     = keyEl.value.trim();
            const val     = valEl.value.trim();

            alert.className = 'alert mt-2';

            if (!key) {
                alert.className += ' alert-danger';
                alert.textContent = @json(__('Key is required.'));
                return;
            }

            // Check if key already exists in the form
            const existingInput = document.querySelector(`[name="translations[${key}]"]`);
            if (existingInput) {
                alert.className += ' alert-warning';
                alert.textContent = @json(__('This key already exists in the table above. Update it there.'));
                return;
            }

            // Add hidden input to main form and submit
            const form = document.getElementById('translationsForm');
            const hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = `translations[${key}]`;
            hidden.value = val;
            form.appendChild(hidden);

            alert.className += ' alert-success';
            alert.textContent = @json(__('Adding key and saving all translations...'));

            setTimeout(() => form.submit(), 300);
        });
    })();
    </script>
@endsection
