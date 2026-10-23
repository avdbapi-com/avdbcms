<?php

use libphonenumber\PhoneNumberUtil;

$phoneUtil = PhoneNumberUtil::getInstance();
$regionNames = [
    'AC' => 'Ascension Island',
    'TA' => 'Tristan da Cunha',
    'XK' => 'Kosovo',
];
$countries = [];

foreach ($phoneUtil->getSupportedRegions() as $code) {
    if (! is_string($code) || $code === '001') {
        continue;
    }

    $countries[] = [
        'prefix' => '+' . $phoneUtil->getCountryCodeForRegion($code),
        'code' => $code,
        'flag' => $code,
        'name' => $regionNames[$code] ?? null,
    ];
}

usort($countries, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));

return [
    'default_country' => env('PHONE_DEFAULT_COUNTRY', 'FR'),
    'countries' => $countries,
];
