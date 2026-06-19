<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Services\EsimImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EsimImportController extends Controller
{
    public function __construct(
        private readonly EsimImportService $importService,
    ) {
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required_without:pdf', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'pdf' => ['required_without:file', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $upload = $validated['file'] ?? $validated['pdf'];

        try {
            $summary = $this->importService->importFromPdf($upload);
        } catch (\Throwable $e) {
            Log::error('eSIM PDF import failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process PDF import.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'PDF import completed.',
            'data' => $summary,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'in:available,sold,used'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Esim::query()
            ->where('sim_type', Esim::SIM_TYPE_ESIM)
            ->orderByDesc('id');

        if (! empty($validated['phone_number'])) {
            $needle = Esim::normalizeMsisdn($validated['phone_number']);
            $query->where(function ($q) use ($needle) {
                $q->where('msisdn', $needle)
                    ->orWhere('msisdn', 'like', '%'.$needle.'%');
            });
        }

        if (! empty($validated['status'])) {
            $query->where('sale_status', $validated['status']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 15);
        $paginator->getCollection()->transform(fn (Esim $esim) => $this->formatEsim($esim));

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $esim = Esim::query()
            ->where('sim_type', Esim::SIM_TYPE_ESIM)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatEsim($esim, detailed: true),
        ]);
    }

    public function qr(int $id): StreamedResponse|JsonResponse
    {
        $esim = Esim::query()
            ->where('sim_type', Esim::SIM_TYPE_ESIM)
            ->findOrFail($id);

        if (! $esim->qr_code_path || ! Storage::disk('local')->exists($esim->qr_code_path)) {
            return response()->json([
                'success' => false,
                'message' => 'QR code image not available for this eSIM.',
            ], 404);
        }

        $mime = str_ends_with(strtolower($esim->qr_code_path), '.png')
            ? 'image/png'
            : 'image/jpeg';

        return Storage::disk('local')->response(
            $esim->qr_code_path,
            'esim-'.$esim->id.'-qr.'.pathinfo($esim->qr_code_path, PATHINFO_EXTENSION),
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEsim(Esim $esim, bool $detailed = false): array
    {
        $payload = [
            'id' => $esim->id,
            'phone_number' => $esim->msisdn,
            'iccid' => $esim->iccid,
            'status' => $esim->sale_status ?? Esim::SALE_STATUS_AVAILABLE,
            'inventory_status' => $esim->status,
            'has_qr_code' => (bool) $esim->qr_code_path,
            'created_at' => $esim->created_at,
            'updated_at' => $esim->updated_at,
        ];

        if ($detailed) {
            $payload['qr_code_data'] = $esim->qr_code_data;
            $payload['qr_url'] = $esim->qr_code_path
                ? url('/api/admin/esims/'.$esim->id.'/qr')
                : null;
        }

        return $payload;
    }
}
