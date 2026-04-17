<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('source_account_id') && ($this->input('source_account_id') === '' || $this->input('source_account_id') === 'null')) {
            $this->merge(['source_account_id' => null]);
        }
    }

    public function rules(): array
    {
        $tenantId = TenantContext::getTenantId($this);

        return [
            'direction' => ['sometimes', 'required', 'string', 'in:IN,OUT'],
            'party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'payment_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'method' => ['sometimes', 'required', 'string', 'in:CASH,BANK'],
            'source_account_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where('tenant_id', $tenantId),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'settlement_id' => ['nullable', 'uuid', 'exists:settlements,id'],
            'notes' => ['nullable', 'string'],
            'purpose' => ['nullable', 'string', 'in:GENERAL,WAGES'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('direction') === 'IN' && $this->filled('source_account_id')) {
                $validator->errors()->add(
                    'source_account_id',
                    'Treasury source account is only supported for outgoing (supplier) payments.'
                );
            }
            if (! $this->filled('source_account_id')) {
                return;
            }
            $tenantId = TenantContext::getTenantId($this);
            if (! $tenantId) {
                return;
            }
            $account = Account::where('tenant_id', $tenantId)->where('id', $this->input('source_account_id'))->first();
            if ($account && strtolower((string) $account->type) !== 'asset') {
                $validator->errors()->add('source_account_id', 'Treasury source must be an asset (bank/cash) account.');
            }
        });
    }
}
