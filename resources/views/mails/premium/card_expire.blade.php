{{-- The card expires soon (App\Notifications\Premium\CardExpired). The HTML part (member design 6.21); the plain-text part: mails.text.premium.card_expire. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::cardExpire($notifiable, (string) ($url ?? ''))])
