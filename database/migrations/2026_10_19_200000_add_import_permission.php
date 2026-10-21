<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_import_files')->nullable();
        });
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_import_files'));
    }
};
