{{--
    The body of a list page, sent as the shell sends its own markup: without template indentation and with same-site
    addresses as paths (only the scheme and host go, so a site served under /index.php keeps it), so a page that
    renders its own <x-admin.page-header> (tabs, crumbs) reads like the automatic one and a 25-row list stays small.
    @section('content') <x-admin.list-page> <x-admin.page-header ... /> <x-admin.table ...> ... </x-admin.list-page> @endsection
    Text that must keep its line starts (a <pre>) writes its line breaks as &#10;.
--}}
@php($origin = request()->getSchemeAndHttpHost())
{!! str_replace([' href="'.$origin.'/', ' action="'.$origin.'/', ' src="'.$origin.'/'], [' href="/', ' action="/', ' src="/'], (string) preg_replace('/\n\s+/', "\n", (string) $slot)) !!}
