<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps(6);
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps(6);
        });

        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('records')->nullOnDelete();
            $table->string('name');
            $table->string('status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestamps(6);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->timestamps(6);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps(6);
        });

        Schema::create('record_tag', function (Blueprint $table) {
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['record_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('records');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('countries');
    }
};
