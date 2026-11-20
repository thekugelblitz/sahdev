<?php
/** Disposable loopback MariaDB fixture. The test creates a dedicated random database. */
require __DIR__.'/../../connector/sahdev_cloud/src/LegacySource.php';

$port = filter_var(getenv('SAHDEV_LEGACY_MYSQL_PORT') ?: '3306', FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
if (!$port) throw new RuntimeException('Invalid fixture port.');
$base = 'mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4';
$database = 'sahdev_legacy_test_'.bin2hex(random_bytes(8));
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$user = getenv('SAHDEV_LEGACY_MYSQL_USER') ?: 'root';
$password = getenv('SAHDEV_LEGACY_MYSQL_PASSWORD') ?: '';
$admin = new PDO($base,$user,$password,$options);
$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer = new PDO($base.';dbname='.$database,$user,$password,$options);
    $reader = new PDO($base.';dbname='.$database,$user,$password,$options);
    $writer->exec('CREATE TABLE tblsahdev_settings (big_id BIGINT UNSIGNED, amount DECIMAL(28,8), data VARBINARY(16), nullable_value VARCHAR(10), hidden VARCHAR(20) INVISIBLE) ENGINE=InnoDB');
    $writer->exec("INSERT INTO tblsahdev_settings (big_id,amount,data,nullable_value,hidden) VALUES (18446744073709551615,12345678901234567890.12345678,X'ff0031',NULL,'internal')");
    $writer->exec('INSERT INTO tblsahdev_settings (big_id,amount,data,nullable_value,hidden) SELECT big_id,amount,data,nullable_value,hidden FROM tblsahdev_settings');
    $writer->exec("CREATE TABLE tbladdonmodules (module VARCHAR(32), setting VARCHAR(32), value TEXT) ENGINE=InnoDB");
    $writer->exec("INSERT INTO tbladdonmodules VALUES ('sahdev','version','original'),('sahdev_cloud','version','new'),('unrelated','secret','exclude')");
    $writer->exec('CREATE TABLE tblsahdev_empty (id INT PRIMARY KEY) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE tblclients (id INT PRIMARY KEY) ENGINE=InnoDB');

    $found = [];
    $table = null;
    foreach ((new SahdevConnector\LegacySource($reader))->records() as $record) {
        if (isset($record['table'])) {
            $table = $record['table'];
            $found[$table] = ['columns'=>$record['columns'],'rows'=>[]];
            if ($table === 'tbladdonmodules') $writer->exec("UPDATE tblsahdev_settings SET hidden='new value'");
        } elseif (isset($record['row'])) {
            $found[$table]['rows'][] = array_map(static fn ($value) => $value === null ? null : base64_decode($value,true),$record['row']);
        }
    }
    $expected = ['18446744073709551615','12345678901234567890.12345678',"\xff\x00\x31",null,'internal'];
    if (array_keys($found) !== ['tbladdonmodules','tblsahdev_empty','tblsahdev_settings']
        || $found['tbladdonmodules']['rows'] !== [['sahdev','version','original']]
        || $found['tblsahdev_settings']['columns'] !== ['big_id','amount','data','nullable_value','hidden']
        || $found['tblsahdev_settings']['rows'] !== [$expected,$expected] || $found['tblsahdev_empty']['rows'] !== []) {
        throw new RuntimeException('Legacy capture differs from the consistent source snapshot.');
    }
    echo "PASS MariaDB legacy capture preserves unsigned IDs, decimals, binary data, invisible columns, duplicate rows and snapshot consistency.\n";
    $writer->exec('CREATE TABLE tblsahdev_unsafe (id INT) ENGINE=MyISAM');
    try {
        iterator_to_array((new SahdevConnector\LegacySource($reader))->records());
        throw new LogicException('Expected nontransactional table rejection.');
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(),'not transactional') === false) throw $error;
    }
    echo "PASS nontransactional source is rejected.\n";
} finally {
    $admin->exec('DROP DATABASE `'.$database.'`');
}
