<?php

namespace App\Domains\Operations\LandLease;

use App\Models\LandParcel;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandLease extends Model
{
    use HasUuids;

    protected $table = 'land_leases';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'land_parcel_id',
        'landlord_party_id',
        'start_date',
        'end_date',
        'rent_amount',
        'frequency',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'rent_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const FREQUENCY_MONTHLY = 'MONTHLY';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class);
    }

    public function landlordParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'landlord_party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(LandLeaseAccrual::class, 'lease_id');
    }
}
