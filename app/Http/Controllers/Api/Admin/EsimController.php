<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EsimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'in:available,sold,used'],
            'import_batch_id' => ['nullable', 'integer', 'exists:esim_import_batches,id'],
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

        if (! empty($validated['import_batch_id'])) {
            $query->where('import_batch_id', $validated['import_batch_id']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 15);
        $paginator->getCollection()->transform(fn (Esim $esim) => $this->formatListEsim($esim));

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function qr(Esim $esim): StreamedResponse|JsonResponse
    {
        if ($esim->sim_type !== Esim::SIM_TYPE_ESIM) {
            return response()->json([
                'success' => false,
                'message' => 'QR code is only available for imported eSIM records.',
            ], 404);
        }

        $located = $this->locateQrImage($esim->qr_code_path);
        if ($located === null) {
            return response()->json([
                'success' => false,
                'message' => 'QR code image not available for this eSIM.',
            ], 404);
        }

        $path = $located['path'];
        $mime = str_ends_with(strtolower($path), '.png')
            ? 'image/png'
            : 'image/jpeg';

        return Storage::disk($located['disk'])->response(
            $path,
            'esim-'.$esim->id.'-qr.'.pathinfo($path, PATHINFO_EXTENSION),
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * Resolve stored QR image path across current and legacy storage locations.
     *
     * @return array{disk: string, path: string}|null
     */
    private function locateQrImage(?string $qrCodePath): ?array
    {
        $path = $this->normalizeQrCodePath($qrCodePath);
        if ($path === null) {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return ['disk' => 'local', 'path' => $path];
        }

        // Laravel 10 and earlier stored "local" files directly under storage/app/.
        $legacyFullPath = storage_path('app/'.$path);
        if (is_file($legacyFullPath)) {
            Storage::disk('local')->put($path, file_get_contents($legacyFullPath) ?: '');
            @unlink($legacyFullPath);

            return ['disk' => 'local', 'path' => $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['disk' => 'public', 'path' => $path];
        }

        return null;
    }

    private function normalizeQrCodePath(?string $qrCodePath): ?string
    {
        if ($qrCodePath === null || trim($qrCodePath) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($qrCodePath));

        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        if (preg_match('#storage/app/(?:private/)?(.+)$#', $path, $matches) === 1) {
            $path = $matches[1];
        } elseif (preg_match('#(?:^|/)storage/(esims/qr-codes/.+)$#', $path, $matches) === 1) {
            $path = $matches[1];
        }

        if (str_starts_with($path, 'private/')) {
            $path = substr($path, strlen('private/'));
        }

        if (preg_match('#(esims/qr-codes/[^/]+)$#', $path, $matches) === 1) {
            $path = $matches[1];
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListEsim(Esim $esim): array
    {
        return [
            'id' => $esim->id,
            'phone_number' => $esim->msisdn,
            'iccid' => $esim->iccid,
            'status' => $esim->sale_status ?? Esim::SALE_STATUS_AVAILABLE,
            'import_batch_id' => $esim->import_batch_id,
            'has_qr_code' => (bool) $esim->qr_code_path,
            'created_at' => $esim->created_at,
            'updated_at' => $esim->updated_at,
        ];
    }
}
