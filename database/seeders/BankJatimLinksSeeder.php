<?php

namespace Database\Seeders;

use App\Models\BankJatimLink;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BankJatimLinksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data link dari file CSV (path di SIJATIM_LINKS_CSV)
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('bank_jatim_links')->count() > 0) {
            $this->command->info('bank_jatim_links already has data. Skipping.');

            return;
        }

        // Path to the CSV comes from .env (SIJATIM_LINKS_CSV). It is only needed when the
        // committed data package (database/seed-data) is not used.
        $csvPath = env('SIJATIM_LINKS_CSV');

        if (! $csvPath || ! is_file($csvPath)) {
            $this->command->info('SIJATIM_LINKS_CSV not set or file not found. Skipping bank_jatim_links import.');

            return;
        }

        $this->command->info('Starting import from '.basename($csvPath).'...');

        // Load CSV
        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            $this->command->error("Cannot open CSV file: {$csvPath}");

            return;
        }

        // Read all content and handle BOM
        $content = fread($handle, filesize($csvPath));
        fclose($handle);

        // Remove BOM if present
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $lines = explode("\n", $content);

        // Skip first 3 rows (header rows)
        $dataLines = array_slice($lines, 3);

        // Create lookup maps
        $officeTypes = $this->createOfficeTypeLookup();
        $locationCategories = $this->createLocationCategoryLookup();
        $services = $this->createServiceLookup();

        $rows = [];
        $lineNumber = 0;
        $skipped = 0;
        $matched = 0;

        foreach ($dataLines as $line) {
            $lineNumber++;
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse CSV row
            $data = str_getcsv($line, ',', '"', '');
            if (count($data) < 5) {
                continue;
            }

            // Kolom: NO., JENIS KANTOR, ALAMAT, KATEGORI LOKASI, ID, IP Host Telkom, TANGGAL AKTIVASI
            $no = isset($data[0]) ? trim($data[0]) : '';
            $jenisKantor = isset($data[1]) ? trim($data[1]) : '';
            $alamat = isset($data[2]) ? trim($data[2]) : '';
            $kategoriLokasi = isset($data[3]) ? trim($data[3]) : '';
            $ssid = isset($data[4]) ? trim($data[4]) : '';
            $ipHost = isset($data[5]) ? trim($data[5]) : '';
            $tanggalAktivasi = isset($data[6]) ? trim($data[6]) : '';

            // Skip if SSID empty or is header
            if (empty($ssid) || strtoupper($ssid) === 'ID') {
                $skipped++;

                continue;
            }

            // Match office_type_id
            $officeTypeId = $this->matchOfficeType($jenisKantor, $officeTypes);
            if (! $officeTypeId) {
                $this->command->warn("Skipping row {$lineNumber}: Unknown JENIS KANTOR '{$jenisKantor}'");
                $skipped++;

                continue;
            }

            // Match location_category_id
            $locationCategoryId = $this->matchLocationCategory($kategoriLokasi, $locationCategories);
            if (! $locationCategoryId) {
                $this->command->warn("Unknown KATEGORI LOKASI '{$kategoriLokasi}', using default");
                $locationCategoryId = 1;
            }

            // Convert tanggal aktivasi (format: M/D/YYYY)
            $serviceStartDate = $this->parseDate($tanggalAktivasi);

            // Use NO. as sequence_in_office_type
            $sequence = is_numeric($no) ? (int) $no : $matched + 1;

            // Infer service from kategori/alamat
            $serviceId = $this->inferService($kategoriLokasi, $alamat, $services);

            $rows[] = [
                'ssid' => $ssid,
                'office_type_id' => $officeTypeId,
                'sequence_in_office_type' => $sequence,
                'location_category_id' => $locationCategoryId,
                'service_id' => $serviceId,
                'current_bandwidth_option_id' => null,
                'address' => $alamat,
                'ip_host_telkom' => $ipHost ?: null,
                'service_start_date' => $serviceStartDate,
                'service_end_date' => null,
                'link_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $matched++;
        }

        // Bulk insert
        if (! empty($rows)) {
            foreach (array_chunk($rows, 100) as $chunk) {
                BankJatimLink::insert($chunk);
            }
        }

        $this->command->info('Import completed!');
        $this->command->info("- Total rows in CSV: {$lineNumber}");
        $this->command->info("- Imported: {$matched}");
        $this->command->info("- Skipped: {$skipped}");
    }

    /**
     * Create lookup map for office types
     */
    private function createOfficeTypeLookup(): array
    {
        $lookup = [];
        $officeTypes = OfficeType::all();

        foreach ($officeTypes as $ot) {
            $name = strtoupper($ot->office_type_name);

            // Add exact match
            $lookup[$name] = $ot->id;

            // Add code match
            $lookup[strtoupper($ot->office_type_code)] = $ot->id;

            // Add normalized variants for fuzzy matching
            $lookup['N:'.$this->normalizeName($ot->office_type_name)] = $ot->id;

            // Add common variations (Dr., Hr. prefix)
            $noPrefix = $this->removePrefix($ot->office_type_name, ['Dr.', 'Hr.']);
            $lookup['V:'.$this->normalizeName($noPrefix)] = $ot->id;
        }

        return $lookup;
    }

    /**
     * Create lookup map for location categories
     */
    private function createLocationCategoryLookup(): array
    {
        $lookup = [];
        $categories = LocationCategory::all();

        foreach ($categories as $cat) {
            $name = strtoupper($cat->location_category_name);

            // Add exact match
            $lookup[$name] = $cat->id;

            // Add code match
            $lookup[strtoupper($cat->location_category_code)] = $cat->id;

            // Add normalized
            $lookup['N:'.$this->normalizeName($cat->location_category_name)] = $cat->id;
        }

        return $lookup;
    }

    /**
     * Create lookup map for services
     */
    private function createServiceLookup(): array
    {
        $lookup = [];
        $services = Service::all();

        foreach ($services as $svc) {
            $lookup[strtoupper($svc->service_code)] = $svc->id;
            $lookup['N:'.$this->normalizeName($svc->service_name)] = $svc->id;
        }

        return $lookup;
    }

    /**
     * Fuzzy match office type name
     */
    private function matchOfficeType(string $name, array $lookup): ?int
    {
        if (empty($name)) {
            return null;
        }

        $upperName = strtoupper(trim($name));

        // Direct lookup
        if (isset($lookup[$upperName])) {
            return $lookup[$upperName];
        }

        // Normalized lookup
        $normalized = $this->normalizeName($name);
        if (isset($lookup['N:'.$normalized])) {
            return $lookup['N:'.$normalized];
        }

        // Try variations
        $variations = $this->generateVariations($name);
        foreach ($variations as $var) {
            $varUpper = strtoupper($var);
            if (isset($lookup[$varUpper])) {
                return $lookup[$varUpper];
            }
            if (isset($lookup['N:'.$this->normalizeName($var)])) {
                return $lookup['N:'.$this->normalizeName($var)];
            }
        }

        // Fuzzy search in database
        return $this->fuzzySearchOfficeType($name);
    }

    /**
     * Fuzzy match location category name
     */
    private function matchLocationCategory(string $name, array $lookup): ?int
    {
        if (empty($name)) {
            return null;
        }

        $upperName = strtoupper(trim($name));

        // Direct lookup
        if (isset($lookup[$upperName])) {
            return $lookup[$upperName];
        }

        // Normalized lookup
        $normalized = $this->normalizeName($name);
        if (isset($lookup['N:'.$normalized])) {
            return $lookup['N:'.$normalized];
        }

        // Try variations
        $variations = $this->generateVariations($name);
        foreach ($variations as $var) {
            $varUpper = strtoupper($var);
            if (isset($lookup[$varUpper])) {
                return $lookup[$varUpper];
            }
        }

        // Fuzzy search in database
        return $this->fuzzySearchLocationCategory($name);
    }

    /**
     * Generate name variations for fuzzy matching
     */
    private function generateVariations(string $name): array
    {
        $variations = [];

        // Remove common prefixes
        $noPrefix = $this->removePrefix($name, ['Dr.', 'Hr.']);
        $variations[] = $noPrefix;
        $variations[] = $noPrefix.' SUTOMO';

        // Add without dot
        $variations[] = str_replace('.', '', $name);

        // Add without spaces
        $variations[] = preg_replace('/\s+/', '', $name);

        // KRAKSAN -> KRAKSAAN common typo
        if (stripos($name, 'kraksan') !== false) {
            $variations[] = str_ireplace('kraksan', 'kraksaan', $name);
        }

        // KANTOR KAS variations
        if (stripos($name, 'kantor kas') !== false) {
            $variations[] = 'KANTORKAS';
            $variations[] = 'KANTOR KAS';
        }

        return array_unique(array_filter($variations));
    }

    /**
     * Normalize name for comparison
     */
    private function normalizeName(string $name): string
    {
        $name = strtoupper($name);
        $name = str_replace('.', '', $name);
        $name = preg_replace('/\s+/', '', $name);
        $name = preg_replace('/[^A-Z0-9]/', '', $name);

        return $name;
    }

    /**
     * Remove prefix from name
     */
    private function removePrefix(string $name, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return trim(substr($name, strlen($prefix)));
            }
        }

        return $name;
    }

    /**
     * Fuzzy search office type in database
     */
    private function fuzzySearchOfficeType(string $name): ?int
    {
        $normalized = $this->normalizeName($name);
        $candidates = OfficeType::all();

        foreach ($candidates as $ot) {
            if ($this->normalizeName($ot->office_type_name) === $normalized) {
                return $ot->id;
            }

            $otNormalized = $this->normalizeName($ot->office_type_name);
            if ($this->similarStrings($otNormalized, $normalized) > 0.8) {
                return $ot->id;
            }
        }

        return null;
    }

    /**
     * Fuzzy search location category in database
     */
    private function fuzzySearchLocationCategory(string $name): ?int
    {
        $normalized = $this->normalizeName($name);
        $candidates = LocationCategory::all();

        foreach ($candidates as $cat) {
            if ($this->normalizeName($cat->location_category_name) === $normalized) {
                return $cat->id;
            }

            $catNormalized = $this->normalizeName($cat->location_category_name);
            if ($this->similarStrings($catNormalized, $normalized) > 0.8) {
                return $cat->id;
            }
        }

        return null;
    }

    /**
     * Simple string similarity (Levenshtein-based)
     */
    private function similarStrings(string $s1, string $s2): float
    {
        $len1 = strlen($s1);
        $len2 = strlen($s2);

        if ($len1 === 0 && $len2 === 0) {
            return 1.0;
        }

        $maxLen = max($len1, $len2);
        $distance = levenshtein($s1, $s2);

        return 1 - ($distance / $maxLen);
    }

    /**
     * Parse date from M/D/YYYY or MM/DD/YYYY format
     */
    private function parseDate(string $dateStr): ?string
    {
        if (empty($dateStr) || $dateStr === '-') {
            return null;
        }

        // Try M/D/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            $month = (int) $matches[1];
            $day = (int) $matches[2];
            $year = (int) $matches[3];

            // Validate
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && $year >= 1900 && $year <= 2100) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * Infer service type from category and address
     */
    private function inferService(string $category, string $address, array $services): int
    {
        // Default to TELKOM VPN IP (id=1)
        $defaultServiceId = $services['N:TELKOMVPNIP'] ?? $services['TELKOM_VPN_IP'] ?? 1;

        $addressUpper = strtoupper($address);

        // Try to infer from address content
        if (stripos($addressUpper, 'ASTINET') !== false) {
            return $services['N:TELKOMASTINET'] ?? $services['TELKOM_ASTINET'] ?? $defaultServiceId;
        }

        if (stripos($addressUpper, 'METRO') !== false) {
            return $services['N:TELKOMMETRO'] ?? $services['TELKOM_METRO'] ?? $defaultServiceId;
        }

        if (stripos($addressUpper, 'MANAGED') !== false) {
            return $services['N:TELKOMMANAGEDNETWORK'] ?? $services['TELKOM_MANAGED_NETWORK'] ?? $defaultServiceId;
        }

        if (stripos($addressUpper, 'PLASATRON') !== false) {
            return $services['N:TELKOMPLASATRON'] ?? $services['TELKOM_PLASATRON'] ?? $defaultServiceId;
        }

        return $defaultServiceId;
    }
}
