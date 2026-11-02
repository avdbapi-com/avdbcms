{{--
    The invoice PDF of a Premium payment (member design 6.24), rendered by dompdf (App\Services\InvoiceService):
    CSS 2.1 only (tables and floats, no flexbox, grid or CSS variables), the DejaVu Sans font dompdf ships, A4 portrait
    with 18mm margins. The brand colour appears once, as the rule at the top; everything else is near-black and grey.
    The seller is the owner's (Money > Payment setup > Invoices), else the site name only; a tax line only with a rate.
    Dates and amounts come written as the member's pages write them (InvoiceService::details(), App\Support\BillingFormat).
--}}
@php
    $invoice = $invoice ?? \App\Services\InvoiceService::details($transaction);
    $buyer = $invoice['buyer'];
    $seller = $invoice['seller'];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ __('Invoice :number', ['number' => $invoice['number']]) }} | {{ $invoice['site'] }}</title>
    {{-- the PDF's Keywords: the template version and this payment (InvoiceService::fingerprint()); a stored PDF without
         them is made again before it is sent (R1-027) --}}
    <meta name="keywords" content="{{ \App\Services\InvoiceService::fingerprint($transaction) }}">
    <style>
        @page { margin: 18mm 18mm 20mm; }
        body { margin: 0; color: #15171c; font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 0; text-align: left; }
        .rule { height: 4px; background: {{ $invoice['brand'] }}; margin-bottom: 22px; }
        .site { font-size: 15pt; font-weight: bold; }
        .doc { text-align: right; }
        .doc .title { font-size: 20pt; font-weight: bold; letter-spacing: .5px; }
        .muted { color: #5b616e; }
        .small { font-size: 8.5pt; }
        {{-- the labels and column heads in capitals: Turkish ones come capitalised (InvoiceService::capitalLabel()) --}}
        .label { color: #5b616e; font-size: 8pt; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; }
        .parties td { width: 50%; padding-top: 26px; }
        .items { margin-top: 30px; }
        .items th { padding: 8px 0; border-bottom: 1.5px solid #15171c; color: #5b616e; font-size: 8pt; letter-spacing: .6px; text-transform: uppercase; }
        .items td { padding: 12px 0; border-bottom: 1px solid #d9dce3; }
        .num { text-align: right; white-space: nowrap; }
        .items th.num { text-align: right; }
        .totals { width: 46%; margin: 18px 0 0 54%; }
        .totals td { padding: 5px 0; }
        .totals .total td { padding-top: 10px; border-top: 1.5px solid #15171c; font-size: 12pt; font-weight: bold; }
        .paid { margin-top: 26px; padding: 10px 12px; border: 1px solid #d9dce3; }
        .foot { margin-top: 48px; padding-top: 12px; border-top: 1px solid #d9dce3; color: #5b616e; font-size: 8.5pt; }
    </style>
</head>
<body>
    <div class="rule"></div>
    <table>
        <tr>
            <td>
                <div class="site">{{ $seller['name'] }}</div>
                @if($invoice['host'] !== '')
                    <div class="muted small">{{ $invoice['host'] }}</div>
                @endif
            </td>
            <td class="doc">
                <div class="title">{{ __('Invoice') }}</div>
                <div class="muted">{{ __('No. :number', ['number' => $invoice['number']]) }}</div>
                <div class="muted">{{ __('Date: :date', ['date' => $invoice['date']]) }}</div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ \App\Services\InvoiceService::capitalLabel(__('Billed to')) }}</div>
                @foreach(array_filter([$buyer['name'], $buyer['address'], $buyer['city'], $buyer['country']], fn ($line) => $line !== '') as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if($buyer['vat_id'] !== '')
                    <div>{{ __('VAT number: :number', ['number' => $buyer['vat_id']]) }}</div>
                @endif
            </td>
            <td>
                <div class="label">{{ \App\Services\InvoiceService::capitalLabel(__('From')) }}</div>
                <div>{{ $seller['name'] }}</div>
                @foreach($seller['address'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if($seller['tax_id'] !== '')
                    <div>{{ __('Tax number: :number', ['number' => $seller['tax_id']]) }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>{{ \App\Services\InvoiceService::capitalLabel(__('Description')) }}</th>
                <th class="num">{{ \App\Services\InvoiceService::capitalLabel(__('Period')) }}</th>
                <th class="num">{{ \App\Services\InvoiceService::capitalLabel(__('Price')) }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>{{ $invoice['item'] }}</strong>
                    <div class="muted small">{{ __('Your Premium plan on :site', ['site' => $invoice['site']]) }}</div>
                </td>
                <td class="num">{{ $invoice['period'] }}</td>
                <td class="num">{{ $invoice['subtotal'] }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">{{ __('Subtotal') }}</td>
            <td class="num">{{ $invoice['subtotal'] }}</td>
        </tr>
        @if($invoice['tax'] !== null)
            <tr>
                <td class="muted">{{ $invoice['tax_label'] }}</td>
                <td class="num">{{ $invoice['tax'] }}</td>
            </tr>
        @endif
        <tr class="total">
            <td>{{ __('Total paid') }}</td>
            <td class="num">{{ $invoice['total'] }}</td>
        </tr>
    </table>

    <div class="paid">
        {{-- a date with its own full stop ("26 нояб. 2026 г.") ends the sentence with it (BillingFormat::fill) --}}
        <strong>{{ \App\Support\BillingFormat::fill(__('Paid on :date.'), ['date' => $invoice['paid_on']]) }}</strong>
        <span class="muted">{{ __('Thank you. Keep this invoice for your records.') }}</span>
    </div>

    <div class="foot">
        @if($invoice['support'] !== '')
            {{ __('Questions about this invoice? Write to :email.', ['email' => $invoice['support']]) }}
        @endif
        {{ __('Invoice :number, page 1 of 1.', ['number' => $invoice['number']]) }}
    </div>
</body>
</html>
