<?php
namespace SahdevConnector;

use PDO;
use RuntimeException;

/** Read the installed MySQL schema. No source tables are created, altered or written. */
final class LegacySource
{
    private PDO $database;

    public function __construct(PDO $database)
    {
        $this->database = $database;
    }

    public function records(): \Generator
    {
        $db = $this->database;
        $stringify = $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $started = false;
        try {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $started = true;
            $tables = $this->tables();
            foreach ($tables as $table) {
                $name = $this->identifier($table['name']);
                $schema = $db->query('SHOW CREATE TABLE '.$name);
                $ddl = $schema->fetch(PDO::FETCH_NUM)[1] ?? null;
                $schema->closeCursor();
                if (!is_string($ddl)) {
                    throw new RuntimeException('The source table definition could not be read.');
                }
                $columnCursor = $db->query('SHOW FULL COLUMNS FROM '.$name);
                $columns = array_column($columnCursor->fetchAll(PDO::FETCH_ASSOC),'Field');
                $columnCursor->closeCursor();
                // Explicitly enumerate columns, including ones declared INVISIBLE.
                $quoted = array_map(static function (string $column): string {
                    return '`'.str_replace('`','``',$column).'`';
                }, $columns);
                $query = 'SELECT '.implode(',',$quoted).' FROM '.$name;
                if ($table['name'] === 'tbladdonmodules') {
                    $query .= " WHERE BINARY module = 'sahdev'";
                }
                $rows = $db->query($query);
                try {
                    yield ['table'=>$table['name'], 'ddl'=>$ddl, 'columns'=>$columns];
                    $count = 0;
                    while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                        // Preserve NULL, binary, zero dates, exact decimals and large integers across engines.
                        yield ['row'=>array_map(static function ($value) {
                            return $value === null ? null : base64_encode((string) $value);
                        }, $row)];
                        $count++;
                    }
                    yield ['end'=>$table['name'], 'rows'=>$count];
                } finally {
                    $rows->closeCursor();
                }
            }
            if ($tables !== $this->tables()) {
                throw new RuntimeException('The source table inventory changed during capture. Retry after the upgrade finishes.');
            }
        } finally {
            // PDO does not track a transaction started by SQL consistently across MySQL drivers.
            if ($started) $db->exec('ROLLBACK');
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify);
        }
    }

    private function tables(): array
    {
        $cursor = $this->database->query(
            "SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES "
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            ."AND (LEFT(TABLE_NAME,10) = 'tblsahdev_' OR TABLE_NAME = 'tbladdonmodules') ORDER BY TABLE_NAME"
        );
        $tables = $cursor->fetchAll(PDO::FETCH_ASSOC);
        $cursor->closeCursor();
        if (!array_filter($tables, static function (array $table): bool { return $table['name'] !== 'tbladdonmodules'; })) {
            throw new RuntimeException('No original Sahdev tables were found.');
        }
        foreach ($tables as $table) {
            $this->identifier($table['name']);
            if (strcasecmp((string) $table['engine'],'InnoDB') !== 0) {
                throw new RuntimeException('A source table is not transactional: '.$table['name'].'. Capture from an InnoDB staging copy.');
            }
        }
        return $tables;
    }

    private function identifier(string $table): string
    {
        if ($table !== 'tbladdonmodules' && !preg_match('/^tblsahdev_[a-zA-Z0-9_]{1,54}$/D',$table)) {
            throw new RuntimeException('Unexpected source table identifier.');
        }
        return '`'.$table.'`';
    }
}
