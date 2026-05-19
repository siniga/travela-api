<?php

namespace Database\Seeders;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Trip;
use App\Models\Kyc;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::take(5)->get();

        $byAlias = Bundle::query()
            ->whereIn('alias', ['Starter', 'Explorer', 'Traveller', 'Nomad'])
            ->orderByRaw("FIELD(alias, 'Starter','Explorer','Traveller','Nomad')")
            ->get()
            ->keyBy('alias');

        if ($users->isEmpty() || $byAlias->count() < 4) {
            $this->command->warn('Need at least 5 users and all 4 Travela bundle aliases seeded. Run UserSeeder and BundleSeeder first.');

            return;
        }

        $sampleOrders = [
            [
                'draft_id' => 'DRAFT-2025-001',
                'user_id' => $users[0]->id,
                'status' => 'pending_payment',
                'discount_amount' => 0.00,
                'discount_code' => null,
                'source' => 'mobile_app',
                'platform' => 'react_native',
                'metadata' => [
                    'created_at' => '2025-10-21T11:30:00Z',
                    'status' => 'pending_payment',
                ],
                'kyc' => [
                    'passport_id' => 'A1234567',
                    'passport_country' => 'TZ',
                    'nationality' => 'Tanzanian',
                    'gender' => 'Female',
                    'reason_for_travel' => 'tourism',
                ],
                'trip' => [
                    'destination_country' => 'Kenya',
                    'arrival_date' => '2025-10-25',
                    'departure_date' => '2025-10-30',
                    'duration_days' => 5,
                ],
                'items' => [
                    ['bundle_alias' => 'Nomad'],
                ],
            ],
            [
                'draft_id' => 'DRAFT-2025-002',
                'user_id' => $users[1]->id,
                'status' => 'paid',
                'discount_amount' => 4.00,
                'discount_code' => 'WELCOME',
                'source' => 'web_app',
                'platform' => 'react_js',
                'metadata' => [
                    'created_at' => '2025-10-20T14:15:00Z',
                    'status' => 'paid',
                    'payment_method' => 'mobile_money',
                ],
                'kyc' => [
                    'passport_id' => 'B9876543',
                    'passport_country' => 'TZ',
                    'nationality' => 'Tanzanian',
                    'gender' => 'Male',
                    'reason_for_travel' => 'business',
                ],
                'trip' => [
                    'destination_country' => 'Uganda',
                    'arrival_date' => '2025-11-01',
                    'departure_date' => '2025-11-05',
                    'duration_days' => 4,
                ],
                'items' => [
                    ['bundle_alias' => 'Explorer'],
                ],
            ],
            [
                'draft_id' => 'DRAFT-2025-003',
                'user_id' => $users[2]->id,
                'status' => 'completed',
                'discount_amount' => 0.00,
                'discount_code' => null,
                'source' => 'mobile_app',
                'platform' => 'flutter',
                'metadata' => [
                    'created_at' => '2025-10-19T09:45:00Z',
                    'status' => 'completed',
                    'payment_method' => 'credit_card',
                    'processed_at' => '2025-10-19T10:00:00Z',
                ],
                'kyc' => [
                    'passport_id' => 'C5555555',
                    'passport_country' => 'KE',
                    'nationality' => 'Kenyan',
                    'gender' => 'Male',
                    'reason_for_travel' => 'tourism',
                ],
                'trip' => [
                    'destination_country' => 'Tanzania',
                    'arrival_date' => '2025-10-22',
                    'departure_date' => '2025-10-28',
                    'duration_days' => 6,
                ],
                'items' => [
                    ['bundle_alias' => 'Traveller'],
                ],
            ],
            [
                'draft_id' => 'DRAFT-2025-004',
                'user_id' => $users[3]->id,
                'status' => 'processing',
                'discount_amount' => 1.00,
                'discount_code' => 'FIRST10',
                'source' => 'mobile_app',
                'platform' => 'react_native',
                'metadata' => [
                    'created_at' => '2025-10-18T16:20:00Z',
                    'status' => 'processing',
                    'payment_method' => 'mobile_money',
                ],
                'kyc' => [
                    'passport_id' => 'D1111111',
                    'passport_country' => 'KE',
                    'nationality' => 'Kenyan',
                    'gender' => 'Female',
                    'reason_for_travel' => 'business',
                ],
                'trip' => [
                    'destination_country' => 'Rwanda',
                    'arrival_date' => '2025-11-10',
                    'departure_date' => '2025-11-12',
                    'duration_days' => 2,
                ],
                'items' => [
                    ['bundle_alias' => 'Starter'],
                ],
            ],
            [
                'draft_id' => 'DRAFT-2025-005',
                'user_id' => $users[4]->id,
                'status' => 'cancelled',
                'discount_amount' => 0.00,
                'discount_code' => null,
                'source' => 'web_app',
                'platform' => 'vue_js',
                'metadata' => [
                    'created_at' => '2025-10-17T13:30:00Z',
                    'status' => 'cancelled',
                    'cancelled_at' => '2025-10-17T14:00:00Z',
                    'cancellation_reason' => 'customer_request',
                ],
                'kyc' => [
                    'passport_id' => 'E9999999',
                    'passport_country' => 'TZ',
                    'nationality' => 'Tanzanian',
                    'gender' => 'Other',
                    'reason_for_travel' => 'tourism',
                ],
                'trip' => [
                    'destination_country' => 'South Africa',
                    'arrival_date' => '2025-12-01',
                    'departure_date' => '2025-12-10',
                    'duration_days' => 9,
                ],
                'items' => [
                    ['bundle_alias' => 'Starter'],
                ],
            ],
        ];

        foreach ($sampleOrders as $orderData) {
            $lines = [];

            foreach ($orderData['items'] as $line) {
                $bundle = $byAlias[$line['bundle_alias']];

                $lines[] = [
                    'bundle' => $bundle,
                    'line_subtotal' => (float) $bundle->price_usd,
                ];
            }

            $subtotal = array_sum(array_column($lines, 'line_subtotal'));
            $total = max(0, $subtotal - (float) $orderData['discount_amount']);

            Kyc::updateOrCreate(
                ['user_id' => $orderData['user_id']],
                [
                    'passport_id' => $orderData['kyc']['passport_id'],
                    'passport_country' => $orderData['kyc']['passport_country'],
                    'nationality' => $orderData['kyc']['nationality'],
                    'gender' => $orderData['kyc']['gender'],
                    'reason' => $orderData['kyc']['reason_for_travel'],
                    'arrival_date' => $orderData['trip']['arrival_date'],
                    'departure_date' => $orderData['trip']['departure_date'],
                ]
            );

            $currency = $lines[0]['bundle']->currency;

            $order = Order::updateOrCreate(
                ['draft_id' => $orderData['draft_id']],
                [
                    'user_id' => $orderData['user_id'],
                    'status' => $orderData['status'],
                    'subtotal' => $subtotal,
                    'discount_amount' => $orderData['discount_amount'],
                    'discount_code' => $orderData['discount_code'],
                    'total_amount' => $total,
                    'currency' => $currency,
                    'source' => $orderData['source'],
                    'platform' => $orderData['platform'],
                    'metadata' => $orderData['metadata'],
                ]
            );

            Trip::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'destination_country' => $orderData['trip']['destination_country'],
                    'arrival_date' => $orderData['trip']['arrival_date'],
                    'departure_date' => $orderData['trip']['departure_date'],
                    'duration_days' => $orderData['trip']['duration_days'],
                ]
            );

            foreach ($lines as $line) {
                $bundle = $line['bundle'];

                OrderItem::updateOrCreate(
                    [
                        'order_id' => $order->id,
                        'bundle_id' => $bundle->id,
                        'type' => 'bundle',
                    ],
                    [
                        'bundle_name' => $bundle->name,
                        'data_amount' => $bundle->data_mb,
                        'validity_days' => $bundle->validity_days,
                        'price' => $bundle->price_usd,
                        'currency' => $bundle->currency,
                    ]
                );
            }
        }

        $this->command->info('Order seeder completed successfully!');
    }
}
