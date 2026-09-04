<?php

/*
| Where the hosted service runs. Every statement the legal pages make about
| data location, and the OVH sub-processor row, reads from this one array -
| the pages used to hardcode "France" in four places, which is exactly how a
| promise goes stale after a move.
|
| Set `in_eea` to false when the servers sit outside the EEA, and give
| `transfer_basis` the Chapter V ground that covers the transfer. The pages
| swap to third-country wording on their own.
*/
$hosting = [
    'provider' => env('LEGAL_HOSTING_PROVIDER', 'OVH'),
    'country' => env('LEGAL_HOSTING_COUNTRY', 'France'),
    'in_eea' => (bool) env('LEGAL_HOSTING_IN_EEA', true),
    'transfer_basis' => env('LEGAL_HOSTING_TRANSFER_BASIS', ''),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Operator Identity
    |--------------------------------------------------------------------------
    |
    | Who runs this instance. The hosted service at bilis.app is operated by
    | the entity described here; the legal pages read every one of these
    | values rather than hardcoding them, so a fork that offers its own
    | hosted instance can drop in its own details without touching prose.
    |
    | `company_id` is the IČO and `tax_id` the DIČ. `vat_id` is the IČ DPH and
    | is deliberately empty: samko labs is not VAT-registered, and every page
    | that mentions VAT renders that line only when this value is filled in.
    |
    */

    'operator' => [
        'name' => env('LEGAL_OPERATOR_NAME', 'samko labs, s. r. o.'),
        'address' => env('LEGAL_OPERATOR_ADDRESS', 'Trnková 451/12, 040 14 Košice – mestská časť Košická Nová Ves'),
        'country' => env('LEGAL_OPERATOR_COUNTRY', 'Slovakia'),
        'company_id' => env('LEGAL_OPERATOR_COMPANY_ID', '53928881'),
        'tax_id' => env('LEGAL_OPERATOR_TAX_ID', '2121532600'),
        'vat_id' => env('LEGAL_OPERATOR_VAT_ID', ''),
        'register' => env('LEGAL_OPERATOR_REGISTER', 'the Commercial Register of the Košice City Court, section Sro, insert no. 52051/V'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact Addresses
    |--------------------------------------------------------------------------
    */

    'contact' => [
        'general' => env('LEGAL_CONTACT_GENERAL', 'info@bilis.app'),
        'privacy' => env('LEGAL_CONTACT_PRIVACY', 'info@bilis.app'),
        'security' => env('LEGAL_CONTACT_SECURITY', 'info@bilis.app'),
        'billing' => env('LEGAL_CONTACT_BILLING', 'info@bilis.app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jurisdiction
    |--------------------------------------------------------------------------
    |
    | Governing law and the courts that hear disputes, plus the data
    | protection authority a user can complain to about our handling of
    | their personal data.
    |
    */

    'jurisdiction' => [
        'governing_law' => env('LEGAL_GOVERNING_LAW', 'Slovakia'),
        'courts' => env('LEGAL_COURTS', 'the competent courts of Slovakia'),
        'supervisory_authority' => env(
            'LEGAL_SUPERVISORY_AUTHORITY',
            'Úrad na ochranu osobných údajov Slovenskej republiky (Office for Personal Data Protection of the Slovak Republic)'
        ),
        'supervisory_authority_url' => env('LEGAL_SUPERVISORY_AUTHORITY_URL', 'https://dataprotection.gov.sk'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Dates
    |--------------------------------------------------------------------------
    |
    | Shown at the head of each legal page. Bump these whenever the text
    | changes materially, and tell existing customers before the new terms
    | take effect (the Terms promise 30 days' notice).
    |
    */

    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '1 September 2026'),
    'last_updated' => env('LEGAL_LAST_UPDATED', '26 August 2026'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long the hosted service keeps ingested log data. This number is a
    | promise made in the Privacy Policy, so the ClickHouse table needs a
    | matching TTL - see database/clickhouse/. Keep the two in step.
    |
    */

    'log_retention_days' => (int) env('LEGAL_LOG_RETENTION_DAYS', 30),
    'account_deletion_grace_days' => (int) env('LEGAL_ACCOUNT_DELETION_GRACE_DAYS', 30),
    'backup_retention_days' => (int) env('LEGAL_BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | The hosted service sells through Stripe Managed Payments, so Stripe is
    | the merchant of record - not us. Stripe calculates, collects, files and
    | remits sales tax, VAT and GST in the countries it covers, issues the
    | receipt or invoice, and handles disputes and transaction-level support.
    | Customers see the sale as "Sold through Link" and manage orders and
    | subscriptions at link.com.
    |
    | This is why `operator.vat_id` is empty: under a merchant-of-record
    | arrangement we are not the party charging the customer VAT.
    |
    | https://docs.stripe.com/payments/managed-payments/how-it-works
    |
    */

    'payments' => [
        'merchant_of_record' => env('LEGAL_MERCHANT_OF_RECORD', 'Stripe'),
        'customer_facing_brand' => env('LEGAL_PAYMENTS_BRAND', 'Link'),
        'order_management_url' => env('LEGAL_PAYMENTS_ORDERS_URL', 'https://link.com'),
        'support_url' => env('LEGAL_PAYMENTS_SUPPORT_URL', 'https://support.link.com/topics/sold-through-link'),
        'statement_descriptor_prefix' => env('LEGAL_PAYMENTS_STATEMENT_PREFIX', 'LINK.COM*'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sub-processors
    |--------------------------------------------------------------------------
    |
    | Every third party that may touch customer data, listed publicly in the
    | Privacy Policy. GDPR Article 28 requires notice before this list grows,
    | so adding a row here means emailing customers first.
    |
    */

    'hosting' => $hosting,

    'sub_processors' => [
        [
            'name' => 'OVH SAS',
            'purpose' => 'Server hosting, storage and network for the entire service',
            'location' => $hosting['country'].($hosting['in_eea'] ? ' (EU)' : ''),
            'url' => 'https://www.ovhcloud.com/en/personal-data-protection/',
        ],
        [
            'name' => 'Stripe, Inc. and Stripe Technology Europe, Limited',
            'purpose' => 'Merchant of record for the hosted service: checkout, payment processing, tax, invoicing, refunds, disputes and transaction support',
            'location' => 'Ireland (EU) and United States',
            'url' => 'https://stripe.com/privacy',
        ],
        [
            'name' => 'TODO email provider',
            'purpose' => 'Transactional email (sign-in, verification, password reset, invitations)',
            'location' => 'TODO — choose an EU-hosted provider',
            'url' => '',
        ],
    ],

];
