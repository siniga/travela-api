<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (! Schema::hasColumn('user_esims', 'bundle_id')) {
                $table->foreignId('bundle_id')->nullable()->after('esim_id')->constrained('bundles')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_esims', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('bundle_id')->constrained('orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_esims', 'order_item_id')) {
                $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
            }
        });

        $this->backfillExistingAssignments();
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (Schema::hasColumn('user_esims', 'order_item_id')) {
                $table->dropConstrainedForeignId('order_item_id');
            }
            if (Schema::hasColumn('user_esims', 'order_id')) {
                $table->dropConstrainedForeignId('order_id');
            }
            if (Schema::hasColumn('user_esims', 'bundle_id')) {
                $table->dropConstrainedForeignId('bundle_id');
            }
        });
    }

    private function backfillExistingAssignments(): void
    {
        $assignments = DB::table('user_esims')->select('id', 'user_id')->get();

        foreach ($assignments as $row) {
            $orderId = DB::table('orders')
                ->where('user_id', $row->user_id)
                ->where('payment_status', 'paid')
                ->orderByDesc('id')
                ->value('id');

            if (! $orderId) {
                continue;
            }

            $item = DB::table('order_items')
                ->where('order_id', $orderId)
                ->where('type', 'bundle')
                ->whereNotNull('bundle_id')
                ->orderBy('id')
                ->first(['id', 'bundle_id']);

            if (! $item) {
                DB::table('user_esims')->where('id', $row->id)->update(['order_id' => $orderId]);

                continue;
            }

            DB::table('user_esims')->where('id', $row->id)->update([
                'order_id' => $orderId,
                'bundle_id' => $item->bundle_id,
                'order_item_id' => $item->id,
            ]);
        }
    }
};
