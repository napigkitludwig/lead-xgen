<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lead_requests', function (Blueprint $table) {
            $table->string('scraping_method')->default('playwright')->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_requests', function (Blueprint $table) {
            $table->dropColumn('scraping_method');
        });
    }
};
