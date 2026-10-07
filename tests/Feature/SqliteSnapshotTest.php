<?php

use App\Models\Manager;
use App\Support\SqliteSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * The live database runs in WAL mode. A plain copy of its file misses what
 * still sits in the WAL, and copying a file over it let the old WAL be
 * replayed onto the new one. Both are done by SQLite itself now.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/snapshot-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    $this->live = $this->dir.'/live.sqlite';
    touch($this->live);

    config(['database.connections.snapshot_live' => [
        'driver' => 'sqlite',
        'database' => $this->live,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'journal_mode' => 'wal',
        'busy_timeout' => 5000,
    ]]);

    DB::connection('snapshot_live')->statement('create table notes (id integer primary key, body text)');
    DB::connection('snapshot_live')->table('notes')->insert(['body' => 'written into the WAL']);
});

afterEach(function () {
    DB::purge('snapshot_live');
    File::deleteDirectory($this->dir);
});

function readNotes(string $path): array
{
    $pdo = new PDO('sqlite:'.$path);

    return [
        $pdo->query('pragma quick_check')->fetchColumn(),
        $pdo->query('select body from notes')->fetchAll(PDO::FETCH_COLUMN),
    ];
}

it('copies what still sits in the WAL, which a plain copy of the file misses', function () {
    // The bug: the main file alone does not hold the table yet.
    copy($this->live, $this->dir.'/plain.sqlite');
    expect(fn () => readNotes($this->dir.'/plain.sqlite'))->toThrow(PDOException::class);

    SqliteSnapshot::to($this->dir.'/snapshot.sqlite', $this->live);

    expect(readNotes($this->dir.'/snapshot.sqlite'))->toBe(['ok', ['written into the WAL']]);
});

it('puts a backup in place without replaying the old WAL onto it', function () {
    $backup = $this->dir.'/backup.sqlite';
    $pdo = new PDO('sqlite:'.$backup);
    $pdo->exec('create table notes (id integer primary key, body text)');
    $pdo->exec("insert into notes (body) values ('from the backup')");
    $pdo = null;

    SqliteSnapshot::replaceWith($backup, 'snapshot_live');

    expect(DB::connection('snapshot_live')->table('notes')->pluck('body')->all())->toBe(['from the backup']);
    expect(readNotes($this->live))->toBe(['ok', ['from the backup']]);
});

it('downloads a whole snapshot of the database, not the live file', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');
    config(['database.connections.sqlite.database' => $this->live]);

    $response = $this->get(route('manager.backup.download'))->assertOk()->assertDownload();
    $file = $response->baseResponse->getFile()->getPathname();

    expect($file)->not->toBe($this->live);
    expect(readNotes($file))->toBe(['ok', ['written into the WAL']]);

    File::delete($file);
});

it('serves the manifest without opening a session', function () {
    $before = DB::table('sessions')->count();

    $this->get('/manifest.json')
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));

    expect(DB::table('sessions')->count())->toBe($before);
});
