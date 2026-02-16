<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReverseJournalRequest;
use App\Http\Requests\StoreJournalRequest;
use App\Http\Requests\UpdateJournalRequest;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    public function __construct(
        private JournalEntryService $journalService
    ) {}

    /**
     * POST /api/journals
     */
    public function store(StoreJournalRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $lines = $request->input('lines', []);
        $createdBy = $request->user()?->id;

        try {
            $journal = $this->journalService->createDraft(
                $tenantId,
                $request->input('entry_date'),
                $request->input('memo'),
                $lines,
                $createdBy
            );
            return response()->json($journal->load('lines.account'), 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/journals?from=&to=&status=&q=&limit=&offset=
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = JournalEntry::where('tenant_id', $tenantId)
            ->with(['postingGroup', 'reversalPostingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('from')) {
            $query->where('entry_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('entry_date', '<=', $request->input('to'));
        }
        if ($request->filled('q')) {
            $q = '%' . $request->input('q') . '%';
            $query->where(function ($qry) use ($q) {
                $qry->whereRaw('journal_number ILIKE ?', [$q])
                    ->orWhereRaw('memo ILIKE ?', [$q]);
            });
        }

        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $offset = max((int) $request->input('offset', 0), 0);
        $items = $query->orderBy('entry_date', 'desc')->orderBy('created_at', 'desc')
            ->offset($offset)->limit($limit)
            ->get();

        return response()->json($items);
    }

    /**
     * GET /api/journals/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $journal = JournalEntry::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['lines.account', 'postingGroup', 'reversalPostingGroup'])
            ->firstOrFail();

        $payload = $journal->toArray();
        $payload['total_debits'] = $journal->lines->sum('debit_amount');
        $payload['total_credits'] = $journal->lines->sum('credit_amount');
        return response()->json($payload);
    }

    /**
     * PUT /api/journals/{id} — DRAFT only.
     */
    public function update(UpdateJournalRequest $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $journal = JournalEntry::where('id', $id)->where('tenant_id', $tenantId)->first();
        if (!$journal) {
            return response()->json(['message' => 'Journal not found.'], 404);
        }
        if (!$journal->canEdit()) {
            return response()->json(
                ['message' => 'Only DRAFT journals can be updated.'],
                409
            );
        }

        try {
            $data = $request->only(['entry_date', 'memo']);
            $journal = $this->journalService->updateDraft(
                $id,
                $tenantId,
                $data,
                $request->input('lines', [])
            );
            return response()->json($journal->load('lines.account'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/journals/{id}/post
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $postedBy = $request->user()?->id;

        try {
            $postingGroup = $this->journalService->postJournal($id, $tenantId, $postedBy);
            $journal = JournalEntry::where('id', $id)->where('tenant_id', $tenantId)
                ->with(['lines.account', 'postingGroup'])->firstOrFail();
            return response()->json([
                'journal' => $journal,
                'posting_group' => $postingGroup,
            ]);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode() === 422 ? 422 : 409;
            return response()->json(['message' => $e->getMessage()], $code);
        }
    }

    /**
     * POST /api/journals/{id}/reverse
     */
    public function reverse(ReverseJournalRequest $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        try {
            $reversalPg = $this->journalService->reverseJournal(
                $id,
                $tenantId,
                $request->input('reversal_date'),
                $request->input('memo')
            );
            $journal = JournalEntry::where('id', $id)->where('tenant_id', $tenantId)
                ->with(['lines.account', 'postingGroup', 'reversalPostingGroup'])->firstOrFail();
            return response()->json([
                'journal' => $journal,
                'reversal_posting_group' => $reversalPg,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
