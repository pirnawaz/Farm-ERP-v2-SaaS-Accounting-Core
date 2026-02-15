<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostLandLeaseAccrualRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user) {
            return $user->role === 'tenant_admin';
        }
        $role = $this->header('X-User-Role');
        return $role === 'tenant_admin';
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
        ];
    }
}
