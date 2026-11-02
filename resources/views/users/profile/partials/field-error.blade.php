{{-- The error of one field, under the field (member design 5.9, account-38). The field points to it with
     aria-describedby="<id>" and carries aria-invalid="true" while it has an error.
     @include('users.profile.partials.field-error', ['field' => 'username'])  ->  id "username-error" --}}
@php
    $errorId = $id ?? (str_replace('.', '-', $field).'-error');
@endphp
@error($field)
    <span class="mk-error" id="{{ $errorId }}">{{ $message }}</span>
@enderror
