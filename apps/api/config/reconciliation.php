<?php

return [

    /*
    |--------------------------------------------------------------------------
    | COGS account codes (settlement pool reconciliation)
    |--------------------------------------------------------------------------
    |
    | These expense account codes are excluded from "settlement pool" totals
    | when reconciling settlement vs OT. COGS is posted to ledger with sales
    | but does not appear as a separate operational transaction line, so
    | including it would cause reconciliation deltas. Project P&L reports
    | still include COGS; only reconciliation checks use this exclusion.
    |
    */
    'cogs_account_codes' => [
        'COGS_PRODUCE',
    ],

];
