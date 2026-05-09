<?php

namespace Database\Seeders;

use App\Models\Esim;
use Illuminate\Database\Seeder;

class EsimSeeder extends Seeder
{
    public function run(): void
    {
        $pairs = [
            ['iccid' => '8925504500855150553', 'msisdn' => '255798092059'],
            ['iccid' => '8925504500855150546', 'msisdn' => '255797028130'],
            ['iccid' => '8925504500855150538', 'msisdn' => '255798091325'],
            ['iccid' => '8925504500855150520', 'msisdn' => '255797087969'],
            ['iccid' => '8925504500855150512', 'msisdn' => '255798092134'],
            ['iccid' => '8925504500855150504', 'msisdn' => '255798091323'],
            ['iccid' => '8925504500855150488', 'msisdn' => '255798091321'],
            ['iccid' => '8925504500855150470', 'msisdn' => '255798091318'],
            ['iccid' => '8925504500855150496', 'msisdn' => '255798091322'],
            ['iccid' => '8925504500855150462', 'msisdn' => '255797053059'],
        ];

        foreach ($pairs as $p) {
            Esim::updateOrCreate(
                ['msisdn' => $p['msisdn']],
                [
                    'iccid' => $p['iccid'],
                    'network_id' => 1,
                    'status' => 'AVAILABLE',
                    'description' => 'Seeded inventory eSIM',
                ]
            );
        }
    }
}

