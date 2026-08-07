<?php

use App\Models\Bundle;
use App\Support\BundleVisibility;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Bundle::query()->each(function (Bundle $bundle) {
            if (! BundleVisibility::isAdminOnlyBundle($bundle)) {
                return;
            }

            $metadata = is_array($bundle->metadata) ? $bundle->metadata : [];
            if (($metadata['admin_only'] ?? false) === true) {
                return;
            }

            $metadata['admin_only'] = true;
            $bundle->update(['metadata' => $metadata]);
        });
    }

    public function down(): void
    {
        Bundle::query()->each(function (Bundle $bundle) {
            $metadata = is_array($bundle->metadata) ? $bundle->metadata : [];
            if (! array_key_exists('admin_only', $metadata)) {
                return;
            }

            unset($metadata['admin_only']);
            $bundle->update(['metadata' => $metadata]);
        });
    }
};
