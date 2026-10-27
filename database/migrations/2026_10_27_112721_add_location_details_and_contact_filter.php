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
        Schema::table('leads', function (Blueprint $table) {
            $table->string('town')->nullable()->after('location');
            $table->string('zip_code', 20)->nullable()->after('town');
            $table->string('country')->nullable()->after('zip_code');
        });

        Schema::table('lead_requests', function (Blueprint $table) {
            $table->boolean('only_with_contact')->default(false)->after('max_results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['town', 'zip_code', 'country']);
        });

        Schema::table('lead_requests', function (Blueprint $table) {
            $table->dropColumn('only_with_contact');
        });
    }
};
