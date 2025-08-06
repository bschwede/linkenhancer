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
                $table->string('path', 100)->default('');
                $table->string('handler', 150)->default('');
                $table->string('method', 20)->default('');
                $table->string('extras', 60)->default('');
                $table->string('category', 60)->default('');
                $table->integer('order', false)->default(10);
                $table->string('url', 250)->default('');
                $table->index(['path', 'handler']);
                $table->timestamp('updated_at', 0)->nullable();
            });
        }
    }

}
