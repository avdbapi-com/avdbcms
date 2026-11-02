{{-- Invoices (member design 6.8): one row per payment: icon, "Premium, monthly · September 2026", the date,
     the invoice number, Paid, the amount right-aligned ("€5.00"), Download PDF (secondary). A missing PDF is made again
     on download, or the page says "This invoice is being prepared, try again in a minute" (studio-28, account-7: never
     a 0-byte PDF). "See Premium" only while Premium can be bought. Dates and amounts as Account settings > Premium and
     the PDF write them (InvoiceService::day() / amount(), App\Support\BillingFormat; R2A-038): the site's currency (this
     list wrote every amount in euros), in the member's language. --}}
@extends('layouts.user')

@section('title', __('Invoices'))

@section('content')
@php
    use App\Support\BillingFormat;

    $desc = match (true) {
        $premium && $endsAt !== null => BillingFormat::fill(__('Premium ends on :date. Your invoices stay here.'), ['date' => BillingFormat::date($endsAt)]),
        $premium && $renewsAt !== null => BillingFormat::fill(__('Premium renews on :date. Your invoices stay here.'), ['date' => BillingFormat::date($renewsAt)]),
        $premium => __('Premium is active. Your invoices stay here.'),
        $endsAt !== null && $invoices->total() > 0 => BillingFormat::fill(__('Premium ended on :date. Your invoices stay here.'), ['date' => BillingFormat::date($endsAt)]),
        $invoices->total() > 0 => __('Your Premium invoices, newest first.'),
        default => __('Invoices appear here after you pay for Premium.'),
    };
@endphp
<div class="avd-mk mk-page">
    <x-mk.head icon="file-invoice" :kicker="__('Account')" :title="__('Invoices')" :desc="$desc">
        @if($canSell && ! $premium && $invoices->total() > 0)
            <x-slot:actions>
                <a class="mk-btn mk-btn--secondary" href="{{ route('pages.premium') }}"><i class="fa-solid fa-star" aria-hidden="true"></i><span>{{ __('See Premium') }}</span></a>
            </x-slot:actions>
        @endif
    </x-mk.head>
    <x-mk.flash refusal />

    @if($invoices->total() === 0)
        <section class="mk-panel">
            <x-mk.empty icon="file-invoice" :title="__('No invoices yet')" :text="__('Invoices appear here after you pay for Premium.')">
                @if($canSell && ! $premium)
                    <a class="mk-btn mk-btn--primary" href="{{ route('pages.premium') }}"><i class="fa-solid fa-star" aria-hidden="true"></i><span>{{ __('See Premium') }}</span></a>
                @endif
            </x-mk.empty>
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Invoices') }}">
            <ul class="mk-rows">
                @foreach($invoices as $invoice)
                    @php
                        // the title Account settings prints for the same invoice ("Premium, monthly · October 2026"; a plan
                        // of 3 months read "yearly" here, a removed plan "Premium · ...")
                        $title = \App\Services\InvoiceService::title($invoice);
                        $amount = \App\Services\InvoiceService::amount($invoice);
                    @endphp
                    <li class="mk-item mk-item--invoice">
                        <span class="mk-attn__icon" style="--mk-tone:var(--avd-muted);--mk-tone-rgb:var(--avd-shade-rgb)" aria-hidden="true"><i class="fa-solid fa-file-invoice"></i></span>
                        <div class="mk-item__body">
                            <span class="mk-item__title">{{ $title }}</span>
                            <div class="mk-row__meta">
                                <span>{{ \App\Services\InvoiceService::day($invoice) }}</span>
                                {{-- the number the PDF and Account settings print (R1-117: this list said "Invoice No 1"), with the
                                     abbreviation's point the PDF's "No. INV-…" has (R3-058: "Invoice No INV-…" read "Invoice: no") --}}
                                <span>{{ __('Invoice No. :number', ['number' => \App\Services\InvoiceService::number($invoice)]) }}</span>
                                <span><x-mk.status kind="public" :label="__('Paid')" /></span>
                            </div>
                        </div>
                        <div class="mk-item__aside">
                            <span class="mk-row__num mk-amount"><bdi>{{ $amount }}</bdi></span>
                            {{-- no download attribute: when the PDF cannot be made right now the address answers with this
                                 page and its "being prepared" message, which must not be saved as a .pdf file --}}
                            <a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ $invoice->route }}"
                               aria-label="{{ __('Download the PDF of :title', ['title' => $title]) }}"><i class="fa-solid fa-download" aria-hidden="true"></i><span>{{ __('Download PDF') }}</span></a>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $invoices->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>
    @endif
</div>
@endsection
