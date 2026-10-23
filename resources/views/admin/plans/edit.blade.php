@extends('layouts.admin')

@section('title', __('Edit Plan:') . ' ' . $plan->name)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-edit me-2"></i>{{__('Edit Plan:')}} {{ $plan->name }}</h3>
        <a href="{{ route('admin.plans.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}</a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <form action="{{ route('admin.plans.update', $plan) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-plans-name">{{__('Plan Name')}}</label>
                                <input id="ad-plans-name" type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $plan->name) }}" required>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold">{{__('Price (cents)')}}</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="price" class="form-control" value="{{ old('price', $plan->getAttributes()['price'] ?? 0) }}" required min="0" step="1">
                                </div>
                                <small class="text-muted">{{__('Price in cents')}}</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold" for="ad-plans-duration">{{__('Duration')}}</label>
                                <select id="ad-plans-duration" name="duration" class="form-select" required>
                                    <option value="1" {{ $plan->duration == 1 ? 'selected' : '' }}>{{__('Monthly')}}</option>
                                    <option value="12" {{ $plan->duration == 12 ? 'selected' : '' }}>{{__('Yearly')}}</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-plans-stripe-id">{{__('Stripe Price ID')}}</label>
                                <input id="ad-plans-stripe-id" type="text" name="stripe_id" class="form-control" value="{{ old('stripe_id', $plan->stripe_id) }}" placeholder="price_xxxxx">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-plans-paypal-plan-id">{{__('PayPal Plan ID')}}</label>
                                <input id="ad-plans-paypal-plan-id" type="text" name="paypal_plan_id" class="form-control @error('paypal_plan_id') is-invalid @enderror" value="{{ old('paypal_plan_id', $plan->paypal_plan_id) }}" placeholder="P-XXXXXXXXXXXX">
                                <small class="text-muted">{{__('Create the recurring plan in PayPal and paste its Plan ID here.')}}</small>
                                @error('paypal_plan_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Update Plan')}}</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header"><h6 class="mb-0">{{__('Danger Zone')}}</h6></div>
                <div class="card-body">
                    <form action="{{ route('admin.plans.destroy', $plan) }}" method="POST" onsubmit='return confirm(@json(__('Delete this plan?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Plan')}}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
