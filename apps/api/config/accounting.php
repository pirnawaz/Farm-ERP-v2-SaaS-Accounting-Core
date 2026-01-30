<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deprecated account codes (guardrails)
    |--------------------------------------------------------------------------
    |
    | New ledger entries must NOT be posted to these accounts. Party balances
    | use PARTY_CONTROL_*; settlement uses PROFIT_DISTRIBUTION_CLEARING.
    | Historical data and correction/consolidation commands are unchanged.
    |
    */
    'deprecated_codes' => [
        // Legacy party balance accounts (consolidated into PARTY_CONTROL_*)
        'ADVANCE_HARI',
        'DUE_FROM_HARI',
        'PAYABLE_HARI',
        'PAYABLE_LANDLORD',
        'ADVANCE_LANDLORD',
        'DUE_FROM_LANDLORD',
        'PAYABLE_KAMDAR',
        'ADVANCE_KAMDAR',
        'DUE_FROM_KAMDAR',
        // Superseded by PROFIT_DISTRIBUTION_CLEARING for settlement postings
        'PROFIT_DISTRIBUTION',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crop cycle close gating
    |--------------------------------------------------------------------------
    |
    | close_cycle_block_on_fail: when true, close is blocked if any reconciliation
    | check has status FAIL. close_cycle_allow_on_warn: when true, close is
    | allowed when only WARN checks exist (no FAIL). Kept for future use.
    |
    */
    'close_cycle_block_on_fail' => true,
    'close_cycle_allow_on_warn' => true,

];
