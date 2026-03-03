<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvItem extends Model
{
    use HasUuids;

    protected $table = 'inv_items';

    protected $fillable = [
        'tenant_id',
        'name',
        'sku',
        'category_id',
        'uom_id',
        'valuation_method',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvItemCategory::class, 'category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(InvUom::class, 'uom_id');
    }

    public function grnLines(): HasMany
    {
        return $this->hasMany(InvGrnLine::class, 'item_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(InvIssueLine::class, 'item_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InvStockBalance::class, 'item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(InvStockMovement::class, 'item_id');
    }

    public function adjustmentLines(): HasMany
    {
        return $this->hasMany(InvAdjustmentLine::class, 'item_id');
    }

    public function transferLines(): HasMany
    {
        return $this->hasMany(InvTransferLine::class, 'item_id');
    }

    public function harvestLines(): HasMany
    {
        return $this->hasMany(HarvestLine::class, 'inventory_item_id');
    }

    public function cropActivityInputs(): HasMany
    {
        return $this->hasMany(CropActivityInput::class, 'item_id');
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'inventory_item_id');
    }

    public function saleInventoryAllocations(): HasMany
    {
        return $this->hasMany(SaleInventoryAllocation::class, 'inventory_item_id');
    }

    public function machineryServicesAsInKind(): HasMany
    {
        return $this->hasMany(MachineryService::class, 'in_kind_item_id');
    }

    /** Relation names used for withCount (snake_case + _count). */
    protected static function usageCountAttributes(): array
    {
        return [
            'grn_lines_count', 'issue_lines_count', 'transfer_lines_count', 'adjustment_lines_count',
            'stock_balances_count', 'stock_movements_count', 'harvest_lines_count', 'crop_activity_inputs_count',
            'sale_lines_count', 'sale_inventory_allocations_count', 'machinery_services_as_in_kind_count',
        ];
    }

    /** True if item has no transactions/usages anywhere (safe to delete). */
    public function isUnused(): bool
    {
        foreach (static::usageCountAttributes() as $attr) {
            if (array_key_exists($attr, $this->getAttributes()) && (int) $this->getAttribute($attr) > 0) {
                return false;
            }
        }
        return !$this->grnLines()->exists()
            && !$this->issueLines()->exists()
            && !$this->transferLines()->exists()
            && !$this->adjustmentLines()->exists()
            && !$this->stockBalances()->exists()
            && !$this->stockMovements()->exists()
            && !$this->harvestLines()->exists()
            && !$this->cropActivityInputs()->exists()
            && !$this->saleLines()->exists()
            && !$this->saleInventoryAllocations()->exists()
            && !$this->machineryServicesAsInKind()->exists();
    }
}
