<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * "Data package": a git-friendly snapshot of the working database (every table, as gzipped JSON) plus the generated
 * invoice and uploaded source files, so teammates get the existing invoice progress with `php artisan migrate --seed`.
 *
 * Layout of the package folder (default database/seed-data):
 *   manifest.json                 format, migrations, per-table row count + sha256, per-file size + sha256
 *   tables/<table>.json.gz        rows exactly as stored (JSON text columns stay strings)
 *   files/<relative path>         copies of the local-disk files under FILE_DIRECTORIES
 *
 * Works with table names and the query builder only, so it does not depend on any Eloquent model.
 */
class SeedDataPackage
{
    public const FORMAT_VERSION = 1;

    /** Framework / runtime tables that never belong in a package. */
    public const EXCLUDED_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'];

    /** Folders on the 'local' disk that are packaged (billing/previews is dev-only and deliberately left out). */
    public const FILE_DIRECTORIES = ['billing/invoices', 'billing/sources'];

    public const FILE_DISK = 'local';

    /** Password every imported user gets; the package never carries real credentials. */
    public const DEFAULT_PASSWORD = 'password';

    /** The only users columns that are exported (no password, remember_token, two_factor_* ...). */
    private const USER_COLUMNS = ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** SQLite allows 999 bound variables on old builds; stay below that per INSERT. */
    private const MAX_BINDINGS = 900;

    /** Also flush an INSERT once this much row data is buffered, so tables with huge JSON columns stay light on memory. */
    private const MAX_CHUNK_BYTES = 8 * 1024 * 1024;

    /** Overrides the default folder (used by tests so they never touch the real database/seed-data). */
    private static ?string $directory = null;

    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
    }

    public static function directory(): string
    {
        return self::$directory ?? database_path('seed-data');
    }

    public static function exists(?string $dir = null): bool
    {
        return is_file(self::folder($dir).'/manifest.json');
    }

    /**
     * Writes the whole current database and the packaged files into $dir (replacing any previous package there).
     *
     * @return array{directory: string, tables: int, rows: int, files: int, bytes: int}
     */
    public function export(?string $dir = null): array
    {
        $dir = self::folder($dir);
        File::ensureDirectoryExists($dir);
        // Without a manifest a half-written package can never be mistaken for a valid one.
        File::delete($dir.'/manifest.json');
        File::deleteDirectory($dir.'/tables');
        File::deleteDirectory($dir.'/files');
        File::ensureDirectoryExists($dir.'/tables');

        $bytes = 0;
        $rows = 0;
        $tables = [];
        foreach (self::packagedTables() as $table) {
            [$gz, $count] = $this->encodeTable($table);
            $target = $dir.'/tables/'.$table.'.json.gz';
            if (file_put_contents($target, $gz) === false) {
                throw new RuntimeException("Gagal menulis {$target}.");
            }
            $tables[$table] = ['rows' => $count, 'sha256' => hash('sha256', $gz)];
            $rows += $count;
            $bytes += strlen($gz);
        }

        $files = $this->copyFilesOut($dir);
        $bytes += array_sum(array_column($files, 'size'));

        $migrations = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        sort($migrations);
        $manifest = [
            'format' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'migrations' => $migrations,
            'tables' => $tables ?: new \stdClass,
            'files' => $files ?: new \stdClass,
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | self::JSON_FLAGS)."\n";
        if (file_put_contents($dir.'/manifest.json', $json) === false) {
            throw new RuntimeException("Gagal menulis {$dir}/manifest.json.");
        }

        return ['directory' => $dir, 'tables' => count($tables), 'rows' => $rows, 'files' => count($files), 'bytes' => $bytes + strlen($json)];
    }

    /**
     * Replaces the packaged tables of the current database with the package contents and restores the packaged files.
     *
     * @return array{tables: array<string, int>, rows: int, files: int, warnings: list<string>, periods: list<array{period_code: string, status: string, lines: int, grand_total: ?string}>}
     */
    public function import(?string $dir = null): array
    {
        $dir = self::folder($dir);
        $manifest = $this->readManifest($dir);
        $this->verify($dir, $manifest);
        $warnings = $this->assertMigrations($manifest);

        $load = [];
        foreach (array_keys($manifest['tables']) as $table) {
            if (! preg_match('/^\w+$/', $table) || in_array($table, self::EXCLUDED_TABLES, true)) {
                throw new RuntimeException("Nama tabel tidak valid di manifest: {$table}.");
            }
            if (Schema::hasTable($table)) {
                $load[] = $table;
            } else {
                $warnings[] = "Tabel {$table} ada di paket tetapi tidak ada di database ini; dilewati.";
            }
        }
        $order = $this->orderTables($load);

        // One shared hash: bcrypt is slow on purpose and every packaged user gets the same default password.
        $passwordHash = in_array('users', $order, true) ? Hash::make(self::DEFAULT_PASSWORD) : null;

        $loaded = [];
        $run = function () use ($dir, $order, $passwordHash, &$loaded, &$warnings): void {
            foreach (array_reverse($order) as $table) {
                DB::table($table)->delete();
            }
            foreach ($order as $table) {
                $loaded[$table] = $this->loadTable($dir, $table, $passwordHash, $warnings);
            }
        };

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::transaction(function () use ($run): void {
                // Parents are inserted first anyway; deferring covers circular references and checks everything at the end.
                DB::statement('PRAGMA defer_foreign_keys = ON');
                $run();
                $this->assertNoForeignKeyViolations();
            });
        } else {
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction($run));
        }

        // Files go back only after the database committed, so a failed import never leaves half-restored state behind a rollback.
        $restored = $this->restoreFiles($dir, array_keys($manifest['files']));

        return [
            'tables' => $loaded,
            'rows' => array_sum($loaded),
            'files' => $restored,
            'warnings' => $warnings,
            'periods' => $this->periodSummary(),
        ];
    }

    /** Tables that go into a package, sorted by name. */
    public static function packagedTables(): array
    {
        $tables = array_filter(
            Schema::getTableListing(null, false),
            fn (string $table) => ! str_starts_with($table, 'sqlite_') && ! in_array($table, self::EXCLUDED_TABLES, true)
        );
        sort($tables);

        return array_values($tables);
    }

    // ------------------------------------------------------------------------------------------------ export

    /** @return array{0: string, 1: int} gzipped JSON array of the table's rows, and the row count */
    private function encodeTable(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        if ($table === 'users') {
            $columns = array_values(array_intersect($columns, self::USER_COLUMNS));
        }
        $query = DB::table($table)->select($columns);
        foreach ($this->orderColumns($table, $columns) as $column) {
            $query->orderBy($column);
        }

        // One gzip stream holding an ordinary JSON array, but with every row on its own line (json_encode escapes
        // newlines inside strings, so a raw newline only ever separates rows). Any JSON reader still sees one array,
        // while import() can read it row by row instead of decoding a 17 MB table at once.
        $zip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $gz = deflate_add($zip, "[\n", ZLIB_NO_FLUSH);
        $count = 0;
        try {
            foreach ($query->cursor() as $row) {
                $gz .= deflate_add($zip, ($count++ ? ",\n" : '').json_encode((array) $row, self::JSON_FLAGS), ZLIB_NO_FLUSH);
            }
        } catch (JsonException $e) {
            throw new RuntimeException("Tabel {$table} berisi data yang tidak bisa disimpan sebagai JSON: ".$e->getMessage(), 0, $e);
        }

        return [$gz.deflate_add($zip, "\n]", ZLIB_FINISH), $count];
    }

    /** Primary key columns, or every column for a table without a primary key. */
    private function orderColumns(string $table, array $columns): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] ?? false) {
                return $index['columns'];
            }
        }

        return $columns;
    }

    /** @return array<string, array{size: int, sha256: string}> */
    private function copyFilesOut(string $dir): array
    {
        $disk = Storage::disk(self::FILE_DISK);
        $paths = [];
        foreach (self::FILE_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths);

        $files = [];
        foreach ($paths as $path) {
            $source = $disk->readStream($path);
            if (! is_resource($source)) {
                throw new RuntimeException("Berkas {$path} tidak bisa dibaca dari storage.");
            }
            $target = $dir.'/files/'.$path;
            File::ensureDirectoryExists(dirname($target));
            $out = fopen($target, 'wb');
            stream_copy_to_stream($source, $out);
            fclose($source);
            fclose($out);
            $files[$path] = ['size' => filesize($target), 'sha256' => hash_file('sha256', $target)];
        }

        return $files;
    }

    // ------------------------------------------------------------------------------------------------ import

    private function readManifest(string $dir): array
    {
        $file = $dir.'/manifest.json';
        if (! is_file($file)) {
            throw new RuntimeException("Paket data tidak ditemukan: {$file} tidak ada.");
        }
        try {
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('manifest.json tidak bisa dibaca: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT_VERSION || ! is_array($manifest['tables'] ?? null) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Format manifest.json tidak dikenali (butuh format '.self::FORMAT_VERSION.').');
        }
        $manifest['migrations'] = is_array($manifest['migrations'] ?? null) ? $manifest['migrations'] : [];

        return $manifest;
    }

    /** Every table and file must be there and match its recorded sha256. */
    private function verify(string $dir, array $manifest): void
    {
        foreach ($manifest['tables'] as $table => $info) {
            $this->assertChecksum($dir.'/tables/'.$table.'.json.gz', "tables/{$table}.json.gz", $this->checksumOf($info));
        }
        foreach ($manifest['files'] as $path => $info) {
            $this->assertSafeFilePath((string) $path);
            $this->assertChecksum($dir.'/files/'.$path, "files/{$path}", $this->checksumOf($info));
        }
    }

    private function checksumOf(mixed $info): ?string
    {
        return is_array($info) && is_string($info['sha256'] ?? null) ? $info['sha256'] : null;
    }

    private function assertChecksum(string $absolute, string $label, ?string $expected): void
    {
        if (! is_file($absolute)) {
            throw new RuntimeException("Berkas paket hilang: {$label}.");
        }
        if ($expected === null || ! hash_equals($expected, hash_file('sha256', $absolute))) {
            throw new RuntimeException("Berkas paket rusak atau berubah (sha256 tidak cocok): {$label}.");
        }
    }

    private function assertSafeFilePath(string $path): void
    {
        $inside = false;
        foreach (self::FILE_DIRECTORIES as $directory) {
            $inside = $inside || str_starts_with($path, $directory.'/');
        }
        if (! $inside || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException("Path berkas tidak diizinkan di manifest: {$path}.");
        }
    }

    /** @return list<string> warnings */
    private function assertMigrations(array $manifest): array
    {
        $have = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $missing = array_values(array_diff($manifest['migrations'], $have));
        if ($missing) {
            throw new RuntimeException('Skema database belum terbaru. Jalankan `php artisan migrate` dulu. Migration yang belum ada: '.implode(', ', $missing));
        }

        $newer = array_diff($have, $manifest['migrations']);
        if (! $newer) {
            return [];
        }

        return [count($newer).' migration di database ini lebih baru dari paket; kolom baru memakai nilai default. Perbarui paket dengan `php artisan sijatim:export-seed-data`.'];
    }

    /**
     * Parents before children, using the real foreign keys of the target database. Self references are ignored;
     * a genuine cycle is broken at the alphabetically first table (the deferred/disabled FK checks cover it).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderTables(array $tables): array
    {
        sort($tables);
        $pending = [];
        foreach ($tables as $table) {
            $pending[$table] = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $pending[$table][$parent] = true;
                }
            }
        }

        $done = [];
        while ($pending) {
            $ready = array_keys(array_filter($pending, fn (array $parents) => ! array_diff_key($parents, $done)));
            $ready = $ready ?: [array_key_first($pending)];
            foreach ($ready as $table) {
                $done[$table] = true;
                unset($pending[$table]);
            }
        }

        return array_keys($done);
    }

    /** Inserts one table, streaming the file row by row, and returns the number of rows written. */
    private function loadTable(string $dir, string $table, ?string $passwordHash, array &$warnings): int
    {
        $target = array_flip(Schema::getColumnListing($table));
        $keep = null;
        $extra = [];
        $size = 1;
        $chunk = [];
        $bytes = 0;
        $count = 0;
        $flush = function () use (&$chunk, &$bytes, $table): void {
            if ($chunk) {
                DB::table($table)->insert($chunk);
            }
            $chunk = [];
            $bytes = 0;
        };

        foreach ($this->readRows($dir.'/tables/'.$table.'.json.gz', $table) as [$row, $length]) {
            if ($keep === null) {
                // Columns come from the first row (an export writes the same columns for every row).
                $keep = array_intersect_key(array_flip(array_keys($row)), $target);
                $dropped = array_diff(array_keys($row), array_keys($keep));
                if ($dropped) {
                    $warnings[] = "Tabel {$table}: kolom tidak ada di database ini dan diabaikan: ".implode(', ', $dropped).'.';
                }
                if ($table === 'users') {
                    foreach (['password' => $passwordHash, 'remember_token' => null] as $column => $value) {
                        if (isset($target[$column])) {
                            $extra[$column] = $value;
                        }
                    }
                }
                $size = max(1, intdiv(self::MAX_BINDINGS, max(1, count($keep) + count($extra))));
            }

            $record = [];
            foreach ($keep as $column => $_) {
                $value = $row[$column] ?? null;
                // PDO sends floats as text rounded to 14 digits ("precision"); send the shortest exact form instead.
                $record[$column] = is_float($value) ? json_encode($value) : $value;
            }
            $chunk[] = $extra + $record;
            $bytes += $length;
            $count++;

            if (count($chunk) >= $size || $bytes >= self::MAX_CHUNK_BYTES) {
                $flush();
            }
        }
        $flush();

        return $count;
    }

    /**
     * Yields [row, size in bytes] for every row of a table file. Reads line by line when the file has one row per
     * line (what export() writes) and falls back to decoding the whole array for any other layout.
     *
     * @return \Generator<int, array{0: array<string, mixed>, 1: int}>
     */
    private function readRows(string $file, string $table): \Generator
    {
        $label = "tables/{$table}.json.gz";
        $handle = gzopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Berkas paket tidak bisa dibuka: {$label}.");
        }

        try {
            $first = gzgets($handle);
            if ($first === false) {
                return;
            }
            if (trim($first) === '[') {
                while (($line = gzgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === ']') {
                        return;
                    }
                    if ($line !== '') {
                        yield [$this->decodeRow(rtrim($line, ','), $label), strlen($line)];
                    }
                }

                return;
            }

            foreach ($this->decodeRow($first.stream_get_contents($handle), $label) as $row) {
                yield [$row, 0];
            }
        } finally {
            gzclose($handle);
        }
    }

    private function decodeRow(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("JSON rusak di {$label}: ".$e->getMessage(), 0, $e);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("Isi {$label} bukan data tabel yang valid.");
        }

        return $decoded;
    }

    private function assertNoForeignKeyViolations(): void
    {
        $violations = DB::select('PRAGMA foreign_key_check');
        if (! $violations) {
            return;
        }
        $sample = array_map(fn ($v) => "{$v->table} (rowid {$v->rowid}) -> {$v->parent}", array_slice($violations, 0, 10));

        throw new RuntimeException('Data paket melanggar foreign key ('.count($violations).' baris): '.implode('; ', $sample).'. Tidak ada yang disimpan.');
    }

    /** @param  list<string>  $paths */
    private function restoreFiles(string $dir, array $paths): int
    {
        $disk = Storage::disk(self::FILE_DISK);
        foreach ($paths as $path) {
            $stream = fopen($dir.'/files/'.$path, 'rb');
            $written = is_resource($stream) && $disk->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $written) {
                throw new RuntimeException("Gagal memulihkan berkas {$path} ke storage.");
            }
        }

        return count($paths);
    }

    /** @return list<array{period_code: string, status: string, lines: int, grand_total: ?string}> */
    private function periodSummary(): array
    {
        if (! Schema::hasTable('invoice_periods')) {
            return [];
        }
        $lines = Schema::hasTable('invoice_lines')
            ? DB::table('invoice_lines')->select('invoice_period_id', DB::raw('count(*) as total'))->groupBy('invoice_period_id')->pluck('total', 'invoice_period_id')->all()
            : [];
        // The grand total sits inside a calculation JSON of several MB per period; let the database pull it out
        // instead of decoding the whole document in PHP.
        $totals = [];
        if (Schema::hasColumn('invoice_periods', 'calculation')) {
            try {
                $totals = DB::table('invoice_periods')->select('id', 'calculation->totals->grand_total as grand_total')->get()->pluck('grand_total', 'id')->all();
            } catch (QueryException) {
                // A database without JSON functions: the totals are simply left out of the summary.
            }
        }

        $periods = [];
        foreach (DB::table('invoice_periods')->orderBy('id')->get(['id', 'period_code', 'status']) as $period) {
            $total = $totals[$period->id] ?? null;
            $periods[] = [
                'period_code' => (string) $period->period_code,
                'status' => (string) $period->status,
                'lines' => (int) ($lines[$period->id] ?? 0),
                'grand_total' => $total === null ? null : (string) $total,
            ];
        }

        return $periods;
    }

    private static function folder(?string $dir): string
    {
        return rtrim($dir ?? self::directory(), '/\\');
    }
}
