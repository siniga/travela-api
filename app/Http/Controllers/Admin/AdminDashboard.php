<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\SimInventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminDashboard extends Controller
{
    public function __construct(private readonly SimInventoryService $inventory)
    {
    }

    public function stats(): JsonResponse
    {
        $totalOrders = (int) Order::query()->where('status', '!=', 'draft')->count();
        $totalRevenue = (float) (Order::query()->where('status', 'paid')->sum('total_amount') ?? 0);
        $todaysOrders = (int) Order::query()
            ->where('status', '!=', 'draft')
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'todays_orders' => $todaysOrders,
                'esims_issued' => $this->esimsIssuedData(),
                'recent_orders' => $this->recentOrdersData(),
                'inventory_stock' => $this->inventory->report(),
            ],
        ]);
    }

    /**
     * eSIMs issued: all user_esims assignments (SIMs assigned to users).
     */
    public function esimsIssued(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->esimsIssuedData(),
        ]);
    }

    /**
     * Activity feed for eSIM issuance (assignments), with order context when available.
     */
    public function esimIssuedActivities(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        return response()->json([
            'success' => true,
            'data' => $this->esimIssuedActivitiesData($limit),
        ]);
    }

    /**
     * @return array{
     *     total_issued: int,
     *     esim_count: int,
     *     physical_count: int,
     *     assignments: \Illuminate\Support\Collection<int, UserEsim>
     * }
     */
    private function esimsIssuedData(): array
    {
        $assignments = UserEsim::query()
            ->with(['user:id,name,email,role', 'esim'])
            ->orderByDesc('id')
            ->get();

        $esimCount = $assignments->filter(
            fn (UserEsim $row) => $row->esim?->sim_type === Esim::SIM_TYPE_ESIM
        )->count();

        $physicalCount = $assignments->filter(
            fn (UserEsim $row) => $row->esim?->sim_type === Esim::SIM_TYPE_PHYSICAL
        )->count();

        return [
            'total_issued' => $assignments->count(),
            'esim_count' => $esimCount,
            'physical_count' => $physicalCount,
            'assignments' => $assignments,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function recentOrdersData()
    {
        return DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->join('trips', 'trips.order_id', '=', 'orders.id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('bundles', 'bundles.id', '=', 'order_items.bundle_id')
            ->where('orders.status', '=', 'paid')
            ->where('order_items.type', '=', 'bundle')
            ->groupBy(
                'orders.id',
                'users.name',
                'orders.created_at',
                'orders.currency',
                'trips.destination_country',
                'order_items.bundle_id',
                'bundles.name',
                'order_items.bundle_name',
            )
            ->orderByDesc('orders.created_at')
            ->limit(10)
            ->get([
                'orders.id as order_id',
                'users.name as customer',
                DB::raw('COALESCE(bundles.name, order_items.bundle_name) as bundle'),
                'trips.destination_country as country',
                'orders.created_at as date',
                'orders.currency as currency',
                DB::raw('COUNT(order_items.id) as qty'),
                DB::raw('SUM(order_items.price) as revenue'),
            ])
            ->map(function ($row) {
                return [
                    'order_id' => (int) $row->order_id,
                    'customer' => $row->customer,
                    'bundle' => $row->bundle,
                    'country' => $row->country,
                    'date' => $row->date,
                    'currency' => $row->currency,
                    'qty' => (int) $row->qty,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();
    }

    /**
     * @return array{activities: \Illuminate\Support\Collection<int, array<string, mixed>>}
     */
    private function esimIssuedActivitiesData(int $limit): array
    {
        $assignments = UserEsim::query()
            ->whereNotNull('user_id')
            ->whereHas('esim', fn ($q) => $q->where('sim_type', Esim::SIM_TYPE_ESIM))
            ->with(['user:id,name', 'esim:id,sim_type'])
            ->orderByDesc('created_at')
            ->get();

        if ($assignments->isEmpty()) {
            return ['activities' => collect()];
        }

        $ordersByAssignmentId = $this->ordersLinkedToAssignments();
        $orderDetails = $this->orderContextById(
            $ordersByAssignmentId->pluck('id')->unique()->values()->all()
        );

        $groups = [];

        foreach ($assignments as $assignment) {
            $order = $ordersByAssignmentId->get($assignment->id);
            $groupKey = $order
                ? 'order:'.$order->id
                : 'user:'.$assignment->user_id.':'.($assignment->created_at?->toDateString() ?? 'unknown');

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'assignment_ids' => [],
                    'user_id' => (int) $assignment->user_id,
                    'customer_name' => $assignment->user?->name ?? 'Unknown',
                    'order_id' => $order?->id,
                    'issued_at' => $assignment->created_at,
                ];
            }

            $groups[$groupKey]['assignment_ids'][] = $assignment->id;

            if ($assignment->created_at && (
                ! $groups[$groupKey]['issued_at']
                || $assignment->created_at->gt($groups[$groupKey]['issued_at'])
            )) {
                $groups[$groupKey]['issued_at'] = $assignment->created_at;
            }
        }

        $activities = collect($groups)
            ->map(function (array $group) use ($orderDetails) {
                $quantity = count($group['assignment_ids']);
                $orderId = $group['order_id'];
                $context = $orderId ? ($orderDetails[$orderId] ?? null) : null;

                if (! $context && $orderId === null) {
                    $context = $this->fallbackOrderContextForUser(
                        $group['user_id'],
                        $group['issued_at']
                    );
                    if ($context) {
                        $orderId = $context['order_id'];
                        if ($context['qty'] > 0) {
                            $quantity = max($quantity, $context['qty']);
                        }
                    }
                } elseif ($context && $context['qty'] > 0) {
                    $quantity = max($quantity, $context['qty']);
                }

                $customer = $group['customer_name'];
                $bundle = $context['bundle'] ?? null;
                $country = $context['country'] ?? null;
                $currency = $context['currency'] ?? 'USD';
                $amount = $context['amount'] ?? null;
                $issuedAt = $group['issued_at'];

                return [
                    'id' => $orderId ? 'order:'.$orderId : 'assignments:'.implode(',', $group['assignment_ids']),
                    'order_id' => $orderId,
                    'assignment_ids' => $group['assignment_ids'],
                    'quantity' => $quantity,
                    'customer_name' => $customer,
                    'headline' => $this->issuedHeadline($quantity, $customer),
                    'bundle' => $bundle,
                    'country' => $country,
                    'currency' => $currency,
                    'amount' => $amount,
                    'summary_line' => $this->issuedSummaryLine($bundle, $country, $currency, $amount),
                    'issued_at' => $issuedAt?->toIso8601String(),
                    'date_label' => $issuedAt?->format('d/m/Y'),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['issued_at'] ?? '')
            ->take($limit)
            ->values();

        return ['activities' => $activities];
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersLinkedToAssignments(): Collection
    {
        return Order::query()
            ->where('status', 'paid')
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn (Order $order) => ! empty($order->metadata['user_esim_id']))
            ->keyBy(fn (Order $order) => (int) $order->metadata['user_esim_id']);
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{order_id: int, bundle: ?string, country: ?string, currency: string, amount: float, qty: int}>
     */
    private function orderContextById(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('orders')
            ->join('trips', 'trips.order_id', '=', 'orders.id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('bundles', 'bundles.id', '=', 'order_items.bundle_id')
            ->whereIn('orders.id', $orderIds)
            ->where('order_items.type', '=', 'bundle')
            ->groupBy(
                'orders.id',
                'orders.currency',
                'orders.total_amount',
                'trips.destination_country',
            )
            ->get([
                'orders.id as order_id',
                'orders.currency',
                'orders.total_amount',
                'trips.destination_country as country',
                DB::raw('COUNT(order_items.id) as qty'),
                DB::raw('MIN(COALESCE(bundles.name, order_items.bundle_name)) as bundle'),
            ]);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->order_id] = [
                'order_id' => (int) $row->order_id,
                'bundle' => $row->bundle,
                'country' => $row->country,
                'currency' => $row->currency ?: 'USD',
                'amount' => (float) $row->total_amount,
                'qty' => (int) $row->qty,
            ];
        }

        return $map;
    }

    /**
     * @return array{order_id: int, bundle: ?string, country: ?string, currency: string, amount: float, qty: int}|null
     */
    private function fallbackOrderContextForUser(int $userId, ?Carbon $issuedAt): ?array
    {
        $query = DB::table('orders')
            ->join('trips', 'trips.order_id', '=', 'orders.id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('bundles', 'bundles.id', '=', 'order_items.bundle_id')
            ->where('orders.user_id', $userId)
            ->where('orders.status', 'paid')
            ->where('order_items.type', '=', 'bundle');

        if ($issuedAt) {
            $query->where('orders.created_at', '<=', $issuedAt->copy()->addDay());
        }

        $row = $query
            ->groupBy(
                'orders.id',
                'orders.currency',
                'orders.total_amount',
                'trips.destination_country',
                'orders.created_at',
            )
            ->orderByDesc('orders.created_at')
            ->limit(1)
            ->first([
                'orders.id as order_id',
                'orders.currency',
                'orders.total_amount',
                'trips.destination_country as country',
                DB::raw('COUNT(order_items.id) as qty'),
                DB::raw('MIN(COALESCE(bundles.name, order_items.bundle_name)) as bundle'),
            ]);

        if (! $row) {
            return null;
        }

        return [
            'order_id' => (int) $row->order_id,
            'bundle' => $row->bundle,
            'country' => $row->country,
            'currency' => $row->currency ?: 'USD',
            'amount' => (float) $row->total_amount,
            'qty' => (int) $row->qty,
        ];
    }

    private function issuedHeadline(int $quantity, string $customerName): string
    {
        $unit = $quantity === 1 ? 'eSIM' : 'eSIMs';

        return sprintf('Issued %d %s to %s', $quantity, $unit, $customerName);
    }

    private function issuedSummaryLine(?string $bundle, ?string $country, string $currency, ?float $amount): ?string
    {
        if ($bundle === null && $country === null && $amount === null) {
            return null;
        }

        $parts = array_filter([
            $bundle,
            $country,
            $amount !== null
                ? sprintf('%s %.2f', strtoupper($currency), $amount)
                : null,
        ], fn ($part) => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }
}

