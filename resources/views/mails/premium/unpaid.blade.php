{{-- A payment did not go through (App\Notifications\Premium\Unpaid). The HTML part (member design 6.21); the plain-text part: mails.text.premium.unpaid. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::unpaid($notifiable, (string) ($url ?? ''), is_scalar($amount ?? null) ? (string) $amount : '')])
