<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Copying and replacing the live SQLite database safely.
 *
 * The database runs in WAL mode: recent writes sit in database.sqlite-wal
 * until SQLite folds them into the main file. A plain copy() of the main file
 * misses them, and taken while SQLite is folding them in, it comes out torn —
 * "database disk image is malformed". Worse, copying a file over the live one
 * leaves the old -wal and -shm beside it, and SQLite replays the old database's
 * writes onto the new file: that corrupts the live database, and a damaged
 * sessions index makes the site lose signed-in users at random.
 *
 * So a copy is taken by SQLite itself (VACUUM INTO: whole and consistent, safe
 * while the site runs), and a restore empties the WAL first and swaps the new
 * file in with a rename, never leaving an old -wal behind.
 */
final class SqliteSnapshot
{
    /**
     * A consistent copy of the database file at $path, which must not exist.
     * Read over a connection of its own: VACUUM cannot run inside a
     * transaction, and the request's own connection may be in one.
     */
    public static function to(string $path, ?string $database = null): void
    {
        $database ??= config('database.connections.sqlite.database');

        if (file_exists($path)) {
            throw new RuntimeException("Snapshot target already exists: {$path}");
        }

        $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->prepare('VACUUM INTO ?')->execute([$path]);
    }

    /**
     * Put $source in place of the live database. Its writes in the WAL are
     * folded in and the WAL emptied first, the connection closed, the stale
     * -wal and -shm removed, and the new file renamed into place in one step.
     *
     * Best done while nobody else is using the site: another request holding
     * the old file open at that moment may still write to it.
     */
    public static function replaceWith(string $source, string $connection = 'sqlite'): void
    {
        $live = config("database.connections.{$connection}.database");
        $staging = $live.'.restoring';

        if (! copy($source, $staging)) {
            throw new RuntimeException('Could not stage the restore.');
        }

        DB::connection($connection)->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        DB::disconnect($connection);

        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($live.$suffix)) {
                unlink($live.$suffix);
            }
        }

        if (! rename($staging, $live)) {
            throw new RuntimeException('Could not put the restored database in place.');
        }

        DB::reconnect($connection);
    }
}
