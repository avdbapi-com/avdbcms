<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;
use App\Support\BillingFormat;
use App\Support\ThemeTokens;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The invoice PDF of a Premium payment (member design 6.24, account-1).
 *
 * The seller block comes from the owner's settings (Money > Payment setup > Invoices: invoice_seller_name,
 * invoice_seller_address, invoice_seller_tax_id, invoice_tax_label, invoice_tax_rate); until they are filled it is the
 * site name only, never another company, and there is no tax line (OD-M8; before 1.2.3 every site printed a French
 * company with its SIRET, VAT number and a fixed "TVA (20%)"). The invoice is written in the member's language when
 * the PDF font can draw it (dompdf ships DejaVu Sans: Latin, Cyrillic and Vietnamese letters; Arabic would print
 * unjoined, Chinese, Japanese, Korean, Hindi and Thai not at all), else in English; names and addresses stay as typed.
 * Its dates and amounts read as on the member's pages (App\Support\BillingFormat, R2A-038): "26 Eki 2026", "€4,20".
 */
class InvoiceService
{
    /** The site languages DejaVu Sans can draw (measured on lang/<locale>.json: 0 letters missing). */
    public const PDF_LANGUAGES = ['de', 'en', 'es', 'fil', 'fr', 'id', 'ms', 'pt', 'ru', 'tl', 'tr', 'vi'];

    /** The settings group the seller details are saved in (Money > Payment setup). */
    public const SETTINGS_GROUP = 'monetization';

    /**
     * The version of the invoice template (users/invoice.blade.php). Every PDF carries it with the payment it belongs to
     * (fingerprint()); a stored PDF without the current one is made again before it is sent (R1-027). Raise it when the
     * template changes what an invoice says. 3 (R2A-038): dates and amounts in the member's language, as Account settings
     * and the Invoices list print them ("26 Eki 2026", "€4,20"; version 2 wrote "Eki 26, 2026", "€4.20").
     */
    public const TEMPLATE_VERSION = 3;

    /** The prefix of the fingerprint in the PDF's document properties (Keywords). */
    public const FINGERPRINT_PREFIX = 'avd-invoice';

    public function generate(Transaction $transaction)
    {
        $locale = self::locale($transaction);
        $previous = App::getLocale();

        try {
            App::setLocale($locale);

            // Only the letters an invoice uses are embedded from the font (R1-028: the whole DejaVu Sans made every PDF
            // about 860 KB; with the used letters it is about 25 KB and is made faster).
            $pdf = Pdf::loadView('users.invoice', [
                'transaction' => $transaction,
                'invoice' => self::details($transaction, $locale),
            ])->setPaper('a4', 'portrait')->setOption('isFontSubsettingEnabled', true);

            return $pdf->save($transaction->invoice_path, config('filesystems.default'));
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Make sure the invoice's PDF on the disk is this payment's, made with the current template (studio-28 / account-7:
     * a missing file was sent as a 0-byte PDF; R1-027: a PDF of an older template, or of another payment after a
     * database restore reused the number, was sent as it was): anything else is made again. False when it could not be
     * made (the member sees "This invoice is being prepared"; the stored file is never sent then).
     */
    public function ensurePdf(Transaction $transaction): bool
    {
        try {
            if (self::isCurrent($transaction)) {
                return true;
            }

            $this->generate($transaction);

            return self::isCurrent($transaction);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What a PDF of this payment carries in its document properties: the template version and a hash of the payment
     * (its number, the payment service's id, the amount and the date), e.g. "avd-invoice/2/3f9a...". Names, addresses
     * and the member are not in it: an invoice keeps its fingerprint when the member's account is deleted.
     */
    public static function fingerprint(Transaction $transaction): string
    {
        $payment = implode('|', [
            (string) $transaction->getKey(),
            (string) $transaction->getRawOriginal('stripe_id'),
            (string) (int) $transaction->getRawOriginal('amount'),
            self::storedDate($transaction),
        ]);

        return self::FINGERPRINT_PREFIX.'/'.self::TEMPLATE_VERSION.'/'.substr(hash('sha256', $payment), 0, 32);
    }

    /**
     * The payment's date as the database keeps it ("2026-10-20 10:00:00"), never as a moment in time: the site's time
     * zone (Settings > Site > Time zone) changes the moment a stored date stands for, not the date an invoice prints, so
     * a change of time zone must not make every stored invoice "not current" and have it made again.
     */
    private static function storedDate(Transaction $transaction): string
    {
        $raw = $transaction->getRawOriginal('date') ?? ($transaction->getAttributes()['date'] ?? null);

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        try {
            // the same text for "2026-10-20 10:00:00", "2026-10-20 10:00:00.000000" or "2026-10-20T10:00:00" (read as
            // written: UTC is only the frame, no time zone is applied to the stored digits)
            return Carbon::parse($raw, 'UTC')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $raw;
        }
    }

    /**
     * True when the stored PDF of $transaction (its invoice_path, or $path) is a PDF that carries this payment's
     * fingerprint: not missing, not empty, not an older template (1.2.2 printed another company and tax), not another
     * payment's invoice (a database restored from a backup reuses invoice numbers while the PDFs on the disk stay).
     */
    public static function isCurrent(Transaction $transaction, ?string $path = null): bool
    {
        try {
            $path ??= $transaction->invoice_path;

            if (! Storage::exists($path) || (int) Storage::size($path) <= 0) {
                return false;
            }

            $pdf = (string) Storage::get($path);
        } catch (Throwable) {
            return false;
        }

        if (! str_starts_with($pdf, '%PDF')) {
            return false;
        }

        $fingerprint = self::fingerprint($transaction);

        // dompdf writes the document properties as UTF-16 text; another writer may keep them as they are
        return str_contains($pdf, (string) mb_convert_encoding($fingerprint, 'UTF-16BE', 'UTF-8'))
            || str_contains($pdf, $fingerprint);
    }

    /**
     * A label the invoice prints in capitals (the "Billed to" / "From" labels and the column heads: text-transform:
     * uppercase). dompdf capitalises with Unicode's default rule, right for every invoice language but Turkish, where
     * "i" has a dotted capital: "Fatura edilen" printed "FATURA EDILEN" instead of "FATURA EDİLEN". For Turkish (and
     * Azerbaijani, the same rule) the capitals are made here; any other language is returned as it is and the style
     * capitalises it (already-capital text stays as it is).
     */
    public static function capitalLabel(string $text, ?string $locale = null): string
    {
        $base = strtolower((string) explode('-', str_replace('_', '-', $locale ?? App::getLocale()))[0]);

        if (! in_array($base, ['tr', 'az'], true)) {
            return $text;
        }

        return mb_convert_case(str_replace('i', "\u{0130}", $text), MB_CASE_UPPER, 'UTF-8');
    }

    /**
     * The language the invoice is written in: the member's, when the PDF font can draw it, else English. A payment
     * whose member is gone (a deleted account) gets the site's language, read the same way on the web and in a console
     * process (site_default_locale()): there site.default_locale is never set and app.locale is the install-time value,
     * or the language of the last mail Laravel rendered (R1R-07).
     */
    public static function locale(Transaction $transaction): string
    {
        $user = $transaction->user;
        $locale = $user !== null ? $user->preferredLocale() : site_default_locale();
        $base = strtolower((string) explode('-', $locale)[0]);

        return in_array($base, self::PDF_LANGUAGES, true) ? $base : 'en';
    }

    /**
     * The seller block: the owner's settings, else the site name only (no address, no tax id, no tax line).
     *
     * @return array{name: string, address: list<string>, tax_id: string, tax_label: string, tax_rate: float}
     */
    public static function seller(): array
    {
        $stored = [];

        try {
            $stored = Setting::getGroup(self::SETTINGS_GROUP);
        } catch (Throwable) {
            // No settings table (a site in the middle of an update): the site name only.
        }

        $value = function (string $key) use ($stored): string {
            $configured = config('site.'.$key);

            return trim((string) (is_scalar($configured) && (string) $configured !== '' ? $configured : ($stored[$key] ?? '')));
        };

        $name = $value('invoice_seller_name');
        $rate = str_replace(',', '.', $value('invoice_tax_rate'));
        $rate = is_numeric($rate) ? (float) $rate : 0.0;

        return [
            'name' => $name !== '' ? $name : (string) config('app.name'),
            'address' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value('invoice_seller_address')) ?: []), fn (string $line): bool => $line !== '')),
            'tax_id' => $value('invoice_seller_tax_id'),
            'tax_label' => $value('invoice_tax_label'),
            'tax_rate' => $rate > 0 && $rate <= 100 ? round($rate, 2) : 0.0,
        ];
    }

    /** The invoice number: "INV-2026-0042" (the year of the payment and the payment's number, never reused). */
    public static function number(Transaction $transaction): string
    {
        return sprintf('INV-%s-%04d', $transaction->date?->format('Y') ?? now()->format('Y'), (int) $transaction->getKey());
    }

    /** "Premium, monthly" / "Premium, yearly" / "Premium plan" (a plan that is gone). */
    public static function planLabel(Transaction $transaction): string
    {
        $months = (int) ($transaction->subscription?->plan?->duration ?? 0);

        return match (true) {
            $months === 1 => __('Premium, monthly'),
            $months === 12 => __('Premium, yearly'),
            $months > 1 => trans_choice('{1} Premium, every month|[2,*] Premium, every :count months', $months, ['count' => $months]),
            default => __('Premium plan'),
        };
    }

    /** The row title of an invoice in a list: "Premium, monthly · September 2026", "Premium, aylık · Ekim 2026", "… · 2026年10月". */
    public static function title(Transaction $transaction): string
    {
        $month = BillingFormat::month($transaction->date);

        return trim(self::planLabel($transaction).($month !== '' ? "\u{00A0}· ".$month : ''));
    }

    /** The date of the payment as every page and the PDF print it: "Oct 26, 2026", "26 Eki 2026" (BillingFormat). */
    public static function day(Transaction $transaction): string
    {
        return BillingFormat::date($transaction->date);
    }

    /**
     * The amount the member paid, with the currency symbol, in the reader's language: "€5.00", "5,00 €", "€5,00"
     * (BillingFormat; the Invoices list, Account settings and the PDF print the same, R2A-038).
     */
    public static function amount(Transaction $transaction): string
    {
        return BillingFormat::money((int) $transaction->getRawOriginal('amount'), 2);
    }

    /**
     * The period a payment pays for: from the payment's day to the day before the next one ("24 Oct – 23 Nov 2026"),
     * from the plan's months; null when the plan is gone.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function period(Transaction $transaction): ?array
    {
        $months = (int) ($transaction->subscription?->plan?->duration ?? 0);

        if ($months < 1 || $transaction->date === null) {
            return null;
        }

        $from = $transaction->date->copy()->startOfDay();

        return [$from, $from->copy()->addMonthsNoOverflow($months)->subDay()];
    }

    /**
     * Everything the PDF prints, in cents and words, in $locale (the page's language when none is given). Dates and
     * amounts as the member's pages print them (BillingFormat, R2A-038): "26 Eki 2026", "€4,20", "KDV %19".
     *
     * @return array<string, mixed>
     */
    public static function details(Transaction $transaction, ?string $locale = null): array
    {
        $locale ??= (string) App::getLocale();

        return BillingFormat::inLocale($locale, fn (): array => self::detailsInLocale($transaction, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private static function detailsInLocale(Transaction $transaction, string $locale): array
    {
        $seller = self::seller();
        $total = max(0, (int) $transaction->getRawOriginal('amount'));
        $storedTax = max(0, (int) $transaction->getRawOriginal('tax'));
        $tax = null;

        if ($seller['tax_rate'] > 0) {
            // The tax the payment service charged; else the part of the price the owner's rate stands for.
            $tax = $storedTax > 0 && $storedTax < $total ? $storedTax : (int) round($total - $total / (1 + $seller['tax_rate'] / 100));
        }

        $period = self::period($transaction);
        $country = '';

        if (filled($transaction->country)) {
            try {
                $country = \Symfony\Component\Intl\Countries::getName(strtoupper((string) $transaction->country), $locale);
            } catch (Throwable) {
                $country = (string) $transaction->country;
            }
        }

        return [
            'number' => self::number($transaction),
            'date' => BillingFormat::date($transaction->date),
            'site' => (string) config('app.name'),
            'host' => (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''),
            'seller' => $seller,
            'buyer' => [
                'name' => trim((string) $transaction->name),
                'address' => trim((string) $transaction->address),
                'city' => trim(trim((string) ($transaction->postal_code ?? '')).' '.trim((string) $transaction->city)),
                'country' => $country,
                'vat_id' => trim((string) $transaction->vat_id),
            ],
            'item' => self::planLabel($transaction),
            'period' => $period ? BillingFormat::date($period[0]).' – '.BillingFormat::date($period[1]) : '',
            'subtotal' => BillingFormat::money($total - ($tax ?? 0), 2),
            'tax' => $tax !== null ? BillingFormat::money($tax, 2) : null,
            'tax_label' => $tax !== null ? trim(($seller['tax_label'] !== '' ? $seller['tax_label'] : __('Tax')).' '.BillingFormat::percent($seller['tax_rate'])) : null,
            'total' => BillingFormat::money($total, 2),
            'paid_on' => BillingFormat::date($transaction->date),
            'support' => (string) (config('site.support_email') ?: config('site.contact_email') ?: config('mail.from.address') ?: ''),
            'brand' => self::brandColor(),
        ];
    }

    /** The site look's light-mode brand colour (the 4px rule at the top), as in the member e-mails; else near-black. */
    public static function brandColor(): string
    {
        try {
            $selection = ThemeTokens::resolve((string) config('site.theme_preset', ''), (string) config('site.theme_flavor', ''));
            $theme = ThemeTokens::theme($selection['theme']);

            if ($theme !== null) {
                $tokens = ThemeTokens::derive($theme, 'light', ThemeTokens::flavor($theme, $selection['flavor']));

                if (preg_match('/#[0-9a-fA-F]{6}\b/', (string) ($tokens['--avd-brand-fill'] ?? ''), $match)) {
                    return strtolower($match[0]);
                }
            }
        } catch (Throwable) {
            // The rule falls back to the text colour.
        }

        return '#15171c';
    }
}
