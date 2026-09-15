<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WithSlateSourcesCredentials;

uses(LazilyRefreshDatabase::class);
uses(WithSlateSourcesCredentials::class);

test('the package purpose migration owns the fresh purpose column and revokes pre-purpose credentials', function (): void {
    $credential = $this->mintCredential();
    $migrationPath = base_path('vendor/artisan-build/built-for-cloud/database/migrations/2026_09_14_000001_add_purpose_to_credentials_table.php');

    Schema::table('credentials', function (Blueprint $table): void {
        $table->dropColumn('purpose');
    });
    DB::table('credentials')->where('id', $credential->credential->id)->update(['revoked_at' => null]);

    $migration = require $migrationPath;
    $migration->up();

    self::assertTrue(Schema::hasColumn('credentials', 'purpose'));
    self::assertNotNull(DB::table('credentials')->where('id', $credential->credential->id)->value('revoked_at'));
    self::assertFileDoesNotExist(base_path('database/migrations/2026_09_14_000001_add_purpose_to_credentials_table.php'));
    self::assertSame(
        '2026_09_14_000001_add_purpose_to_credentials_table',
        DB::table('migrations')->where('migration', '2026_09_14_000001_add_purpose_to_credentials_table')->value('migration'),
    );
    self::assertSame(1, Credential::query()->whereKey($credential->credential->id)->count());
});
