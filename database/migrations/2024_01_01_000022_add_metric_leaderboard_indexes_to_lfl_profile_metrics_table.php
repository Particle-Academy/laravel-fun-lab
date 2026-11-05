<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for per-metric leaderboards (`LFL::leaderboard()->metric($slug)`).
 *
 * The existing unique (profile_id, gamed_metric_id) index serves a lookup from a
 * profile. A leaderboard goes the other way: every row for ONE metric, sorted by
 * XP, or filtered by when it last moved. Without these, ranking one metric
 * scans and sorts every metric's rows.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('lfl.table_prefix', 'lfl_').'profile_metrics';
    }

    public function up(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->index(['gamed_metric_id', 'total_xp'], $this->tableName().'_metric_xp_index');
            $table->index(['gamed_metric_id', 'updated_at'], $this->tableName().'_metric_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->dropIndex($this->tableName().'_metric_xp_index');
            $table->dropIndex($this->tableName().'_metric_updated_index');
        });
    }
};
