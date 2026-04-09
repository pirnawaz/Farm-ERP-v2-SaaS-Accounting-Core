<?php

namespace App\Domains\Accounting\Loans;

use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Concerns\GuardsPostedRecordImmutability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanDrawdown extends Model
{
    use GuardsPostedRecordImmutability;
    use HasUuids;

    protected $table = 'loan_drawdowns';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'loan_agreement_id',
        'drawdown_date',
        'amount',
        'reference_no',
        'status',
        'notes',
        'created_by',
        'posting_group_id',
        'posted_at',
    ];

    protected $casts = [
        'drawdown_date' => 'date',
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function loanAgreement(): BelongsTo
    {
        return $this->belongsTo(LoanAgreement::class, 'loan_agreement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }
}
