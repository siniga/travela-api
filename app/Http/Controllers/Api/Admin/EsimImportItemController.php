<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use App\Services\EsimSingleImportService;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EsimImportItemController extends Controller
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    public function confirm(Request $request, EsimImportItem $item): JsonResponse
    {
        if ($item->status !== EsimImportItem::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending items awaiting review can be confirmed.',
            ], 422);
        }

        $batch = $item->batch;
        if (! $batch) {
            return response()->json([
                'success' => false,
                'message' => 'Import batch not found for this item.',
            ], 404);
        }

        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is closed.',
            ], 422);
        }

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:30'],
            'iccid' => ['nullable', 'string', 'max:50'],
        ]);

        $phoneNumber = Esim::normalizeMsisdn($validated['phone_number']);
        $iccid = isset($validated['iccid']) ? strtoupper(trim($validated['iccid'])) : null;

        $item->update([
            'status' => EsimImportItem::STATUS_PROCESSING,
            'phone_number' => $phoneNumber,
            'iccid' => $iccid,
            'error_message' => null,
        ]);

        try {
            $extracted = $this->importService->extractFromStoredSource($item, $phoneNumber, $iccid);

            $result = DB::transaction(function () use ($batch, $item, $extracted, $phoneNumber, $iccid) {
                $result = $this->importService->persist($batch, $item, $extracted, $phoneNumber, $iccid);
                $esim = $result['esim'];

                $item->update([
                    'esim_id' => $esim->id,
                    'phone_number' => $esim->msisdn,
                    'iccid' => $esim->iccid,
                ]);

                return $result;
            });

            $esim = $result['esim'];
            $activation = $this->activateOnVodacom($esim);

            if (! $activation['success']) {
                $item->update([
                    'status' => EsimImportItem::STATUS_FAILED,
                    'error_message' => $activation['message'],
                ]);

                $batch->recordItemFailure();
                $batch->refresh();

                return response()->json([
                    'success' => false,
                    'message' => $activation['message'],
                    'saved' => true,
                    'activated' => false,
                    'item' => $item->fresh()->toResponseArray(),
                    'esim' => $esim->toImportApiArray(),
                    'batch' => $batch->toSummaryArray(),
                ], 422);
            }

            $item->update([
                'status' => EsimImportItem::STATUS_COMPLETED,
                'error_message' => null,
            ]);

            $batch->recordItemSuccess();
            $batch->refresh();

            return response()->json([
                'success' => true,
                'message' => 'SIM saved and activated on Vodacom.',
                'saved' => true,
                'activated' => true,
                'item' => $item->fresh()->toResponseArray(),
                'esim' => $esim->toImportApiArray(),
                'batch' => $batch->toSummaryArray(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('eSIM batch item confirm failed', [
                'item_id' => $item->id,
                'batch_id' => $batch->id,
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
                'saved' => false,
                'activated' => false,
                'item' => $item->fresh()->toResponseArray(),
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }
    }

    public function skip(EsimImportItem $item): JsonResponse
    {
        if ($item->status !== EsimImportItem::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending items can be skipped.',
            ], 422);
        }

        $batch = $item->batch;
        if (! $batch) {
            return response()->json([
                'success' => false,
                'message' => 'Import batch not found for this item.',
            ], 404);
        }

        $item->update([
            'status' => EsimImportItem::STATUS_SKIPPED,
            'error_message' => null,
        ]);

        $batch->recordItemSkipped();
        $batch->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Item skipped.',
            'item' => $item->fresh()->toResponseArray(),
            'batch' => $batch->toSummaryArray(),
        ]);
    }

    public function retryActivation(EsimImportItem $item): JsonResponse
    {
        if (! $item->esim_id) {
            return response()->json([
                'success' => false,
                'message' => 'No saved eSIM found for this item.',
            ], 422);
        }

        if ($item->status !== EsimImportItem::STATUS_FAILED) {
            return response()->json([
                'success' => false,
                'message' => 'Activation retry is only available for items that failed after saving.',
            ], 422);
        }

        $batch = $item->batch;
        $esim = $item->esim;

        if (! $esim) {
            return response()->json([
                'success' => false,
                'message' => 'Linked eSIM record not found.',
            ], 404);
        }

        $activation = $this->activateOnVodacom($esim);

        if (! $activation['success']) {
            $item->update(['error_message' => $activation['message']]);

            return response()->json([
                'success' => false,
                'message' => $activation['message'],
                'item' => $item->fresh()->toResponseArray(),
                'batch' => $batch?->fresh()->toSummaryArray(),
            ], 422);
        }

        $item->update([
            'status' => EsimImportItem::STATUS_COMPLETED,
            'error_message' => null,
        ]);

        if ($batch && $batch->failed_items > 0) {
            $batch->decrement('failed_items');
            $batch->increment('processed_items');
            $batch->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => 'SIM activated on Vodacom.',
            'item' => $item->fresh()->toResponseArray(),
            'esim' => $esim->fresh()->toImportApiArray(),
            'batch' => $batch?->fresh()->toSummaryArray(),
        ]);
    }

    public function retry(Request $request, EsimImportItem $item): JsonResponse
    {
        if ($item->status !== EsimImportItem::STATUS_FAILED) {
            return response()->json([
                'success' => false,
                'message' => 'Only failed items can be retried.',
            ], 422);
        }

        if ($item->esim_id) {
            return response()->json([
                'success' => false,
                'message' => 'This item was saved but activation failed. Use retry activation instead.',
            ], 422);
        }

        $batch = $item->batch;
        if (! $batch) {
            return response()->json([
                'success' => false,
                'message' => 'Import batch not found for this item.',
            ], 404);
        }

        if (in_array($batch->status, [EsimImportBatch::STATUS_COMPLETED, EsimImportBatch::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This import batch is closed.',
            ], 422);
        }

        $validated = $request->validate([
            'file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'iccid' => ['nullable', 'string', 'max:50'],
        ]);

        if (empty($validated['file']) && ! $item->source_file_path) {
            return response()->json([
                'success' => false,
                'message' => 'Upload a new file or ensure a stored source file exists.',
            ], 422);
        }

        $wasFailed = true;

        $item->update([
            'status' => EsimImportItem::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        if ($wasFailed && $batch->failed_items > 0) {
            $batch->decrement('failed_items');
        }

        try {
            $result = DB::transaction(function () use ($batch, $item, $validated, $request) {
                $result = $this->importService->retry(
                    $batch,
                    $item,
                    $validated['file'] ?? null,
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
            ]);
        } catch (\Throwable $e) {
            Log::warning('eSIM batch item retry failed', [
                'item_id' => $item->id,
                'batch_id' => $batch->id,
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
     * @return array{success: bool, message: string, response?: Response}
     */
    private function activateOnVodacom(Esim $esim): array
    {
        $query = array_filter([
            'msisdn' => $esim->msisdn,
            'iccid' => $esim->iccid,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        if ($query === []) {
            return [
                'success' => false,
                'message' => 'Cannot activate: MSISDN or ICCID is required.',
            ];
        }

        try {
            $response = $this->vodacom->post('/api/sims-activate', $query);

            if (! $response->successful()) {
                $body = mb_substr((string) $response->body(), 0, 500);

                return [
                    'success' => false,
                    'message' => 'Saved to inventory but Vodacom activation failed: '.$body,
                    'response' => $response,
                ];
            }

            $esim->update(['provider_status' => Esim::PROVIDER_STATUS_ACTIVE]);

            return [
                'success' => true,
                'message' => 'Activated on Vodacom.',
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Saved to inventory but Vodacom activation failed: '.$e->getMessage(),
            ];
        }
    }
}
