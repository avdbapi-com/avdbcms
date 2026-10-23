{{-- The settings page script (resources/views/admin/settings/page.js), served as a cached file. --}}
<script src="{{ route('admin.settings.script', ['v' => \App\Http\Controllers\Admin\SettingsPageController::scriptVersion()]) }}" defer></script>
