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

class EsimImportItemController extends Controller
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
    ) {
    }

    public function retry(Request $request, EsimImportItem $item): JsonResponse
    {
        if ($item->status !== EsimImportItem::STATUS_FAILED) {
            return response()->json([
                'success' => false,
                'message' => 'Only failed items can be retried.',
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
}
