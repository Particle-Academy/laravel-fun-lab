<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `is_hidden` to the achievements table. A hidden ("secret") achievement is
 * omitted from public catalog listings until the awardable has actually earned
 * it — for Easter eggs and surprise rewards. Use the `scopeVisible` /
 * `scopeVisibleTo` scopes on the Achievement model to honour it.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('lfl.table_prefix', 'lfl_').'achievements';
    }

    public function up(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_active');
            $table->index('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->dropIndex([$this->tableName(), 'is_hidden']);
            $table->dropColumn('is_hidden');
        });
    }
};
