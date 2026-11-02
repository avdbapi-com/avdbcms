<?php

namespace App\Notifications\Support;

use App\Http\Controllers\VideoController;
use App\Models\Video;
use App\Support\LocalNumber;
use App\Support\NumberFormat;
use App\Support\PremiumAvailability;
use Carbon\CarbonInterface;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The words of the member e-mails (member design 6.21), in one place for both parts of each mail: the HTML view
 * (mails/<group>/<name>.blade.php, the layout mails/layouts/html) and its real plain-text view
 * (mails/text/<group>/<name>.blade.php, the layout mails/layouts/text). Every method returns the same shape:
 *
 *   subject     the mail's subject (translated)
 *   preheader   the line a mail client shows after the subject
 *   heading     the h1
 *   greeting    "Hello nightdrive_tv," (or null)
 *   paragraphs  plain sentences
 *   panel       an optional framed block: ['title' => ?, 'lines' => [...], 'text' => ? (multi-line), 'image' => ?]
 *   button      ['url' => absolute URL, 'label' => ...] or null: a link that works with images off
 *   note        a smaller line under the button (or null)
 *   help        ['url' => the contact page, 'label' => "Need help? Contact us."] or null: the help line, a link
 *   audience    'member' (the footer names the member's account) or 'owner' (the contact form's mail)
 *   manage      whether the footer links to the member's e-mail settings (not for an account that is deleted or
 *               suspended: it cannot open them)
 *
 * Every text goes through __() in the language the mail is rendered in: the member's (User::preferredLocale;
 * the page's at sign-up). Member text (user names, video titles, a visitor's message) is plain text here: the views
 * print it escaped (HTML) or as it is (text part), never through a Markdown parser (the security rules S26 / S29).
 */
final class MemberMail
{
    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    private static function make(array $parts): array
    {
        return $parts + [
            'subject' => '',
            'preheader' => '',
            'heading' => '',
            'greeting' => null,
            'paragraphs' => [],
            'panel' => null,
            'button' => null,
            'note' => null,
            'help' => null,
            'audience' => 'member',
            'manage' => true,
        ];
    }

    /** "Need help? Contact us.", a link to the contact page (judge R1-132: the Premium mails said it without a link). */
    private static function help(): array
    {
        return ['label' => __('Need help? Contact us.'), 'url' => self::link('contact.show')];
    }

    /**
     * The video's picture for a mail. The stored thumbnail travels inside the mail (an inline picture): a mail client
     * has no session, and the thumbnail's address answers only the owner once a video is suspended (judge R1-052: the
     * picture was always broken). A poster from elsewhere goes by its full address, one of the site's own files by its
     * full address too; the site's placeholder (an SVG, which most mail clients do not show) is left out.
     *
     * @return array{image?: string, embed?: array{path: string, type: string, name: string}}
     */
    public static function videoPicture(mixed $video): array
    {
        if (! $video instanceof Video) {
            return [];
        }

        try {
            $file = (string) ($video->thumbnail?->file ?? '');

            if ($file !== '' && ! str_contains($file, '..')) {
                $disk = Storage::disk('local');
                $path = Video::THUMBNAIL_FOLDER.'/'.$file;

                if ($disk->exists($path) && (int) $disk->size($path) > 0 && (int) $disk->size($path) <= 2 * 1024 * 1024) {
                    $type = strtolower(trim(explode(';', (string) ($disk->mimeType($path) ?: ''), 2)[0]));

                    if (in_array($type, VideoController::THUMBNAIL_TYPES, true)) {
                        return ['embed' => ['path' => $disk->path($path), 'type' => $type, 'name' => 'video.'.(explode('/', $type)[1] ?? 'jpg')]];
                    }
                }
            }
        } catch (Throwable) {
            // no stored picture: a poster, or none
        }

        $poster = trim((string) ($video->poster_url ?? ''));

        if (preg_match('#^https?://[^\s"<>]+$#i', $poster) === 1) {
            return ['image' => $poster];
        }

        if (preg_match('#^/(?!/)[^\s"<>]+$#', $poster) === 1 && ! str_ends_with(strtolower(parse_url($poster, PHP_URL_PATH) ?: ''), '.svg')) {
            return ['image' => url($poster)];
        }

        return [];
    }

    /**
     * The sign-up confirmation and the password-reset mails are Laravel's own notifications (VerifyEmail,
     * ResetPassword: their classes stay, the site's tests and listeners name them); their mail is made here, in the
     * member mail layout, the member's language and the site's words. Called once at boot (RouteServiceProvider).
     */
    public static function registerAuthMails(): void
    {
        VerifyEmail::toMailUsing(function (mixed $notifiable, string $url): MailMessage {
            return (new MailMessage)
                ->subject(self::verify($notifiable, $url)['subject'])
                ->view(['mails.account.verify', 'mails.text.account.verify'], ['notifiable' => $notifiable, 'url' => $url]);
        });

        ResetPassword::toMailUsing(function (mixed $notifiable, string $token): MailMessage {
            // the reset page in the member's language, with the e-mail (the page keeps it read-only: auth-15)
            $email = method_exists($notifiable, 'getEmailForPasswordReset') ? $notifiable->getEmailForPasswordReset() : (string) ($notifiable->email ?? '');
            $url = url(locale_url(route('password.reset', ['token' => $token, 'email' => $email])));

            return (new MailMessage)
                ->subject(self::forgot($notifiable, $url)['subject'])
                ->view(['mails.account.forgot', 'mails.text.account.forgot'], ['notifiable' => $notifiable, 'url' => $url]);
        });
    }

    /** The site's name as the mails print it. */
    public static function site(): string
    {
        return (string) config('app.name', 'AVDBCMS');
    }

    /** "Hello nightdrive_tv," */
    public static function greeting(mixed $notifiable): ?string
    {
        $name = trim((string) (is_object($notifiable) ? ($notifiable->username ?? '') : ''));

        return $name !== '' ? __('Hello :name,', ['name' => $name]) : __('Hello,');
    }

    /** An absolute link to a page of the site in the language the mail is written in. */
    public static function link(string $routeName, array $parameters = []): string
    {
        try {
            return url(locale_url(route($routeName, $parameters)));
        } catch (Throwable) {
            return url('/');
        }
    }

    /** "60 minutes", "24 hours", "3 days": a link's lifetime in words. */
    public static function duration(int $minutes): string
    {
        $minutes = max(1, $minutes);

        if ($minutes >= 2880 && $minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return trans_choice('{1} :count day|[2,*] :count days', $days, ['count' => $days]);
        }

        if ($minutes >= 120 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return trans_choice('{1} :count hour|[2,*] :count hours', $hours, ['count' => $hours]);
        }

        return trans_choice('{1} :count minute|[2,*] :count minutes', $minutes, ['count' => $minutes]);
    }

    /**
     * A date in the site's date format, in the mail's language and the site's time zone, as the member's pages and the
     * invoice PDF write it (BillingFormat::date, the studio's way; R2A-038: the receipt said "Kas 05, 2026" next to a PDF
     * that says "5 Kas 2026"). A sentence that ends with the date keeps one full stop (BillingFormat::fill: "г.").
     */
    public static function date(mixed $value): string
    {
        try {
            $date = $value instanceof CarbonInterface ? $value : Carbon::parse((string) $value);

            return \App\Support\BillingFormat::date($date);
        } catch (Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------------ account

    /** Sign-up: confirm the e-mail (Illuminate's VerifyEmail, its mail made here: RouteServiceProvider::boot). */
    public static function verify(mixed $notifiable, string $url): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Confirm your e-mail'),
            'preheader' => __('One click and your :site account is ready.', ['site' => $site]),
            'heading' => __('Confirm your e-mail'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('You created an account on :site. Confirm your e-mail to finish.', ['site' => $site]),
            ],
            'button' => ['url' => $url, 'label' => __('Confirm my e-mail')],
            'note' => __('The link works for :time. If you did not create this account, you can ignore this e-mail.', [
                'time' => self::duration((int) config('auth.verification.expire', 60)),
            ]),
        ]);
    }

    /** "Forgot password" (Illuminate's ResetPassword, its mail made here). */
    public static function forgot(mixed $notifiable, string $url): array
    {
        $site = self::site();
        $broker = (string) config('auth.defaults.passwords', 'users');

        return self::make([
            'subject' => __('Reset your password'),
            'preheader' => __('Choose a new password for your :site account.', ['site' => $site]),
            'heading' => __('Reset your password'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('Someone asked to reset the password of your :site account. If it was you, choose a new password.', ['site' => $site]),
            ],
            'button' => ['url' => $url, 'label' => __('Choose a new password')],
            'note' => __('The link works for :time. If you did not ask for it, you can ignore this e-mail: your password stays the same.', [
                'time' => self::duration((int) config('auth.passwords.'.$broker.'.expire', 60)),
            ]),
        ]);
    }

    public static function password(mixed $notifiable): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Your password was changed'),
            'preheader' => __('The password of your :site account was changed.', ['site' => $site]),
            'heading' => __('Your password was changed'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('The password of your :site account was changed.', ['site' => $site]),
                __('If you did not change it, contact us right away.'),
            ],
            'button' => ['url' => self::link('contact.show'), 'label' => __('Contact us')],
        ]);
    }

    public static function emailUpdate(mixed $notifiable, string $url): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Confirm your new e-mail'),
            'preheader' => __('You asked to change the e-mail of your :site account.', ['site' => $site]),
            'heading' => __('Confirm your new e-mail'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('You asked to change the e-mail of your :site account.', ['site' => $site]),
                __('Confirm the new address to finish.'),
            ],
            'button' => ['url' => $url, 'label' => __('Confirm this e-mail')],
            'note' => __('The link works for :time. If you did not ask for this, you can ignore this e-mail: nothing changes.', [
                'time' => self::duration((int) config('auth.verification.expire', 60)),
            ]),
        ]);
    }

    public static function ban(mixed $notifiable): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Your account was suspended'),
            'preheader' => __('Your :site account was suspended.', ['site' => $site]),
            'heading' => __('Your account was suspended'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('Your :site account was suspended because it broke our terms of service.', ['site' => $site]),
                __('If you think this is a mistake, contact us.'),
            ],
            'button' => ['url' => self::link('contact.show'), 'label' => __('Contact us')],
            'manage' => false, // a suspended account cannot open its e-mail settings (judge R1-134)
        ]);
    }

    public static function delete(mixed $notifiable): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Your account was deleted'),
            'preheader' => __('Your :site account was deleted.', ['site' => $site]),
            'heading' => __('Your account was deleted'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('Your :site account and its data were deleted, as you asked.', ['site' => $site]),
                __('Thank you for the time you spent with us.'),
            ],
            'note' => __('If you did not ask for this, contact us.'),
            'button' => ['url' => self::link('contact.show'), 'label' => __('Contact us')],
            'manage' => false, // the account is gone (judge R1-134)
        ]);
    }

    // ------------------------------------------------------------------ premium

    public static function welcome(mixed $notifiable): array
    {
        $site = self::site();
        $subscription = is_object($notifiable) ? ($notifiable->premium_subscription ?? null) : null;
        $plan = is_object($subscription) ? ($subscription->plan ?? null) : null;
        $planName = trim((string) (is_object($plan) ? ($plan->name ?? '') : ''));
        $trialEnds = is_object($subscription) ? ($subscription->trial_ends_at ?? null) : null;
        $paragraphs = [];

        if ($trialEnds) {
            $days = max(1, (int) config('plans.trial_period.period', 7));
            $paragraphs[] = \App\Support\BillingFormat::fill(trans_choice('{1} Your free trial of :count day starts now and ends on :date.|[2,*] Your free trial of :count days starts now and ends on :date.', $days, [
                'count' => $days,
            ]), ['date' => self::date($trialEnds)]);
            if ($planName !== '') {
                $paragraphs[] = \App\Support\BillingFormat::fill(__('After the trial, your plan (:plan) is charged from :date.'), ['plan' => $planName, 'date' => self::date($trialEnds)]);
            }
        } else {
            $paragraphs[] = $planName !== ''
                ? __('Your :plan plan is active now.', ['plan' => $planName])
                : __('Your Premium plan is active now.');
        }

        $paragraphs[] = __('You can manage or cancel Premium at any time in Account settings.');

        return self::make([
            'subject' => __('Welcome to :site Premium', ['site' => $site]),
            'preheader' => __('Thank you. Premium is on.'),
            'heading' => __('Thank you. Premium is on.'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => $paragraphs,
            'button' => ['url' => self::link('user.edit'), 'label' => __('Manage Premium')],
            'note' => __('If you cancel, Premium stays until the end of the period you paid for. No partial refunds.'),
            'help' => self::help(),
        ]);
    }

    public static function invoice(mixed $notifiable, mixed $transaction): array
    {
        $site = self::site();
        $from = is_object($transaction) ? ($transaction->date ?? null) : null;
        $subscription = is_object($transaction) ? ($transaction->subscription ?? null) : null;
        $next = is_object($subscription) ? ($subscription->next_payment ?? null) : null;
        $paragraphs = [];

        if ($from && $next) {
            $paragraphs[] = __('Your receipt for :site Premium, :from to :to, is attached to this e-mail.', ['site' => $site, 'from' => self::date($from), 'to' => self::date($next)]);
            $paragraphs[] = \App\Support\BillingFormat::fill(__('Your next payment is on :date.'), ['date' => self::date($next)]);
        } else {
            $paragraphs[] = __('Your receipt for :site Premium is attached to this e-mail.', ['site' => $site]);
        }

        return self::make([
            'subject' => __('Your :site Premium receipt', ['site' => $site]),
            'preheader' => __('Thank you for your payment.'),
            'heading' => __('Your Premium receipt'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => $paragraphs,
            'button' => ['url' => self::link('user.invoices.index'), 'label' => __('See your invoices')],
            'note' => __('Keep this receipt for your records.'),
            'help' => self::help(),
        ]);
    }

    public static function cancel(mixed $notifiable): array
    {
        $site = self::site();

        return self::make([
            'subject' => __('Your :site Premium is cancelled', ['site' => $site]),
            'preheader' => __('Premium stays until the end of the period you paid for.'),
            'heading' => __('Your Premium is cancelled'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                __('Your :site Premium is cancelled. It stays until the end of the period you paid for.', ['site' => $site]),
                __('Changed your mind? You can subscribe again at any time.'),
            ],
            'button' => ['url' => self::link('pages.premium'), 'label' => __('See Premium')],
        ]);
    }

    public static function unpaid(mixed $notifiable, string $url, string $amount = ''): array
    {
        $site = self::site();
        $amount = trim($amount);

        return self::make([
            'subject' => __('Payment problem: your :site Premium', ['site' => $site]),
            'preheader' => __('Update your payment details to keep Premium.'),
            'heading' => __('Payment problem'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [
                $amount !== ''
                    ? __('Your last payment of :amount for :site Premium did not go through.', ['amount' => $amount, 'site' => $site])
                    : __('Your last payment for :site Premium did not go through.', ['site' => $site]),
                __('Update your payment details to keep Premium.'),
            ],
            'button' => ['url' => $url, 'label' => __('Update payment details')],
            'help' => self::help(),
        ]);
    }

    public static function trialEnd(mixed $notifiable): array
    {
        $site = self::site();
        $subscription = is_object($notifiable) ? ($notifiable->premium_subscription ?? null) : null;
        $plan = is_object($subscription) ? ($subscription->plan ?? null) : null;
        $ends = is_object($subscription) ? ($subscription->trial_ends_at ?? null) : null;
        $days = max(1, (int) config('plans.trial_period.email_reminder', 3));
        // the plan's price with the site's currency symbol, written the mail language's way (judge R1-133: "EUR 5")
        $price = $plan instanceof Model && is_numeric($plan->getRawOriginal('price'))
            ? NumberFormat::money((int) $plan->getRawOriginal('price'), PremiumAvailability::currency(), app()->getLocale())
            : trim((string) (is_object($plan) ? ($plan->price ?? '') : ''));
        $period = trim((string) (is_object($plan) ? ($plan->period ?? '') : ''));
        $paragraphs = [];

        if ($ends) {
            $paragraphs[] = \App\Support\BillingFormat::fill(__('Your :site Premium free trial ends on :date.'), ['site' => $site, 'date' => self::date($ends)]);
        }

        if ($price !== '' && $period !== '') {
            $paragraphs[] = __('Then your payment method is charged :price every :period, unless you cancel.', ['price' => $price, 'period' => __($period)]);
        }

        return self::make([
            'subject' => __('Your free trial ends soon'),
            'preheader' => __('Your :site Premium free trial ends soon.', ['site' => $site]),
            'heading' => trans_choice('{1} Your free trial ends in :count day|[2,*] Your free trial ends in :count days', $days, ['count' => $days]),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => $paragraphs !== [] ? $paragraphs : [__('Your :site Premium free trial ends soon.', ['site' => $site])],
            'button' => ['url' => self::link('user.edit'), 'label' => __('Manage Premium')],
        ]);
    }

    public static function cardExpire(mixed $notifiable, string $url): array
    {
        $subscription = is_object($notifiable) ? ($notifiable->premium_subscription ?? null) : null;
        $last4 = trim((string) (is_object($subscription) ? ($subscription->card_last4 ?? '') : ''));
        $expires = is_object($subscription) ? ($subscription->card_expired_at ?? null) : null;
        $days = 0;

        if ($expires instanceof CarbonInterface) {
            $days = max(0, (int) ceil(now()->diffInDays($expires, false)));
        }

        $first = match (true) {
            $last4 !== '' && $days > 0 => trans_choice('{1} The card ending in :last4 expires in :count day.|[2,*] The card ending in :last4 expires in :count days.', $days, ['last4' => $last4, 'count' => $days]),
            $last4 !== '' => __('The card ending in :last4 expires soon.', ['last4' => $last4]),
            default => __('Your card expires soon.'),
        };

        return self::make([
            'subject' => __('Your card expires soon'),
            'preheader' => __('Add your new card to keep Premium without a break.'),
            'heading' => __('Your card expires soon'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [$first, __('Add your new card to keep Premium without a break.')],
            'button' => ['url' => $url, 'label' => __('Update my card')],
            'help' => self::help(),
        ]);
    }

    // ------------------------------------------------------------------ videos and contact

    public static function videoBan(mixed $notifiable, mixed $video): array
    {
        $title = trim((string) (is_object($video) ? ($video->title ?? '') : ''));
        $views = (int) (is_object($video) ? ($video->views ?? 0) : 0);
        $created = is_object($video) ? ($video->created_at ?? null) : null;
        // the count with the mail's language's digit grouping, as the site's pages print it ("2 102 382" in French,
        // "2.102.382" in German; judge R2A-045: it was always "2,102,382")
        $meta = trans_choice('{0} No views|{1} :count view|[2,*] :count views', $views, ['count' => LocalNumber::format($views)]);

        if ($created) {
            $meta .= "\u{00A0}· ".self::date($created);
        }

        return self::make([
            'subject' => __('Your video was suspended'),
            'preheader' => __('One of your videos was suspended.'),
            'heading' => __('Your video was suspended'),
            'greeting' => self::greeting($notifiable),
            'paragraphs' => [__('One of your videos was suspended because it broke our terms of service:')],
            'panel' => ['title' => $title, 'lines' => [$meta]] + self::videoPicture($video),
            'button' => ['url' => self::link('contact.show'), 'label' => __('Contact us')],
            'note' => __('If you think this is a mistake, contact us.'),
        ]);
    }

    /** The contact form's mail to the site's owner (S29: the visitor's text as text, the site as the sender). */
    public static function contact(string $name, string $email, string $text, ?string $ip = null): array
    {
        $site = self::site();
        $name = trim($name);

        return self::make([
            'subject' => __('New message from :name on :site', ['name' => $name, 'site' => $site]),
            'preheader' => __('Reply to this e-mail to answer.'),
            'heading' => __('New message from :name', ['name' => $name]),
            'paragraphs' => [__('Reply to this e-mail to answer :name (:email).', ['name' => $name, 'email' => $email])],
            'panel' => ['text' => $text],
            'note' => $ip !== null && $ip !== '' ? __('Sent from the contact form of :site. Sender address: :ip', ['site' => $site, 'ip' => $ip]) : __('Sent from the contact form of :site.', ['site' => $site]),
            'audience' => 'owner',
        ]);
    }
}
