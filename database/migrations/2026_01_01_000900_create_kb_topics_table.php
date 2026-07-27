<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_topics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('category')->default('General');
            $t->string('icon')->default('fa-book-open');
            $t->string('title');
            $t->text('body')->nullable();
            $t->json('roles')->nullable();   // e.g. ["all"] or ["admin","hr_manager"]
            $t->integer('sort')->default(0);
            $t->timestamps();

            $t->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_topics');
    }
};
