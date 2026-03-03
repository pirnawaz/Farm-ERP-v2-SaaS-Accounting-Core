<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\PeriodClose\PeriodCloseService;
use App\Models\CropCycle;
use App\Models\FieldBlock;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Project;
use App\Models\TenantCropItem;
use App\Services\CropCycleCloseService;
use App\Services\SystemPartyService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CropCycleController extends Controller
{
    public function __construct(
        private CropCycleCloseService $closeService,
        private PeriodCloseService $periodCloseService,
        private SystemPartyService $systemPartyService
    ) {}
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycles = CropCycle::where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem')
            ->orderBy('start_date', 'desc')
            ->get();

        $data = $cycles->map(fn (CropCycle $c) => $this->cycleToArray($c));
        return response()->json($data)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tenant_crop_item_id' => ['required', 'uuid', Rule::exists('tenant_crop_items', 'id')->where('tenant_id', $tenantId)],
            'crop_variety_id' => ['nullable', 'uuid', Rule::exists('crop_varieties', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if ($request->filled('crop_variety_id')) {
            $variety = \App\Models\CropVariety::where('id', $request->crop_variety_id)
                ->where('tenant_id', $tenantId)
                ->where('tenant_crop_item_id', $request->tenant_crop_item_id)
                ->first();
            if (!$variety) {
                throw ValidationException::withMessages([
                    'crop_variety_id' => ['The selected crop variety must belong to the selected crop item.'],
                ])->status(422);
            }
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'tenant_crop_item_id' => $request->tenant_crop_item_id,
            'start_date' => $request->start_date,
            'status' => 'OPEN',
        ];
        $data['crop_variety_id'] = $request->filled('crop_variety_id') ? $request->crop_variety_id : null;
        if ($request->filled('end_date')) {
            $data['end_date'] = $request->end_date;
        }
        $cycle = CropCycle::create($data);
        $cycle->load('tenantCropItem.cropCatalogItem');

        return response()->json($this->cycleToArray($cycle), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem', 'cropVariety')
            ->firstOrFail();

        return response()->json($this->cycleToArray($cycle));
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tenant_crop_item_id' => ['sometimes', 'required', 'uuid', Rule::exists('tenant_crop_items', 'id')->where('tenant_id', $tenantId)],
            'crop_variety_id' => [
                'nullable',
                'uuid',
                Rule::exists('crop_varieties', 'id')->where('tenant_id', $tenantId),
            ],
            'start_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if ($request->filled('crop_variety_id')) {
            $cropItemId = $request->filled('tenant_crop_item_id') ? $request->tenant_crop_item_id : $cycle->tenant_crop_item_id;
            $variety = \App\Models\CropVariety::where('id', $request->crop_variety_id)
                ->where('tenant_id', $tenantId)
                ->where('tenant_crop_item_id', $cropItemId)
                ->first();
            if (!$variety) {
                throw ValidationException::withMessages([
                    'crop_variety_id' => ['The selected crop variety must belong to the selected crop item.'],
                ])->status(422);
            }
        }

        $data = $request->only(['name', 'start_date', 'end_date', 'tenant_crop_item_id', 'crop_variety_id']);
        if (array_key_exists('end_date', $data) && $data['end_date'] === '') {
            $data['end_date'] = null;
        }
        if (array_key_exists('crop_variety_id', $data) && ($data['crop_variety_id'] === '' || $data['crop_variety_id'] === null)) {
            $data['crop_variety_id'] = null;
        }
        $cycle->update($data);
        $cycle->load('tenantCropItem.cropCatalogItem', 'cropVariety');

        return response()->json($this->cycleToArray($cycle));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $cycle->delete();

        return response()->json(null, 204);
    }

    public function closePreview(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $preview = $this->closeService->previewClose($id, $tenantId);

        return response()->json($preview);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'as_of' => ['nullable', 'date', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $userId = $request->user()?->id;
        $asOf = $request->input('as_of');

        try {
            $result = $this->periodCloseService->closeCropCycle($tenantId, $id, $userId, $asOf);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['crop_cycle' => [$e->getMessage()]])->status(422);
        }

        $run = $result['close_run'];
        return response()->json([
            'crop_cycle_id' => $result['crop_cycle']->id,
            'status' => $result['crop_cycle']->status,
            'posting_group_id' => $result['posting_group_id'],
            'net_profit' => $result['net_profit'],
            'closed_at' => $result['closed_at'],
            'closed_by_user_id' => $result['closed_by_user_id'],
        ]);
    }

    public function closeRun(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $run = $this->periodCloseService->getCloseRun($tenantId, $id);

        if (!$run) {
            return response()->json(['message' => 'No close run for this crop cycle.'], 404);
        }

        return response()->json($run->load(['postingGroup']));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = $this->closeService->reopen($id, $tenantId);

        return response()->json($cycle);
    }

    public function open(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $cycle->update(['status' => 'OPEN']);
        $cycle->load('tenantCropItem.cropCatalogItem');

        return response()->json($this->cycleToArray($cycle));
    }

    /**
     * POST /api/crop-cycles/{id}/season-setup
     * Assign fields (land parcels) to this crop cycle; creates one field block and one project per block.
     * One LandAllocation per (cycle, parcel); multiple FieldBlocks and Projects per parcel when advanced.
     * Cycle must be OPEN. Idempotent: re-posting same payload does not create duplicates.
     */
    public function seasonSetup(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem')
            ->firstOrFail();

        if ($cycle->status !== 'OPEN') {
            throw ValidationException::withMessages([
                'crop_cycle' => ['Crop cycle must be OPEN to assign fields.'],
            ])->status(422);
        }

        $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.land_parcel_id' => ['required', 'uuid', Rule::exists('land_parcels', 'id')->where('tenant_id', $tenantId)],
            'assignments.*.blocks' => ['required', 'array', 'min:1'],
            'assignments.*.blocks.*.tenant_crop_item_id' => ['required', 'uuid', Rule::exists('tenant_crop_items', 'id')->where('tenant_id', $tenantId)],
            'assignments.*.blocks.*.name' => ['nullable', 'string', 'max:255'],
            'assignments.*.blocks.*.area' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $landlord = $this->systemPartyService->ensureSystemLandlordParty($tenantId);
        $created = [];
        $fieldBlocksCount = 0;

        DB::transaction(function () use ($tenantId, $cycle, $request, $landlord, &$created, &$fieldBlocksCount) {
            foreach ($request->input('assignments') as $assignment) {
                $parcel = LandParcel::where('id', $assignment['land_parcel_id'])
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                $allocatedAcres = max(0.01, (float) ($parcel->total_acres ?? 0));
                $allocation = LandAllocation::firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'crop_cycle_id' => $cycle->id,
                        'land_parcel_id' => $parcel->id,
                        'party_id' => null,
                    ],
                    ['allocated_acres' => $allocatedAcres]
                );

                $blocks = $this->normalizeBlockNamesForIdempotency($assignment['blocks']);
                foreach ($blocks as $block) {
                    $cropItem = TenantCropItem::where('id', $block['tenant_crop_item_id'])
                        ->where('tenant_id', $tenantId)
                        ->with('cropCatalogItem')
                        ->firstOrFail();
                    $cropName = $cropItem->resolved_display_name ?: 'Crop';
                    $blockName = isset($block['name']) && (string) $block['name'] !== '' ? (string) $block['name'] : null;
                    $blockArea = isset($block['area']) && $block['area'] > 0 ? (float) $block['area'] : null;

                    $fieldBlock = FieldBlock::firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'crop_cycle_id' => $cycle->id,
                            'land_parcel_id' => $parcel->id,
                            'tenant_crop_item_id' => $block['tenant_crop_item_id'],
                            'name' => $blockName,
                        ],
                        ['area' => $blockArea]
                    );
                    if ($fieldBlock->wasRecentlyCreated) {
                        $fieldBlocksCount++;
                    }
                    if ($fieldBlock->area !== $blockArea && $blockArea !== null) {
                        $fieldBlock->update(['area' => $blockArea]);
                    }

                    $projectName = $parcel->name . ' – ' . $cropName . ($blockName ? ' (' . $blockName . ')' : '');
                    $project = Project::firstOrCreate(
                        [
                            'field_block_id' => $fieldBlock->id,
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'name' => $projectName,
                            'party_id' => $landlord->id,
                            'crop_cycle_id' => $cycle->id,
                            'land_allocation_id' => $allocation->id,
                            'status' => 'ACTIVE',
                        ]
                    );
                    if (!$project->wasRecentlyCreated && $project->name !== $projectName) {
                        $project->update(['name' => $projectName]);
                    }

                    $created[] = [
                        'field_block_id' => $fieldBlock->id,
                        'project_id' => $project->id,
                        'name' => $project->name,
                        'land_parcel_id' => $parcel->id,
                        'land_allocation_id' => $allocation->id,
                    ];
                }
            }
        });

        return response()->json([
            'crop_cycle_id' => $cycle->id,
            'field_blocks_created' => $fieldBlocksCount,
            'projects_created' => count($created),
            'projects' => $created,
        ], 201);
    }

    /**
     * Ensure multiple blocks with same crop but no name get synthetic names so idempotency key is unique.
     */
    private function normalizeBlockNamesForIdempotency(array $blocks): array
    {
        $byCrop = [];
        foreach ($blocks as $i => $block) {
            $cid = $block['tenant_crop_item_id'];
            if (!isset($byCrop[$cid])) {
                $byCrop[$cid] = [];
            }
            $byCrop[$cid][] = $i;
        }
        $normalized = $blocks;
        foreach ($byCrop as $indices) {
            if (count($indices) <= 1) {
                continue;
            }
            $unnamed = 0;
            foreach ($indices as $idx) {
                $name = $normalized[$idx]['name'] ?? null;
                if ($name === null || (string) $name === '') {
                    $unnamed++;
                    $normalized[$idx]['name'] = (string) $unnamed;
                }
            }
        }
        return $normalized;
    }

    private function cycleToArray(CropCycle $cycle): array
    {
        $item = $cycle->tenantCropItem;
        $displayName = null;
        if ($item) {
            $displayName = $item->display_name !== null && $item->display_name !== ''
                ? $item->display_name
                : ($item->cropCatalogItem ? $item->cropCatalogItem->default_name : $item->custom_name);
        }
        $arr = $cycle->toArray();
        $arr['crop_display_name'] = $displayName;
        return $arr;
    }
}
