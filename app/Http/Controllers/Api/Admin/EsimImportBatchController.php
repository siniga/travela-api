<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use App\Services\EsimSingleImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EsimImportBatchController extends Controller
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'total_items' => ['required', 'integer', 'min:1', 'max:10000'],
            'sim_type' => [
                'required',
                'string',
                Rule::in([EsimImportBatch::SIM_TYPE_ESIM, EsimImportBatch::SIM_TYPE_PHYSICAL]),
            ],
        ]);

        $batch = EsimImportBatch::query()->create([
            'name' => $validated['name'] ?? null,
            'sim_type' => $validated['sim_type'],
            'total_items' => $validated['total_items'],
            'created_by' => $request->user()?->id,
            'status' => EsimImportBatch::STATUS_PENDING,
        ]);

        return response()->json([
            'success' => true,
            'batch' => $batch->toSummaryArray(),
        ], 201);
    }

    public function show(EsimImportBatch $batch): JsonResponse
    {
        $batch->load(['items.esim']);

        return response()->json([
            'success' => true,
            'batch' => array_merge($batch->toSummaryArray(), [
                'items' => $batch->items->map(fn (EsimImportItem $item) => array_merge(
                    $item->toResponseArray(),
                    ['esim' => $item->esim?->toImportApiArray()],
                )),
            ]),
        ]);
    }

    public function storeItem(Request $request, EsimImportBatch $batch): JsonResponse
    {
        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is no longer accepting items.',
            ], 422);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'page_number' => ['nullable', 'integer', 'min:1'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'iccid' => ['nullable', 'string', 'max:50'],
        ]);

        $item = EsimImportItem::query()->create([
            'esim_import_batch_id' => $batch->id,
            'page_number' => $validated['page_number'] ?? null,
            'phone_number' => isset($validated['phone_number'])
                ? Esim::normalizeMsisdn($validated['phone_number'])
                : null,
            'iccid' => isset($validated['iccid']) ? strtoupper(trim($validated['iccid'])) : null,
            'status' => EsimImportItem::STATUS_PROCESSING,
        ]);

        try {
            $result = DB::transaction(function () use ($batch, $item, $validated, $request) {
                $result = $this->importService->process(
                    $batch,
                    $item,
                    $validated['file'],
                    $validated['phone_number'] ?? null,
                    $validated['iccid'] ?? null,
                );

                $esim = $result['esim'];

                $item->update([
                    'status' => EsimImportItem::STATUS_COMPLETED,
                    'esim_id' => $esim->id,
                    'phone_number' => $esim->msisdn,
                    'iccid' => $esim->iccid,
                    'error_message' => null,
                ]);

                $batch->recordItemSuccess();
                $batch->refresh();

                return $result;
            });

            return response()->json([
                'success' => true,
                'item' => $item->fresh()->toResponseArray(),
                'esim' => $result['esim']->toImportApiArray(),
                'batch' => $batch->fresh()->toSummaryArray(),
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('eSIM batch item import failed', [
                'batch_id' => $batch->id,
                'item_id' => $item->id,
                'page_number' => $item->page_number,
                'error' => $e->getMessage(),
            ]);

            $item->update([
                'status' => EsimImportItem::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $batch->recordItemFailure();
            $batch->refresh();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'item' => $item->fresh()->toResponseArray(),
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }
    }

    public function previewItem(Request $request, EsimImportBatch $batch): JsonResponse
    {
        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is no longer accepting items.',
            ], 422);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'page_number' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = EsimImportItem::query()->create([
            'esim_import_batch_id' => $batch->id,
            'page_number' => $validated['page_number'] ?? null,
            'status' => EsimImportItem::STATUS_PENDING,
        ]);

        try {
            $this->importService->storePreview($batch, $item, $validated['file']);
            $extracted = $this->importService->extract($validated['file']);
            $preview = $this->importService->toPreviewArray($extracted);

            $item->update([
                'phone_number' => $preview['phone_number'],
                'iccid' => $preview['iccid'],
            ]);

            $batch->markProcessing();

            return response()->json([
                'success' => true,
                'item' => $item->fresh()->toResponseArray(),
                'preview' => $preview,
                'batch' => $batch->fresh()->toSummaryArray(),
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('eSIM batch item preview failed', [
                'batch_id' => $batch->id,
                'item_id' => $item->id,
                'page_number' => $item->page_number,
                'error' => $e->getMessage(),
            ]);

            $item->update([
                'status' => EsimImportItem::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $batch->recordItemFailure();
            $batch->refresh();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'item' => $item->fresh()->toResponseArray(),
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }
    }

    public function finish(EsimImportBatch $batch): JsonResponse
    {
        $batch->refresh();

        $handled = $batch->processed_items + $batch->failed_items;

        if ($batch->total_items > 0 && $handled < $batch->total_items) {
            return response()->json([
                'success' => false,
                'message' => 'Batch is not fully processed yet.',
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }

        $batch->update([
            'status' => EsimImportBatch::STATUS_COMPLETED,
            'completed_at' => now(),
            'started_at' => $batch->started_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'batch' => $batch->fresh()->toSummaryArray(),
        ]);
    }
}
