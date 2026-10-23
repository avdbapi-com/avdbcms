{{-- An icon from the admin sprite (resources/admin/icons.svg). <x-admin.icon name="mail" class="sm" /> --}}
@props(['name', 'class' => ''])
{{ \App\Support\AdminTheme::icon($name, $class) }}
