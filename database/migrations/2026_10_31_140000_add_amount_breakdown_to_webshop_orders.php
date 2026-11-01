<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('webshop_orders', 'amount_breakdown')) {
                $table->json('amount_breakdown')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('webshop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('webshop_orders', 'amount_breakdown')) {
                $table->dropColumn('amount_breakdown');
            }
        });
    }
};
