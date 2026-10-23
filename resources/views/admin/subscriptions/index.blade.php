@extends('layouts.admin')

@section('title', __('Subscriptions'))

@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-crown me-2"></i>{{__('Subscriptions')}}</h2>
    <div class="d-flex gap-2 text-sm text-muted">
        <span class="badge bg-success fs-6">{{ $subscriptions->total() }} {{__('total')}}</span>
    </div>
</div>
<hr>
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th style="min-width:200px">{{__('User')}}</th>
            <th>{{__('Plan')}}</th>
            <th>{{__('Gateway')}}</th>
            <th>{{__('Status')}}</th>
            <th>{{__('Card')}}</th>
            <th style="min-width:150px">{{__('Next Payment')}}</th>
            <th style="min-width:150px">{{__('Trial Ends')}}</th>
            <th style="min-width:150px">{{__('Ends At')}}</th>
            <th style="min-width:150px">{{__('Created')}}</th>
            <th style="min-width:180px">{{__('Actions')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($subscriptions as $sub)
            <tr class="bg-light">
                <td class="align-middle">
                    @if($sub->user)
                        <a href="{{ route('admin.users.edit', $sub->user) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                            <img src="{{ $sub->user->avatar_url }}" class="rounded" style="width:36px;height:36px;object-fit:cover">
                            <div>
                                <div class="fw-bold">{{ $sub->user->username }}</div>
                                <small class="text-muted">{{ $sub->user->email }}</small>
                            </div>
                        </a>
                    @else
                        <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                    @endif
                </td>
                <td class="align-middle">
                    <span class="badge bg-primary">{{ $sub->plan?->name ?? __('N/A') }}</span>
                </td>
                <td class="align-middle">
                    <span class="badge bg-secondary">{{ Str::headline($sub->payment_provider ?? 'stripe') }}</span>
                </td>
                <td class="align-middle">
                    @php
                        $statusColor = match($sub->stripe_status) {
                            'active' => 'success',
                            'trialing' => 'info',
                            'canceled' => 'danger',
                            'unpaid' => 'warning',
                            default => 'secondary',
                        };
                    @endphp
                    <span class="badge bg-{{ $statusColor }}">{{ __(Str::headline($sub->stripe_status)) }}</span>
                </td>
                <td class="align-middle">
                    @if($sub->card_last4)
                        <span class="text-muted">•••• {{ $sub->card_last4 }}</span>
                        <div><small class="text-muted">{{ $sub->card_expired_at?->format('m/Y') }}</small></div>
                    @else
                        <span class="text-muted">{{__('N/A')}}</span>
                    @endif
                </td>
                <td class="align-middle">
                    <small>{{ $sub->next_payment?->translatedFormat('d M Y') ?? '—' }}</small>
                </td>
                <td class="align-middle">
                    <small>{{ $sub->trial_ends_at?->translatedFormat('d M Y') ?? '—' }}</small>
                </td>
                <td class="align-middle">
                    <small>{{ $sub->ends_at?->translatedFormat('d M Y') ?? '—' }}</small>
                </td>
                <td class="align-middle">
                    <small>{{ $sub->created_at?->diffForHumans() }}</small>
                </td>
                <td class="align-middle">
                    <div class="d-flex gap-1 flex-wrap">
                        @if($sub->stripe_status === 'canceled')
                            <form method="POST" action="{{ route('admin.subscriptions.activate', $sub) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success" title="{{__('Activate')}}">
                                    <i class="fa-solid fa-check me-1"></i>{{__('Activate')}}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.subscriptions.cancel', $sub) }}" onsubmit='return confirm(@json(__('Cancel this subscription?')))'>
                                @csrf
                                <button type="submit" class="btn btn-sm btn-warning" title="{{__('Cancel')}}">
                                    <i class="fa-solid fa-ban me-1"></i>{{__('Cancel')}}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.subscriptions.delete', $sub) }}" onsubmit='return confirm(@json(__('Delete subscription permanently?')))'>
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger" title="{{__('Delete')}}">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center py-4">
                    <i class="fa-solid fa-crown fa-2x mb-3 text-muted"></i>
                    <p class="fw-bold text-muted">{{__('No subscriptions found')}}</p>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$subscriptions" />
@endsection
