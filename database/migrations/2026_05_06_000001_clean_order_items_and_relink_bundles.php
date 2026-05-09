<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('order_items')->orderBy('id')->get();

        if ($legacy->isEmpty()) {
            return;
        }

        $bundles = DB::table('bundles')->whereIn('alias', ['Nomad', 'Explorer', 'Traveller', 'Starter'])->get();
        if ($bundles->isEmpty()) {
            // Nothing to link to; skip destructive clean.
            return;
        }

        $byAlias = $bundles->keyBy('alias');

        $now = now();

        $newRows = [];

        foreach ($legacy as $row) {
            $type = $row->type;
            $resolved = null;

            if ($type === 'bundle') {
                $resolved = $this->resolveBundle(
                    $row->bundle_name,
                    (float) $row->price,
                    (string) $row->currency,
                    $bundles,
                    $byAlias
                );
            }

            $newRows[] = [
                'order_id' => $row->order_id,
                'type' => $type,
                'bundle_id' => $resolved?->id,
                'bundle_name' => $resolved ? $resolved->name : $row->bundle_name,
                'data_amount' => $resolved ? $resolved->data_mb : $row->data_amount,
                'validity_days' => $resolved ? (int) $resolved->validity_days : $row->validity_days,
                'price' => $row->price,
                'currency' => $row->currency,
                'metadata' => $row->metadata,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ];
        }

        DB::table('order_items')->delete();

        foreach (array_chunk($newRows, 100) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Destructive remap; no safe automatic restore.
    }

    /**
     * @param  Collection<int, object>  $bundles
     * @param  Collection<string, object>  $byAlias
     */
    private function resolveBundle(
        ?string $bundleName,
        float $linePrice,
        string $lineCurrency,
        Collection $bundles,
        Collection $byAlias
    ): ?object {
        $n = strtolower(trim((string) $bundleName));

        $aliasHints = [
            'nomad' => 'Nomad',
            'explorer' => 'Explorer',
            'traveller' => 'Traveller',
            'traveler' => 'Traveller',
            'starter' => 'Starter',
        ];

        foreach ($aliasHints as $needle => $alias) {
            if ($n !== '' && str_contains($n, $needle)) {
                return $byAlias->get($alias);
            }
        }

        foreach ($bundles as $b) {
            if ($bundleName !== null && $bundleName !== '' && strcasecmp(trim($bundleName), $b->name) === 0) {
                return $b;
            }
        }

        if ($bundleName !== null && preg_match('/(\d+(?:\.\d+)?)\s*gb/i', $bundleName, $m)) {
            $gb = (float) $m[1];
            foreach ($bundles as $b) {
                $size = isset($b->bundle_size) ? (float) $b->bundle_size : null;
                if ($size !== null && abs($size - $gb) < 0.001) {
                    return $b;
                }
                if (isset($b->data_mb) && abs(((int) $b->data_mb) / 1024 - $gb) < 0.001) {
                    return $b;
                }
            }
        }

        $sameCurrency = $bundles->filter(fn ($b) => $b->currency === $lineCurrency);
        $pool = $sameCurrency->isNotEmpty() ? $sameCurrency : $bundles;

        $best = null;
        $bestDiff = null;

        foreach ($pool as $b) {
            $diff = abs((float) $b->price - $linePrice);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $b;
            }
        }

        return $best;
    }
};
