<div x-data="{device : {}}">
    <div class="row align-items-start my-3">
        <div class="col-12 col-xl-4">
            <h2>{{ __('Connected Devices') }}</h2>
            <p class="text-muted">{{ __('See devices using your account') }}</p>
        </div>
        <div class="col-12 col-xl-8">
            <div class="card shadow-soft my-3">
                <div class="card-body">
                    @if($user->devices->isEmpty())
                        <div class="alert alert-primary mb-0">
                            {{ __("You haven't connected any device yet") }}
                        </div>
                    @else
                        <div class="table-responsive avd-mobile-card-table-wrap">
                            <table class="table avd-devices-table">
                                <thead>
                                <tr>
                                    <th scope="col" style="min-width: 230px">{{ __('Device') }}</th>
                                    <th scope="col" style="min-width: 150px">{{ __('Last activity') }}</th>
                                    <th scope="col" style="min-width: 120px">{{ __('Created at') }}</th>
                                    <th scope="col" style="min-width: 200px">{{ __('Expires at') }}</th>
                                    <th scope="col" style="min-width: 6px">{{ __('Actions') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($user->devices as $device)
                                    <tr>
                                        <td data-label="{{ __('Device') }}">
                                            <dynamic-input value="{{$device->name}}" name="name" url="{{route('devices.update', $device)}}" maxlength="50" />
                                        </td>
                                        <td data-label="{{ __('Last activity') }}">{{$device->last_used_at?->diffForHumans()}}</td>
                                        <td data-label="{{ __('Created at') }}">{{$device->created_at->diffForHumans()}}</td>
                                        <td data-label="{{ __('Expires at') }}">{{$device->expires_at?->translatedFormat('d F Y H:i') ?? __('Never')}}</td>
                                        <td data-label="{{ __('Actions') }}">
                                            <button
                                                class="btn btn-danger btn-sm"
                                                title="{{__('Delete device')}}"
                                                aria-label="{{__('Delete device')}}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#delete_device"
                                                data-name="{{$device->name}}"
                                                data-route="{{route('devices.delete', $device)}}"
                                                @click="device = $el.dataset;"
                                            >
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @include('users.profile.modals.delete_device')
</div>

