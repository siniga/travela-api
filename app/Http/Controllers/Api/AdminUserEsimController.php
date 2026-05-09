<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\UserEsim;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\Request;

class AdminUserEsimController extends Controller
{
    public function __construct(private readonly VodacomSimManagerService $vodacom)
    {
    }

    public function index()
    {
        $items = UserEsim::with(['user:id,name,email,role', 'esim'])
            ->orderBy('id', 'desc')
            ->paginate(50);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'esim_id' => ['nullable', 'integer', 'exists:esims,id'],
            'msisdn' => ['nullable', 'string'],
        ]);

        $esimId = $data['esim_id'] ?? null;

        if (! $esimId && ! empty($data['msisdn'])) {
            $esimId = Esim::where('msisdn', $data['msisdn'])->value('id');
        }

        if (! $esimId) {
            return response()->json(['message' => 'Provide esim_id or msisdn.'], 422);
        }

        // Assignment: cannot reuse an esim_id (unique constraint)
        $assignment = UserEsim::create([
            'user_id' => $data['user_id'],
            'esim_id' => $esimId,
        ]);

        // Mark inventory as managed
        Esim::where('id', $esimId)->update(['status' => 'MANAGED']);

        return response()->json(['assignment' => $assignment->load(['user:id,name,email,role', 'esim'])], 201);
    }

    public function destroy(int $id)
    {
        $esim = UserEsim::find($id);

        if (! $esim) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $esim->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function syncFromVodacom(Request $request)
    {
        $query = [
            'status' => $request->query('status', 'ALL'),
            'page' => (int) $request->query('page', 1),
            'page_size' => (int) $request->query('page_size', 50),
        ];

        return $this->proxy($this->vodacom->get('/api/sims', $query));
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}

