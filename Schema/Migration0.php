<?php

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Schema;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Fisharebest\Webtrees\Schema\MigrationInterface;

/**
 * Upgrade the database schema from version 0 (empty database) to version 1.
 */
class Migration0 implements MigrationInterface
{

    public function upgrade(): void
    {

        if (!DB::schema()->hasTable('route_help_map')) {
            DB::schema()->create('route_help_map', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('path', 100)->nullable();
                $table->string('handler', 150)->nullable();
                $table->string('method', 20)->nullable();
                $table->string('extras', 60)->nullable();
                $table->string('category', 60);
                $table->integer('order', false);
                $table->string('url', 250)->nullable();
                $table->index(['path', 'handler']);
            });
        }
    }

}
