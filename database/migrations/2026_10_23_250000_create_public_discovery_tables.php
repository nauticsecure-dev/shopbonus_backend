<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Public discovery read model (Trello #18 "Webshop discovery", "Webshop
 * detail page"): only merchant-approved public fields, readable slugs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webshop_public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->unique()->constrained()->restrictOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('display_name', 120);
            $table->string('short_description', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('logo_path', 255)->nullable();   // private disk; served by the API
            $table->string('logo_mime', 32)->nullable();
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();
            $table->timestamps();

            $table->index(['published', 'display_name']);
        });

        Schema::create('webshop_public_categories', function (Blueprint $table) {
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('category', 40);

            $table->primary(['webshop_id', 'category']);
            $table->index('category');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->unique()->after('public_id');
        });

        DB::table('offers')->whereNull('slug')->orderBy('id')->each(function ($offer) {
            $name = DB::table('offer_versions')->where('id', $offer->current_version_id)->value('name') ?? 'offer';
            DB::table('offers')->where('id', $offer->id)->update(['slug' => Str::limit(Str::slug($name), 60, '').'-'.Str::lower(Str::random(6))]);
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
        Schema::dropIfExists('webshop_public_categories');
        Schema::dropIfExists('webshop_public_profiles');
    }
};
