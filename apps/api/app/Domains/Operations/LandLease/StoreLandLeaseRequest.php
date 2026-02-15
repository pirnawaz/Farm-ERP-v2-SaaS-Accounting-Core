<?php

namespace App\Domains\Operations\LandLease;

use Illuminate\Foundation\Http\FormRequest;

class StoreLandLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'tenant_admin';
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'land_parcel_id' => ['required', 'uuid', 'exists:land_parcels,id'],
            'landlord_party_id' => ['required', 'uuid', 'exists:parties,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'rent_amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'string', 'in:MONTHLY'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
