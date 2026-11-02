{{-- The site's own maintenance mode (Settings > Maintenance, App\Http\Middleware\CheckMaintenanceMode): the same status
     card as a 503 (member design 6.15) with the admin's maintenance message, in the site's header and slim footer;
     it reloads by itself after the admin's "retry after". The middleware passes $message (the admin's text) and $title
     (not shown: the card's own words are translated). --}}
@include('errors.503', ['maintenanceMessage' => isset($message) && is_string($message) ? $message : (string) config('site.maintenance_message', '')])
