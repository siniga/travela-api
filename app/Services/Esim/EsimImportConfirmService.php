<?php

namespace App\Services\Esim;

use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EsimImportConfirmService
{
    public function __construct(
        private readonly EsimSingleImportService $importService,
        private readonly VodacomSimProvisioningService $vodacomProvisioning,
    ) {}

    /**
     * Persist one pending/failed item to inventory and provision on Vodacom.
     *
     * @param  array{
     *   phone_number?: string|null,
     *   iccid?: string|null,
     *   network_id?: int|null,
     *   qr_code_data?: string|null
     * }  $overrides
     * @return array{esim: Esim, item: EsimImportItem, vodacom: array<string, mixed>}
     */
    public function confirm(EsimImportBatch $batch, EsimImportItem $item, array $overrides = []): array
    {
        if (! in_array($item->status, [EsimImportItem::STATUS_PENDING, EsimImportItem::STATUS_FAILED], true)) {
            throw new RuntimeException('Only pending or failed items can be confirmed.');
        }

        if ($item->status === EsimImportItem::STATUS_FAILED && $batch->failed_items > 0) {
            $batch->decrement('failed_items');
        }

        $item->update([
            'status' => EsimImportItem::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        try {
            $persisted = DB::transaction(function () use ($batch, $item, $overrides) {
                $extracted = [
                    'phone_number' => $overrides['phone_number'] ?? $item->phone_number,
                    'iccid' => $overrides['iccid'] ?? $item->iccid,
                    'qr_code_path' => $batch->isPhysical() ? null : $item->qr_code_path,
                    'qr_code_data' => $batch->isPhysical()
                        ? null
                        : ($overrides['qr_code_data'] ?? null),
                ];

                if (! $extracted['phone_number']) {
                    throw new RuntimeException('Phone number is required before confirming.');
                }

                $persistResult = $this->importService->persist($batch, $item, $extracted);
                $esim = $persistResult['esim'];

                if (! empty($overrides['network_id'])) {
                    $esim->update(['network_id' => (int) $overrides['network_id']]);
                    $esim = $esim->fresh();
                }

                $item->update([
                    'esim_id' => $esim->id,
                    'phone_number' => $esim->msisdn,
                    'iccid' => $esim->iccid,
                    'qr_code_path' => $batch->isPhysical() ? null : $item->qr_code_path,
                ]);

                return $esim;
            });

            $vodacomResult = $this->provisionOnVodacom($persisted);
            $persisted->update(['provider_status' => Esim::PROVIDER_STATUS_ACTIVE]);

            $item->update([
                'status' => EsimImportItem::STATUS_COMPLETED,
                'error_message' => null,
            ]);

            $batch->recordItemSuccess();
            $batch->refresh();

            return [
                'esim' => $persisted->fresh(),
                'item' => $item->fresh(),
                'vodacom' => $vodacomResult,
            ];
        } catch (Throwable $e) {
            Log::warning('eSIM import confirm failed', [
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

            throw $e;
        }
    }

    /**
     * Confirm every pending/failed item in the batch (skip-review path).
     *
     * @return array{
     *   completed: int,
     *   failed: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public function confirmAll(EsimImportBatch $batch): array
    {
        $items = $batch->items()
            ->whereIn('status', [EsimImportItem::STATUS_PENDING, EsimImportItem::STATUS_FAILED])
            ->orderBy('id')
            ->get();

        $completed = 0;
        $failed = 0;
        $results = [];

        foreach ($items as $item) {
            $batch->refresh();
            try {
                $result = $this->confirm($batch, $item);
                $completed++;
                $results[] = [
                    'success' => true,
                    'item' => $result['item']->toResponseArray(),
                    'esim' => $result['esim']->toImportApiArray(),
                ];
            } catch (Throwable $e) {
                $failed++;
                $fresh = $item->fresh();
                $esimPayload = null;
                if ($fresh?->esim_id) {
                    $esimPayload = Esim::query()->find($fresh->esim_id)?->toImportApiArray();
                }
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'item' => $fresh?->toResponseArray(),
                    'esim' => $esimPayload,
                ];
            }
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    /**
     * Store spreadsheet source and create pending items for a physical batch.
     *
     * @param  list<array{msisdn: string, iccid: string, row_number: int}>  $rows
     * @return list<EsimImportItem>
     */
    public function createPendingItemsFromRows(
        EsimImportBatch $batch,
        array $rows,
        ?string $sourceFilePath = null,
    ): array {
        if ($batch->items()->exists()) {
            throw new RuntimeException('This batch already has import items.');
        }

        $batch->update([
            'total_items' => count($rows),
            'status' => EsimImportBatch::STATUS_PROCESSING,
            'started_at' => $batch->started_at ?? now(),
        ]);

        $created = [];
        foreach ($rows as $row) {
            $created[] = EsimImportItem::query()->create([
                'esim_import_batch_id' => $batch->id,
                'page_number' => $row['row_number'],
                'phone_number' => Esim::normalizeMsisdn($row['msisdn']),
                'iccid' => strtoupper(trim($row['iccid'])),
                'source_file_path' => $sourceFilePath,
                'qr_code_path' => null,
                'status' => EsimImportItem::STATUS_PENDING,
            ]);
        }

        return $created;
    }

    public function storeSpreadsheetSource(EsimImportBatch $batch, \Illuminate\Http\UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $path = sprintf(
            'esims/import-sources/batch-%d/physical-spreadsheet.%s',
            $batch->id,
            $ext
        );

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return $path;
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
}
