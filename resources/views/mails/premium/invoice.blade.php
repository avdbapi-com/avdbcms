{{-- The Premium receipt, the PDF attached (App\Notifications\Premium\Invoice). The HTML part (member design 6.21); the plain-text part: mails.text.premium.invoice. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::invoice($notifiable, $transaction ?? null)])
