<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Identity fields from Trello #4. Emails are stored lower-cased by the model,
 * so the existing unique index enforces case-insensitive uniqueness on every
 * database driver, including under concurrent signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'first_name');
        });

        Schema::table('users', function (Blueprint $table) {
            // Public identifier for URLs/API payloads; the integer id stays internal.
            $table->ulid('public_id')->nullable()->after('id');
            $table->string('status', 16)->default('active')->after('password')->index();
            // What the user said they came for at signup. Grants nothing by itself.
            $table->string('onboarding_intent', 16)->default('consumer')->after('status');
            $table->string('locale', 5)->default('nl')->after('onboarding_intent');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });

        DB::table('users')->whereNull('public_id')->orderBy('id')->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::ulid()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'status', 'onboarding_intent', 'locale', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('first_name', 'name');
        });
    }
};
