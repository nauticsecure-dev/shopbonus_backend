<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multilingual CMS: pages with immutable revisions, legal versions, FAQ and
 * media (Trello #5 "Page types, legal content & FAQ").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 60)->unique();          // stable identifier, e.g. "terms"
            $table->string('type', 16);                   // privacy | terms | cookie_policy | help | faq | info | landing
            $table->string('audience', 12)->default('public');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('cms_revisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->string('locale', 5);
            $table->unsignedInteger('number');
            $table->json('content');                      // {title, seo_title, seo_description, body}
            $table->string('legal_version', 64)->nullable();
            $table->date('effective_date')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('published_by');
            $table->unsignedBigInteger('restored_from_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['page_id', 'locale', 'number']);
        });

        Schema::create('cms_page_locales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->string('locale', 5);
            $table->string('slug', 100);
            $table->json('draft_content')->nullable();
            $table->unsignedInteger('draft_revision')->default(1);
            $table->timestamp('draft_updated_at')->nullable();
            $table->unsignedBigInteger('draft_updated_by')->nullable();
            $table->foreignId('published_revision_id')->nullable()->constrained('cms_revisions')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        Schema::create('cms_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('path', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });

        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 60)->unique();
            $table->json('names');                        // {nl, en}
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->constrained('faq_categories')->restrictOnDelete();
            $table->string('audience', 12)->default('public'); // public | consumers | merchants
            $table->boolean('published')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->json('translations');                 // {nl: {question, answer}, en: {...}}
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['published', 'audience', 'position']);
        });
    }

    public function down(): void
    {
        foreach (['faq_items', 'faq_categories', 'cms_assets', 'cms_page_locales', 'cms_revisions', 'cms_pages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
