<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use App\Services\Esim\EsimImportConfirmService;
use App\Services\Esim\EsimSingleImportService;
use App\Services\Esim\PhysicalSimSpreadsheetParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EsimImportBatchController extends Controller
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
        private readonly EsimImportConfirmService $confirmService,
        private readonly PhysicalSimSpreadsheetParser $spreadsheetParser,
    ) {}

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

        if ($batch->isPhysical()) {
            return response()->json([
                'success' => false,
                'message' => 'Physical batches must be imported from a spreadsheet (.xlsx, .xls, or .csv).',
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
            $result = DB::transaction(function () use ($batch, $item, $validated) {
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

    /**
     * Parse one page/image and return a preview without saving to inventory or Vodacom.
     */
    public function previewItem(Request $request, EsimImportBatch $batch): JsonResponse
    {
        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is no longer accepting items.',
            ], 422);
        }

        if ($batch->isPhysical()) {
            return response()->json([
                'success' => false,
                'message' => 'Physical batches must be imported from a spreadsheet (.xlsx, .xls, or .csv).',
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
            'status' => EsimImportItem::STATUS_PROCESSING,
        ]);

        try {
            $extracted = $this->importService->extract(
                $batch,
                $item,
                $validated['file'],
                $validated['phone_number'] ?? null,
                $validated['iccid'] ?? null,
            );

            $item->update([
                'status' => EsimImportItem::STATUS_PENDING,
                'phone_number' => $extracted['phone_number'],
                'iccid' => $extracted['iccid'],
                'error_message' => null,
            ]);

            $batch->markProcessing();

            return response()->json([
                'success' => true,
                'preview' => [
                    'phone_number' => $extracted['phone_number'],
                    'iccid' => $extracted['iccid'],
                    'qr_code_data' => $extracted['qr_code_data'],
                    'qr_image_base64' => $extracted['qr_image_base64'],
                    'network_id' => Esim::defaultNetworkId(),
                ],
                'item' => $item->fresh()->toResponseArray(),
                'batch' => $batch->fresh()->toSummaryArray(),
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('eSIM batch item preview failed', [
                'batch_id' => $batch->id,
                'item_id' => $item->id,
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

    /**
     * Upload an Excel/CSV file for a physical batch and create pending review items.
     */
    public function uploadSpreadsheet(Request $request, EsimImportBatch $batch): JsonResponse
    {
        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is no longer accepting items.',
            ], 422);
        }

        if (! $batch->isPhysical()) {
            return response()->json([
                'success' => false,
                'message' => 'Spreadsheet upload is only supported for physical SIM batches.',
            ], 422);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $extension = strtolower($validated['file']->getClientOriginalExtension() ?: '');
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Physical SIM import accepts .xlsx, .xls, or .csv files only.',
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }

        try {
            $rows = $this->spreadsheetParser->parse($validated['file']);
            $sourcePath = $this->confirmService->storeSpreadsheetSource($batch, $validated['file']);
            $items = $this->confirmService->createPendingItemsFromRows($batch, $rows, $sourcePath);

            $defaultNetworkId = Esim::defaultNetworkId();

            return response()->json([
                'success' => true,
                'message' => count($items).' physical SIM rows ready for review.',
                'batch' => $batch->fresh()->toSummaryArray(),
                'items' => collect($items)->map(fn (EsimImportItem $item) => [
                    'item' => $item->toResponseArray(),
                    'preview' => [
                        'phone_number' => $item->phone_number,
                        'iccid' => $item->iccid,
                        'qr_code_data' => null,
                        'qr_image_base64' => null,
                        'network_id' => $defaultNetworkId,
                    ],
                ])->values()->all(),
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('Physical SIM spreadsheet upload failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'batch' => $batch->fresh()->toSummaryArray(),
            ], 422);
        }
    }

    /**
     * Confirm and provision every pending/failed item without stepping through review.
     */
    public function confirmAll(EsimImportBatch $batch): JsonResponse
    {
        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is closed.',
            ], 422);
        }

        if (! $batch->isPhysical()) {
            return response()->json([
                'success' => false,
                'message' => 'Skip review is only supported for physical SIM spreadsheet batches.',
            ], 422);
        }

        $result = $this->confirmService->confirmAll($batch);

        return response()->json([
            'success' => $result['failed'] === 0,
            'message' => sprintf(
                'Skip review finished: %d saved to inventory, %d failed.',
                $result['completed'],
                $result['failed']
            ),
            'completed' => $result['completed'],
            'failed' => $result['failed'],
            'items' => $result['items'],
            'batch' => $batch->fresh()->toSummaryArray(),
        ], $result['failed'] === 0 ? 200 : 422);
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
