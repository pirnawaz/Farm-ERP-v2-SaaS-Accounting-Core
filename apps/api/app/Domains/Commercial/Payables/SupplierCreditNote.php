<?php

namespace App\Domains\Commercial\Payables;

use App\Models\CostCenter;
use App\Models\InvGrn;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Support\Concerns\GuardsPostedRecordImmutability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCreditNote extends Model
{
    use GuardsPostedRecordImmutability;
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    protected $table = 'supplier_credit_notes';

    protected $fillable = [
        'tenant_id',
        'party_id',
        'supplier_invoice_id',
        'inv_grn_id',
        'project_id',
        'cost_center_id',
        'reference_no',
        'credit_date',
        'currency_code',
        'total_amount',
        'status',
        'posting_group_id',
        'posted_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'credit_date' => 'date',
        'total_amount' => 'decimal:2',
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

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function invGrn(): BelongsTo
    {
        return $this->belongsTo(InvGrn::class, 'inv_grn_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }
}
