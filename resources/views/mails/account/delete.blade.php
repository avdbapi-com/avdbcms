{{-- The account was deleted (App\Notifications\Account\DeleteAccount). The HTML part (member design 6.21); the plain-text part: mails.text.account.delete. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::delete($notifiable)])
