<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'allocation_mode' => ['nullable', 'string', 'in:FIFO,MANUAL'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.sale_id' => ['required_with:allocations', 'uuid', 'exists:sales,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }
}
