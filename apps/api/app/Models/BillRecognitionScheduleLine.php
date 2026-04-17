<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillRecognitionScheduleLine extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_POSTED = 'POSTED';

    protected $table = 'bill_recognition_schedule_lines';

    protected $fillable = [
        'tenant_id',
        'bill_recognition_schedule_id',
        'period_no',
        'period_start',
        'period_end',
        'amount',
        'recognition_due_date',
        'status',
        'recognition_posting_group_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'recognition_due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BillRecognitionSchedule::class, 'bill_recognition_schedule_id');
    }

    public function recognitionPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'recognition_posting_group_id');
    }
}
