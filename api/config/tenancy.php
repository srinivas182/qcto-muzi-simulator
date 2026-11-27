<?php

declare(strict_types=1);

return [

    /*
    | The platform's own domain. Tenants get <slug>.<platform_domain> automatically and can
    | add verified custom domains (e.g. learn.ubuntuskills.co.za).
    */
    'platform_domain' => env('PLATFORM_DOMAIN', 'simuwork.test'),

    /*
    | Sub-domains that belong to the platform itself and can never be a tenant slug.
    */
    'reserved_subdomains' => [
        'www', 'admin', 'api', 'app', 'review', 'verify', 'status', 'docs', 'help', 'support',
        'mail', 'email', 'cdn', 'static', 'assets', 'tenants', 'auth', 'login', 'billing',
    ],

    /*
    | Custom domains must publish this TXT record before they are used, proving ownership:
    |   _simuwork-verify.<host>  TXT  <verification token>
    */
    'verification_record_prefix' => '_simuwork-verify',

    /*
    | Mobile apps and the platform API host identify the tenant with this header.
    */
    'header' => 'X-Tenant',
];
