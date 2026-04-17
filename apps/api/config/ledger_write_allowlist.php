<?php

/**
 * Allowlist: only these classes may open a LedgerWriteGuard context that permits LedgerEntry::create().
 *
 * Maintenance commands are listed here but MUST also enforce local/dev-only execution in their handle() methods.
 *
 * @see \App\Services\LedgerWriteGuard
 */
return [
    /*
     * When true, LedgerEntry::create() is allowed without an active guard (used by PHPUnit fixture code).
     * Set from tests/TestCase; never enable in production.
     */
    'allow_unguarded_in_tests' => false,

    'classes' => [
        // Core posting services
        \App\Services\AdvanceService::class,
        \App\Services\CropActivityPostingService::class,
        \App\Services\FieldJobPostingService::class,
        \App\Services\HarvestService::class,
        \App\Services\InventoryPostingService::class,
        \App\Services\JournalEntryService::class,
        \App\Services\LabourPostingService::class,
        \App\Services\Machinery\MachineMaintenancePostingService::class,
        \App\Services\Machinery\MachineryChargePostingService::class,
        \App\Services\Machinery\MachineryExternalIncomePostingService::class,
        \App\Services\Machinery\MachineryPostingService::class,
        \App\Services\Machinery\MachineryServicePostingService::class,
        \App\Services\PaymentService::class,
        \App\Services\PostingService::class,
        \App\Services\ReversalService::class,
        \App\Services\SaleCOGSService::class,
        \App\Services\SaleService::class,
        \App\Services\SettlementService::class,

        // Domain posting services
        \App\Domains\Accounting\FixedAssets\FixedAssetActivationPostingService::class,
        \App\Domains\Accounting\FixedAssets\FixedAssetDisposalPostingService::class,
        \App\Domains\Accounting\FixedAssets\FixedAssetDepreciationPostingService::class,
        \App\Domains\Accounting\Loans\LoanDrawdownPostingService::class,
        \App\Domains\Accounting\Loans\LoanRepaymentPostingService::class,
        \App\Domains\Accounting\PeriodClose\PeriodCloseService::class,
        \App\Domains\Commercial\Payables\SupplierInvoicePostingService::class,
        \App\Domains\Commercial\Payables\SupplierCreditNotePostingService::class,
        \App\Services\OverheadAllocationService::class,
        \App\Services\BillRecognitionService::class,
        \App\Domains\Operations\LandLease\LandLeaseAccrualPostingService::class,
        \App\Domains\Accounting\MultiCurrency\FxRevaluationPostingService::class,

        // One-off maintenance / migration commands (must self-enforce environment in handle())
        \App\Console\Commands\ConsolidatePartyControls::class,
        \App\Console\Commands\FixSettlementPostings::class,
        \App\Console\Commands\ReclassifyLegacyPartyOnlyExpenses::class,
    ],
];
