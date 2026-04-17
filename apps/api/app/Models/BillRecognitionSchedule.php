<?php

namespace App\Models;

use App\Domains\Commercial\Payables\SupplierInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillRecognitionSchedule extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_DEFERRAL_POSTED = 'DEFERRAL_POSTED';

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $table = 'bill_recognition_schedules';

    protected $fillable = [
        'tenant_id',
        'supplier_invoice_id',
        'treatment',
        'start_date',
        'end_date',
        'frequency',
        'total_amount',
        'status',
        'deferral_posting_group_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillRecognitionScheduleLine::class, 'bill_recognition_schedule_id');
    }

    public function deferralPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'deferral_posting_group_id');
    }
}
