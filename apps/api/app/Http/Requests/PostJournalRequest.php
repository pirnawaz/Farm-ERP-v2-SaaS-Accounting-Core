<?php

namespace App\Http\Requests;

use App\Models\JournalEntry;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/journals/{id}/post
 *
 * Ledger posting_date is not accepted on this endpoint: it is derived from the journal's entry_date
 * (see JournalEntryService::postJournal). Request body must not include posting_date.
 */
class PostJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tenantId = TenantContext::getTenantId($this);
            if (! $tenantId) {
                return;
            }
            $id = $this->route('id');
            if (! $id) {
                return;
            }
            $journal = JournalEntry::where('id', $id)->where('tenant_id', $tenantId)->first();
            if (! $journal) {
                return;
            }
            if ($journal->entry_date === null) {
                $validator->errors()->add(
                    'entry_date',
                    'Journal entry_date must be set to post; ledger posting_date is derived from entry_date.'
                );
            }
        });
    }
}
