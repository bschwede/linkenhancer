<?php
declare(strict_types=1);

// Baustufe-1-Smoke (ISOLATED): Migration5-Logik gegen eine temp SQLite-DB prüfen
// (CREATE le_uid_index + ALTER le_index_meta + Idempotenz). Kein webtrees-Config
// und keine Instanz-DB nötig. Hinweis: ->after() wird von SQLite ignoriert
// (Spalten-Reihenfolge wird hier NICHT geprüft); in Produktion (MariaDB/MySQL/
// PostgreSQL) gilt die dortige Positionierung.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../src/Schema/Migration5.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Capsule\Manager as DB;
use Schwendinger\Webtrees\Module\LinkEnhancer\Schema\Migration5;

$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo 'driver: ' . DB::connection()->getDriverName() . PHP_EOL;

// Simulate the le_index_meta that Migration3 (3->4) already created, so the
// ALTER branch of Migration5 is actually exercised.
DB::schema()->create('le_index_meta', static function ($t): void {
    $t->integer('id')->default(1)->primary();
    $t->timestamp('last_run', 0)->nullable();
    $t->integer('rows')->default(0);
});

echo '--- Run 1 ---' . PHP_EOL;
(new Migration5())->upgrade();

echo 'le_uid_index exists: ' . var_export(DB::schema()->hasTable('le_uid_index'), true) . PHP_EOL;
foreach (['id', 'file', 'xref', 'uid', 'rectype', 'tag_path', 'hash'] as $col) {
    echo '  le_uid_index.' . $col . ': ' . var_export(DB::schema()->hasColumn('le_uid_index', $col), true) . PHP_EOL;
}
echo 'le_index_meta.uid_last_run:  ' . var_export(DB::schema()->hasColumn('le_index_meta', 'uid_last_run'), true) . PHP_EOL;
echo 'le_index_meta.uid_rows:      ' . var_export(DB::schema()->hasColumn('le_index_meta', 'uid_rows'), true) . PHP_EOL;
echo 'le_index_meta.last_run (Link, intakt): ' . var_export(DB::schema()->hasColumn('le_index_meta', 'last_run'), true) . PHP_EOL;
echo 'le_index_meta.rows (Link, intakt):     ' . var_export(DB::schema()->hasColumn('le_index_meta', 'rows'), true) . PHP_EOL;

echo '--- Run 2 (Idempotenz) ---' . PHP_EOL;
(new Migration5())->upgrade();
echo 'Run 2 ok (kein Fehler, kein Duplikat)' . PHP_EOL;

echo 'SMOKE OK' . PHP_EOL;
