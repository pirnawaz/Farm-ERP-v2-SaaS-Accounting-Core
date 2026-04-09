<?php

namespace App\Domains\Commercial\Payables;

use App\Models\InvGrn;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Concerns\GuardsPostedRecordImmutability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    use GuardsPostedRecordImmutability;
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_PAID = 'PAID';

    protected $table = 'supplier_invoices';

    protected $fillable = [
        'tenant_id',
        'party_id',
        'project_id',
        'grn_id',
        'reference_no',
        'invoice_date',
        'currency_code',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'status',
        'posting_group_id',
        'paid_at',
        'notes',
        'created_by',
        'posted_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(InvGrn::class, 'grn_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'supplier_invoice_id');
    }

    public function supplierPaymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class, 'supplier_invoice_id');
    }
}
