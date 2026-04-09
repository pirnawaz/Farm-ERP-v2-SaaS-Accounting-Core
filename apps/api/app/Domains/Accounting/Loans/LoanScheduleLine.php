<?php

namespace App\Domains\Accounting\Loans;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanScheduleLine extends Model
{
    use HasUuids;

    protected $table = 'loan_schedule_lines';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'loan_agreement_id',
        'line_number',
        'due_date',
        'principal_amount',
        'interest_amount',
        'total_amount',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'due_date' => 'date',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
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
}
