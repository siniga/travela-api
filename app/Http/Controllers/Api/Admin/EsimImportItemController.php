<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use App\Services\Esim\EsimImportConfirmService;
use App\Services\Esim\EsimSingleImportService;
use App\Services\Esim\VodacomSimProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EsimImportItemController extends Controller
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
        private readonly EsimImportConfirmService $confirmService,
        private readonly VodacomSimProvisioningService $vodacomProvisioning,
    ) {}

    public function confirm(Request $request, EsimImportItem $item): JsonResponse
    {
        $batch = $this->openBatchForItem($item);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        if (! in_array($item->status, [EsimImportItem::STATUS_PENDING, EsimImportItem::STATUS_FAILED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or failed items can be confirmed.',
            ], 422);
        }

        $validated = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:30'],
            'iccid' => ['nullable', 'string', 'max:50'],
            'network_id' => ['nullable', 'integer', 'min:1'],
            'qr_code_data' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->confirmService->confirm($batch, $item, $validated);

            return response()->json([
                'success' => true,
                'message' => $batch->isPhysical()
                    ? 'Physical SIM saved to inventory.'
                    : 'SIM saved and provisioned on Vodacom.',
                'item' => $result['item']->toResponseArray(),
                'esim' => $result['esim']->toImportApiArray(),
                'vodacom' => $result['vodacom'],
                'batch' => $batch->fresh()->toSummaryArray(),
            ]);
        } catch (\Throwable $e) {
            $fresh = $item->fresh();
            $esimPayload = null;
            if ($fresh?->esim_id) {
                $esimPayload = Esim::query()->find($fresh->esim_id)?->toImportApiArray();
            }

            $message = (! $batch->isPhysical() && $fresh?->esim_id)
                ? 'Saved to inventory but Vodacom provisioning failed: '.$e->getMessage()
                : $e->getMessage();

            return response()->json([
                'success' => false,
                'message' => $message,
                'item' => $fresh?->toResponseArray(),
                'esim' => $esimPayload,
                'batch' => $batch->fresh()->toSummaryArray(),
            ], 422);
        }
    }

    public function skip(EsimImportItem $item): JsonResponse
    {
        $batch = $this->openBatchForItem($item);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        if (! in_array($item->status, [EsimImportItem::STATUS_PENDING, EsimImportItem::STATUS_FAILED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or failed items can be skipped.',
            ], 422);
        }

        if ($item->status === EsimImportItem::STATUS_FAILED && $batch->failed_items > 0) {
            $batch->decrement('failed_items');
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
        $batch = $this->openBatchForItem($item);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        if (! $item->esim_id) {
            return response()->json([
                'success' => false,
                'message' => 'No saved SIM for this item. Use confirm instead.',
            ], 422);
        }

        $esim = Esim::query()->find($item->esim_id);
        if (! $esim) {
            return response()->json([
                'success' => false,
                'message' => 'Saved SIM record not found.',
            ], 404);
        }

        $previousStatus = $item->status;

        $item->update([
            'status' => EsimImportItem::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        if ($previousStatus === EsimImportItem::STATUS_FAILED && $batch->failed_items > 0) {
            $batch->decrement('failed_items');
        }

        try {
            $vodacomResult = $this->provisionOnVodacom($esim);
            $esim->update(['provider_status' => Esim::PROVIDER_STATUS_ACTIVE]);

            $item->update([
                'status' => EsimImportItem::STATUS_COMPLETED,
                'error_message' => null,
            ]);

            $batch->recordItemSuccess();
            $batch->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Vodacom provisioning succeeded.',
                'item' => $item->fresh()->toResponseArray(),
                'esim' => $esim->fresh()->toImportApiArray(),
                'vodacom' => $vodacomResult,
                'batch' => $batch->toSummaryArray(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('eSIM import Vodacom retry failed', [
                'item_id' => $item->id,
                'esim_id' => $esim->id,
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
                'esim' => $esim->fresh()->toImportApiArray(),
                'batch' => $batch->toSummaryArray(),
            ], 422);
        }
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
            return $this->retryActivation($item);
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

        if ($batch->isPhysical()) {
            if (! $item->phone_number || ! $item->iccid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Physical item is missing msisdn or iccid.',
                ], 422);
            }

            try {
                $result = $this->confirmService->confirm($batch, $item, [
                    'phone_number' => $item->phone_number,
                    'iccid' => $item->iccid,
                ]);

                return response()->json([
                    'success' => true,
                    'item' => $result['item']->toResponseArray(),
                    'esim' => $result['esim']->toImportApiArray(),
                    'batch' => $batch->fresh()->toSummaryArray(),
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'item' => $item->fresh()->toResponseArray(),
                    'batch' => $batch->fresh()->toSummaryArray(),
                ], 422);
            }
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

        $item->update([
            'status' => EsimImportItem::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        if ($batch->failed_items > 0) {
            $batch->decrement('failed_items');
        }

        try {
            $result = DB::transaction(function () use ($batch, $item, $validated) {
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
     * @return array<string, mixed>
     */
    private function provisionOnVodacom(Esim $esim): array
    {
        $createResult = $this->vodacomProvisioning->createOnVodacom($esim);

        if (! config('services.vodacom_sim.auto_activate_on_import', true)) {
            return ['create' => $createResult, 'activate' => null];
        }

        $activateResult = $this->vodacomProvisioning->activateOnVodacom($esim);

        return [
            'create' => $createResult,
            'activate' => $activateResult,
        ];
    }

    private function openBatchForItem(EsimImportItem $item): EsimImportBatch|JsonResponse
    {
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

        return $batch;
    }
}
