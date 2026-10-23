@extends('layouts.admin')

@section('title', __('Spams'))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <h2>{{__('Spam')}}</h2>
    </div>
    <hr>
    <form method="POST" action="{{route('admin.spams.update')}}">
        @csrf()
        @method('PUT')
        <div class="row mb-3">
            <div class="col-12 col-xxl-6">
                <div>
                    <label for="words" class="form-label">{{__('Stop words')}}</label>
                    <textarea class="form-control" id="words" rows="20" name="words">{{$words}}</textarea>
                </div>
            </div>
            <div class="col-12 col-xxl-6">
                <div>
                    <label for="emails" class="form-label">{{__('Stop emails')}}</label>
                    <textarea class="form-control" id="emails" rows="20" name="emails">{{$emails}}</textarea>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">{{__('Update')}}</button>
        </div>
    </form>
@endsection

