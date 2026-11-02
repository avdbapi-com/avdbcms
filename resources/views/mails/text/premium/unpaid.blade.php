{{-- A payment did not go through (App\Notifications\Premium\Unpaid). The plain-text part (member design 6.21); the HTML part: mails.premium.unpaid. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::unpaid($notifiable, (string) ($url ?? ''), is_scalar($amount ?? null) ? (string) $amount : '')])
