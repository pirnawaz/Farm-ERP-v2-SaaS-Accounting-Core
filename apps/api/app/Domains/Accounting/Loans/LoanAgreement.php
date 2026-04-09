<?php

namespace App\Domains\Accounting\Loans;

use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanAgreement extends Model
{
    use HasUuids;

    protected $table = 'loan_agreements';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'lender_party_id',
        'reference_no',
        'principal_amount',
        'currency_code',
        'interest_rate_annual',
        'start_date',
        'maturity_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate_annual' => 'decimal:6',
        'start_date' => 'date',
        'maturity_date' => 'date',
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

    public function lenderParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'lender_party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function drawdowns(): HasMany
    {
        return $this->hasMany(LoanDrawdown::class, 'loan_agreement_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class, 'loan_agreement_id');
    }

    public function scheduleLines(): HasMany
    {
        return $this->hasMany(LoanScheduleLine::class, 'loan_agreement_id');
    }
}
