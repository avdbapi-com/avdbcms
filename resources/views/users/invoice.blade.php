<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Invoice No :number', ['number' => $transaction->id]) }}</title>
    <link rel="stylesheet" href="css/pdf.min.css">
</head>
<body>
    <div class="container">
        <h1 class="text-center">{{__('Invoice')}}</h1>
        <hr>
        <div style="padding-bottom: 20px;margin-bottom: 20px">
            <div class="text-left" style="float: left">
                <strong class="fw-bold">{{ __('Date') }} :</strong> <span>{{$transaction->date->format('d/m/Y')}}</span>
            </div>
            <div class="text-right" style="float: right">
                <strong>{{ __('Invoice No') }} : </strong> <span>{{$transaction->id}}</span>
            </div>
        </div>
        <hr>
        <div style="padding-bottom: 100px">
            <div class="text-left" style="float: left">
                <strong>{{__('Invoices To')}} :</strong>
                <div>{{$transaction->name}}</div>
                <div>{{$transaction->address}}</div>
                <div>{{$transaction->city}}</div>
                <div>{{\Symfony\Component\Intl\Countries::getName($transaction->country)}}</div>
                @if($transaction->vat_id)
                    <div>{{$transaction->vat_id}}</div>
                @endif
            </div>
            <div class="text-right" style="float: right">
                <strong>{{__('Pay To')}} :</strong>
                <div>{{config('app.name')}}</div>
                <div>91240 Saint-Michel-sur-Orge</div>
                <div>SIRET : 84011597600036</div>
                <div>TVA : FR44840115976</div>
            </div>
        </div>
        <hr>
        <table class="table table-bordered">
        <thead>
            <tr class="active">
                <th>{{__('Description')}}</th>
                <th>{{__('Amount Excluding taxes')}}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('Premium account :plan plan - From :from to :to', ['plan' => $transaction->subscription->plan->name, 'from' => $transaction->date->format('d/m/Y'), 'to' => $transaction->subscription->next_payment->format('d/m/Y')]) }}</td>
                <td>{{ $transaction->amount_without_tax }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="active">
                <th scope="row" colspan="2" class="text-right">
                    {{ __('Excluding taxes') }} : {{ $transaction->amount_without_tax }}
                </th>
            </tr>
            <tr class="active">
                <th scope="row" colspan="2" class="text-right">
                    TVA (20%) : {{ $transaction->tax }}
                </th>
            </tr>
            <tr class="active">
                <th scope="row" colspan="2" class="text-right">
                    {{ __('Total') }} : {{ $transaction->amount }}
                </th>
            </tr>
        </tfoot>
    </table>
        <hr>
        <p>{{ __('Thank you for your purchase. Keep this invoice for your records.') }}</p>
    </div>
</body>
</html>
