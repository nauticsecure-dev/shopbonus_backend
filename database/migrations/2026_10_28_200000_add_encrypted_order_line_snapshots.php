<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_orders', fn (Blueprint $t) => $t->longText('line_items')->nullable());
        Schema::table('order_events', fn (Blueprint $t) => $t->longText('line_items_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('order_events', fn (Blueprint $t) => $t->dropColumn('line_items_snapshot'));
        Schema::table('webshop_orders', fn (Blueprint $t) => $t->dropColumn('line_items'));
    }
};
