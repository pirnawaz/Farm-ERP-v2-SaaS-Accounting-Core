<?php

namespace App\Domains\Operations\LandLease;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLandLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'tenant_admin';
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'uuid', 'exists:projects,id'],
            'land_parcel_id' => ['sometimes', 'uuid', 'exists:land_parcels,id'],
            'landlord_party_id' => ['sometimes', 'uuid', 'exists:parties,id'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date'],
            'rent_amount' => ['sometimes', 'numeric', 'min:0'],
            'frequency' => ['sometimes', 'string', 'in:MONTHLY'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
