{{-- The HTML part of every member e-mail (member design 6.21): a 600px table with inline styles (mail clients have
     no CSS variables and drop <style> blocks), light colours on white, the site theme's light-mode brand colour on the top
     rule and the one button (App\Notifications\Support\MailTheme), the site's name, an h1, plain paragraphs, an optional
     framed block, ONE button (a link that works with images off: the address is also printed under it), a note, a help
     line (a link to the contact page) and a footer ("Manage e-mails" only for an account that can open its settings).
     A video's stored picture travels inside the mail (an inline part), never by an address a mail client cannot open.
     Every text is printed escaped ({{ }}): member text never becomes a link or a picture (the security rules S26 / S29).
     $mail: the array of App\Notifications\Support\MemberMail. --}}
@php
    $mailLocale = str_replace('_', '-', (string) app()->getLocale());
    $mailLanguage = (array) config('languages.'.app()->getLocale(), []);
    $mailBase = strtolower((string) explode('-', $mailLocale)[0]);
    $mailRtl = array_key_exists('rtl', $mailLanguage) ? (bool) $mailLanguage['rtl'] : in_array($mailBase, ['ar', 'fa', 'he', 'ur'], true);
    $mailDir = $mailRtl ? 'rtl' : 'ltr';
    $mailAlign = $mailRtl ? 'right' : 'left';
    $mailColors = \App\Notifications\Support\MailTheme::colors();
    $mailSite = \App\Notifications\Support\MemberMail::site();
    $mailFont = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif";
    $mailButton = is_array($mail['button'] ?? null) && ($mail['button']['url'] ?? '') !== '' ? $mail['button'] : null;
    $mailPanel = is_array($mail['panel'] ?? null) ? $mail['panel'] : null;
    $mailOwner = ($mail['audience'] ?? 'member') === 'owner';
    $mailHelp = is_array($mail['help'] ?? null) && ($mail['help']['url'] ?? '') !== '' ? $mail['help'] : null;
    $mailManage = ($mail['manage'] ?? true) !== false;
    // a picture that travels inside the mail (MemberMail::videoPicture()): only when the mailer renders the mail
    $mailImage = $mailPanel['image'] ?? null;
    if (empty($mailImage) && is_array($mailPanel['embed'] ?? null) && isset($message) && $message instanceof \Illuminate\Mail\Message) {
        try {
            $mailImage = $message->embedData((string) file_get_contents($mailPanel['embed']['path']), (string) $mailPanel['embed']['name'], (string) $mailPanel['embed']['type']);
        } catch (\Throwable) {
            $mailImage = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $mailLocale }}" dir="{{ $mailDir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $mail['subject'] ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;color:#111827;font-family:{{ $mailFont }};-webkit-text-size-adjust:100%;">
@if(($mail['preheader'] ?? '') !== '')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f3f4f6;font-size:1px;line-height:1px;">{{ $mail['preheader'] }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" dir="{{ $mailDir }}" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
<tr><td style="height:4px;line-height:4px;font-size:4px;background-color:{{ $mailColors['brand'] }};border-radius:12px 12px 0 0;">&nbsp;</td></tr>
<tr>
<td style="padding:22px 32px 0;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};font-size:15px;font-weight:700;color:#111827;">{{ $mailSite }}</td>
</tr>
<tr>
<td style="padding:18px 32px 4px;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};">
<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:700;color:#111827;">{{ $mail['heading'] ?? '' }}</h1>
@if(($mail['greeting'] ?? null) !== null && $mail['greeting'] !== '')
<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#111827;">{{ $mail['greeting'] }}</p>
@endif
@foreach((array) ($mail['paragraphs'] ?? []) as $mailParagraph)
<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#111827;">{{ $mailParagraph }}</p>
@endforeach
</td>
</tr>
@if($mailPanel)
<tr>
<td style="padding:4px 32px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
<tr>
<td style="padding:14px 16px;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};font-size:14px;line-height:1.6;color:#111827;">
@if(! empty($mailImage))
<img src="{{ $mailImage }}" alt="{{ $mailPanel['title'] ?? '' }}" width="240" style="display:block;width:240px;max-width:100%;height:auto;border-radius:6px;margin:0 0 10px;border:0;">
@endif
@if(($mailPanel['title'] ?? '') !== '')
<strong style="display:block;font-size:15px;color:#111827;">{{ $mailPanel['title'] }}</strong>
@endif
@foreach((array) ($mailPanel['lines'] ?? []) as $mailLine)
<span style="display:block;color:#4b5563;font-size:13px;">{{ $mailLine }}</span>
@endforeach
@if(($mailPanel['text'] ?? '') !== '')
<div style="white-space:normal;word-break:break-word;">{!! nl2br(e((string) $mailPanel['text'])) !!}</div>
@endif
</td>
</tr>
</table>
</td>
</tr>
@endif
@if($mailButton)
<tr>
<td style="padding:10px 32px 6px;text-align:{{ $mailAlign }};">
<a href="{{ $mailButton['url'] }}" class="button button-primary" target="_blank" rel="noopener" style="display:inline-block;padding:12px 22px;border-radius:8px;background-color:{{ $mailColors['brand'] }};color:{{ $mailColors['on_brand'] }};font-family:{{ $mailFont }};font-size:15px;font-weight:700;line-height:1.2;text-decoration:none;">{{ $mailButton['label'] }}</a>
</td>
</tr>
<tr>
<td style="padding:6px 32px 4px;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};font-size:12px;line-height:1.5;color:#6b7280;word-break:break-all;">{{ __('If the button does not work, open this address:') }}<br><a href="{{ $mailButton['url'] }}" style="color:{{ $mailColors['link'] }};text-decoration:underline;">{{ $mailButton['url'] }}</a></td>
</tr>
@endif
@if(($mail['note'] ?? null) !== null && $mail['note'] !== '')
<tr>
<td style="padding:10px 32px 4px;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};font-size:13px;line-height:1.6;color:#4b5563;">{{ $mail['note'] }}</td>
</tr>
@endif
@if($mailHelp)
<tr>
<td style="padding:10px 32px 4px;text-align:{{ $mailAlign }};font-family:{{ $mailFont }};font-size:13px;line-height:1.6;color:#4b5563;"><a href="{{ $mailHelp['url'] }}" style="color:{{ $mailColors['link'] }};text-decoration:underline;">{{ $mailHelp['label'] }}</a></td>
</tr>
@endif
<tr><td style="padding:0 32px 22px;">&nbsp;</td></tr>
</table>
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" dir="{{ $mailDir }}" style="width:100%;max-width:600px;">
<tr>
<td style="padding:16px 32px;text-align:center;font-family:{{ $mailFont }};font-size:12px;line-height:1.6;color:#6b7280;">
@if($mailOwner)
{{ __('You get this e-mail because you run :site.', ['site' => $mailSite]) }}
@else
{{ __('You get this e-mail because of your account on :site.', ['site' => $mailSite]) }}
@if($mailManage)
<a href="{{ \App\Notifications\Support\MemberMail::link('user.edit', ['section' => 'notifications']) }}" style="color:#4b5563;text-decoration:underline;">{{ __('Manage e-mails') }}</a>
@endif
@endif
&nbsp;· © {{ now()->year }} {{ $mailSite }}
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
