@extends('layouts.admin')

@section('title', __('Create Plan'))

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-plus-circle me-2"></i>{{__('Create Plan')}}</h3>
        <a href="{{ route('admin.plans.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}</a>
    </div>

    <div class="card shadow">
        <div class="card-body">
            <form action="{{ route('admin.plans.store') }}" method="POST">
                @csrf
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold" for="ad-plans-name">{{__('Plan Name')}}</label>
                        <input id="ad-plans-name" type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required placeholder="{{__('e.g. Monthly Premium')}}">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">{{__('Price (cents)')}}</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="price" class="form-control @error('price') is-invalid @enderror" value="{{ old('price') }}" required min="0" step="1">
                        </div>
                        <small class="text-muted">{{__('Price in cents (e.g. 999 = $9.99)')}}</small>
                        @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold" for="ad-plans-duration">{{__('Duration')}}</label>
                        <select id="ad-plans-duration" name="duration" class="form-select" required>
                            <option value="1">{{__('Monthly')}}</option>
                            <option value="12">{{__('Yearly')}}</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">{{__('Stripe Price ID')}}<small class="text-muted">{{__('(optional)')}}</small></label>
                        <input type="text" name="stripe_id" class="form-control" value="{{ old('stripe_id') }}" placeholder="price_xxxxx">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">{{__('PayPal Plan ID')}}<small class="text-muted">{{__('(optional)')}}</small></label>
                        <input type="text" name="paypal_plan_id" class="form-control @error('paypal_plan_id') is-invalid @enderror" value="{{ old('paypal_plan_id') }}" placeholder="P-XXXXXXXXXXXX">
                        <small class="text-muted">{{__('Create the recurring plan in PayPal and paste its Plan ID here.')}}</small>
                        @error('paypal_plan_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Create Plan')}}</button>
            </form>
        </div>
    </div>
</div>
@endsection
