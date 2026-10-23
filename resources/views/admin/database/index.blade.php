@extends('layouts.admin')
@section('title', __('Database Management'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-database me-2"></i>{{__('Database Management')}}</h2>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.database.optimize') }}" onsubmit='return confirm(@json(__('Optimize all tables? This may take a moment.')))'>
            @csrf
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-bolt me-1"></i>{{__('Optimize All Tables')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.database.migrate') }}" onsubmit='return confirm(@json(__('Run database migrations?')))'>
            @csrf
            <button type="submit" class="btn btn-warning btn-sm"><i class="fa-solid fa-arrows-rotate me-1"></i>{{__('Run Migrations')}}</button>
        </form>
    </div>
</div>
<hr>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-primary"><div class="card-body text-center">
            <i class="fa-solid fa-database fa-2x text-primary mb-2"></i>
            <h3>{{ count($tables) }}</h3>
            <small class="text-muted">{{__('Total Tables')}}</small>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-success"><div class="card-body text-center">
            <i class="fa-solid fa-weight-hanging fa-2x text-success mb-2"></i>
            <h3>{{ number_format($totalSize, 2) }} MB</h3>
            <small class="text-muted">{{__('Total Database Size')}}</small>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-info"><div class="card-body text-center">
            <i class="fa-solid fa-server fa-2x text-info mb-2"></i>
            <h3>{{ $dbName }}</h3>
            <small class="text-muted">{{__('Database Name')}}</small>
        </div></div>
    </div>
</div>

{{-- Safe Truncate Quick Actions --}}
<div class="card shadow mb-4">
    <div class="card-header bg-warning text-dark">
        <h6 class="mb-0"><i class="fa-solid fa-eraser me-1"></i>{{__('Quick Truncate (Safe Tables Only)')}}</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{__('These actions will empty the selected table completely and cannot be undone.')}}</p>
        <div class="d-flex gap-2 flex-wrap">
            @foreach(['activities', 'views', 'searches', 'notifications', 'failed_jobs', 'password_reset_tokens'] as $t)
            <form method="POST" action="{{ route('admin.database.truncate', $t) }}" onsubmit='return confirm(@json(__('Truncate table `:table`? This cannot be undone.', ['table' => $t])))'>
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fa-solid fa-eraser me-1"></i>{{ str_replace('_', ' ', ucfirst($t)) }}
                </button>
            </form>
            @endforeach
        </div>
    </div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #0D6EFD">
            <th>{{__('Table Name')}}</th>
            <th class="text-end">{{__('Rows (est.)')}}</th>
            <th class="text-end">{{__('Data (MB)')}}</th>
            <th class="text-end">{{__('Index (MB)')}}</th>
            <th class="text-end">{{__('Total (MB)')}}</th>
            <th>{{__('Engine')}}</th>
            <th>{{__('Collation')}}</th>
        </tr></thead>
        <tbody>
        @foreach($tables as $table)
        <tr>
            <td class="align-middle font-monospace fw-bold">{{ $table->table_name ?? 'unknown' }}</td>
            <td class="align-middle text-end">{{ number_format((int)($table->table_rows ?? 0)) }}</td>
            <td class="align-middle text-end">{{ number_format((float)($table->data_mb ?? 0), 2) }}</td>
            <td class="align-middle text-end">{{ number_format((float)($table->index_mb ?? 0), 2) }}</td>
            <td class="align-middle text-end">
                <span class="badge {{ (float)($table->total_mb ?? 0) > 10 ? 'bg-warning' : 'bg-success' }}">
                    {{ number_format((float)($table->total_mb ?? 0), 2) }} MB
                </span>
            </td>
            <td class="align-middle"><small>{{ $table->engine ?? '' }}</small></td>
            <td class="align-middle"><small class="text-muted">{{ $table->table_collation ?? '' }}</small></td>
        </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="table-dark fw-bold">
            <td>TOTAL</td>
            <td class="text-end">{{ number_format((int)collect($tables)->sum('table_rows')) }}</td>
            <td class="text-end">{{ number_format((float)collect($tables)->sum('data_mb'), 2) }}</td>
            <td class="text-end">{{ number_format((float)collect($tables)->sum('index_mb'), 2) }}</td>
            <td class="text-end">{{ number_format((float)($totalSize ?? 0), 2) }} MB</td>
            <td colspan="2"></td>
        </tr>
        </tfoot>
    </table>
</div>
@endsection
