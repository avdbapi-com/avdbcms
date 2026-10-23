@extends('layouts.admin')

@section('title', __('Plans'))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <h2>{{__('Plans')}}</h2>
        <a href="{{ route('admin.plans.create') }}" class="btn btn-success d-flex align-items-center gap-1">
            <i class="fa-solid fa-plus"></i>
            <span>{{__('Create')}}</span>
        </a>
    </div>
    <hr>
    <div class="row g-3 mb-4">
        @foreach([
            ['label' => __('Premium'), 'ready' => $billingStatus['premium_enabled'], 'value' => $billingStatus['premium_enabled'] ? __('Enabled') : __('Disabled')],
            ['label' => __('Stripe Checkout'), 'ready' => $billingStatus['stripe_ready'] && $billingStatus['stripe_missing_plans'] === 0, 'value' => $billingStatus['stripe_ready'] ? $billingStatus['stripe_missing_plans'].' '.__('plans missing IDs') : __('Credentials or webhook missing')],
            ['label' => __('PayPal Checkout'), 'ready' => $billingStatus['paypal_ready'] && $billingStatus['paypal_missing_plans'] === 0, 'value' => $billingStatus['paypal_ready'] ? $billingStatus['paypal_missing_plans'].' '.__('plans missing IDs') : __('Credentials or webhook missing')],
        ] as $item)
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="fw-bold">{{ $item['label'] }}</div>
                            <span class="badge bg-{{ $item['ready'] ? 'success' : 'warning' }}">{{ $item['ready'] ? __('Ready') : __('Check') }}</span>
                        </div>
                        <div class="text-muted small mt-2">{{ $item['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-lg-6">
                            <div class="small text-muted">{{ __('Stripe Webhook URL') }}</div>
                            <code class="text-break">{{ $billingStatus['stripe_webhook_url'] }}</code>
                        </div>
                        <div class="col-lg-6">
                            <div class="small text-muted">{{ __('PayPal Webhook URL') }}</div>
                            <code class="text-break">{{ $billingStatus['paypal_webhook_url'] }}</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="table-responsive" tabindex="0">
        <table class="table table-bordered table-striped">
            <thead>
            <tr style="border-top: 3px solid #0D6EFD;">
                <th>{{__('Name')}}</th>
                <th>{{__('Price')}}</th>
                <th>{{__('Duration')}}</th>
                <th>{{__('Stripe ID')}}</th>
                <th>{{__('PayPal Plan ID')}}</th>
                <th style="min-width: 150px">{{__('Created At')}}</th>
                <th style="min-width: 120px">{{__('Actions')}}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($plans as $plan)
                <tr class="bg-light">
                    <td class="align-middle">
                        {{$plan->name}}
                    </td>
                    <td class="align-middle">
                        {{$plan->price}}
                    </td>
                    <td class="align-middle">
                        @if((int) $plan->duration === 1)
                            <span class="badge bg-primary">{{ __('Monthly') }}</span>
                        @elseif((int) $plan->duration === 12)
                            <span class="badge bg-info">{{ __('Yearly') }}</span>
                        @else
                            <span class="badge bg-danger">{{ __('Invalid') }}: {{ $plan->duration }}</span>
                        @endif
                    </td>
                    <td class="align-middle">
                        @if($plan->stripe_id)
                            <code class="small">{{ $plan->stripe_id }}</code>
                        @else
                            <span class="badge bg-warning">{{__('Missing')}}</span>
                        @endif
                    </td>
                    <td class="align-middle">
                        @if($plan->paypal_plan_id)
                            <code class="small">{{ $plan->paypal_plan_id }}</code>
                        @else
                            <span class="badge bg-warning">{{__('Missing')}}</span>
                        @endif
                    </td>
                    <td class="align-middle">
                        {{$plan->created_at->diffForHumans()}}
                    </td>
                    <td class="align-middle">
                        <div class="d-flex gap-1">
                            <a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-sm btn-primary" aria-label="{{ __('Edit :name', ['name' => $plan->name]) }}"><i class="fa-solid fa-edit" aria-hidden="true"></i></a>
                            <form action="{{ route('admin.plans.destroy', $plan) }}" method="POST" onsubmit='return confirm(@json(__('Delete this plan?')))'>
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger" aria-label="{{ __('Delete :name', ['name' => $plan->name]) }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <x-admin.pagination :paginator="$plans" />
@endsection
