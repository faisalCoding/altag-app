<?php

use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('streams the current database as a plain file download for a manager', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    // Point the sqlite path at a throwaway database so the download has something
    // to copy (the test connection itself runs in :memory:, which has no file).
    $tempDb = storage_path('app/testing_current_db.sqlite');
    File::delete($tempDb);
    (new PDO('sqlite:'.$tempDb))->exec('create table notes (id integer primary key)');
    config(['database.connections.sqlite.database' => $tempDb]);

    $response = $this->get(route('manager.backup.download'));

    $response->assertOk();
    $response->assertDownload();
    expect($response->headers->get('content-disposition'))->toContain('.sqlite');

    File::delete($tempDb);
});

it('streams a stored backup file as a download for a manager', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    $backupDir = storage_path('app/backups');
    File::ensureDirectoryExists($backupDir);
    $name = 'manual_2026-01-01_00-00-00.sqlite';
    File::put($backupDir.'/'.$name, 'SQLite format 3'."\0");

    $response = $this->get(route('manager.backup.download.stored', ['filename' => $name]));

    $response->assertOk();
    $response->assertDownload($name);

    File::delete($backupDir.'/'.$name);
});

it('does not serve files outside the backups directory via a traversal filename', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    File::ensureDirectoryExists(storage_path('app/backups'));
    $secret = storage_path('app/outside_secret.txt');
    File::put($secret, 'TOP-SECRET-CONTENTS');

    $response = $this->get('/manager/backup/download/'.rawurlencode('../outside_secret.txt'));

    $response->assertNotFound();
    expect($response->baseResponse->getContent())->not->toContain('TOP-SECRET-CONTENTS');

    File::delete($secret);
});

it('returns 404 for a stored backup that does not exist', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    $response = $this->get(route('manager.backup.download.stored', ['filename' => 'nope.sqlite']));

    $response->assertNotFound();
});

it('blocks guests from downloading the current database', function () {
    $this->get(route('manager.backup.download'))->assertRedirect();
});
