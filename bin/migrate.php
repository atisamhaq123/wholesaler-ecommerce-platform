<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\Models\DB;
DB::query('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
foreach(glob(ROOT.'/database/migrations/*.sql') as $file) {
    $name=basename($file);
    if(DB::one('SELECT name FROM migrations WHERE name=?',[$name])) continue;
    foreach(explode(';',file_get_contents($file)) as $sql) if(trim($sql)) DB::query($sql);
    DB::query('INSERT INTO migrations(name) VALUES(?)',[$name]);
    echo "Applied $name\n";
}
