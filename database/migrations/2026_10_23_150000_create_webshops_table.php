<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One webshop = one isolated workspace (Trello #2 §3, #16). Every
 * webshop-owned record elsewhere references `webshop_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webshops', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 150);
            // Ecommerce platform key (e.g. shopify, woocommerce); adapters arrive in B04.
            $table->string('platform', 32)->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webshop_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 16);
            // Extra grants on top of the role defaults; never more than the granter held.
            $table->json('permissions')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            // One membership row per user per webshop; removal is a status, not a delete.
            $table->unique(['webshop_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Server-side "active workspace" (workspace switcher). Always re-validated on use.
            $table->foreignId('current_webshop_id')->nullable()->after('locale')
                ->constrained('webshops')->nullOnDelete();
        });

        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 32);
            $table->string('granted_via', 32); // console | (future: admin workflow)
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'role', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_webshop_id');
        });

        Schema::dropIfExists('webshop_memberships');
        Schema::dropIfExists('webshops');
    }
};
