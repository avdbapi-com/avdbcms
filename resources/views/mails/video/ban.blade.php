{{-- A video was suspended (App\Notifications\Video\VideoBanned). The HTML part (member design 6.21); the plain-text part: mails.text.video.ban. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::videoBan($notifiable, $video ?? null)])
