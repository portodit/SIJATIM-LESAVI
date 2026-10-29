<?php

namespace App\Services\Billing;

use App\Models\AddendumContract;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\InvoiceTrafficUsage;
use App\Services\Billing\InvoiceCalculator;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\OutageEvent;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SourceImportService
{
    private array $affectedDrafts = [];

    public function stage(InvoicePeriod $period, UploadedFile $file, string $kind): ImportBatch
    {
        $period = $period->fresh();
        abort_if($period->fresh()->isLocked(), 423, 'Periode terkunci.');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = ImportBatch::where('invoice_period_id', $period->id)->where('import_type', $kind)->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }
        $metadata = ['adapter' => 'telkom-v1'];
        if ($kind === 'history') {
            $analysis = (new HistoryWorkbookReader)->analyse($file->getRealPath());
            if (! $analysis['sheet']) {
                throw new \InvalidArgumentException('Tidak ditemukan sheet berisi daftar SSID (kolom ID/SID). Pastikan file adalah workbook invoice Telkom.');
            }
            $records = $this->historyRecords($file->getRealPath(), $analysis);
            $metadata = ['adapter' => 'telkom-history', 'analysis' => $analysis];
        } else {
            $records = (new WorkbookSourceReader)->read($file->getRealPath(), $kind, $period);
        }
        $path = $file->storeAs('billing/sources/'.$period->id, $hash.'.'.$file->getClientOriginalExtension(), 'local');

        return DB::transaction(function () use ($period, $file, $kind, $hash, $records, $path, $metadata) {
            $batch = ImportBatch::create(['invoice_period_id' => $period->id, 'import_type' => $kind, 'file_name' => basename($path), 'original_filename' => $file->getClientOriginalName(), 'file_path' => $path, 'file_hash' => $hash, 'usage_start' => $period->start_date, 'usage_end' => $period->end_date, 'input_version' => $period->input_version, 'total_rows' => count($records), 'status' => 'pending', 'uploaded_by' => auth()->id(), 'metadata' => $metadata]);
            $this->storeRows($batch, $period, $records);

            return $batch;
        });
    }

    /**
     * Re-reads a past-month workbook with the sheet and columns the operator picked. A different
     * sheet starts again from that sheet's own suggestions. Earlier row fixes are discarded.
     *
     * @param  array<string, ?string>  $mapping  field => column letter
     */
    public function remapHistory(ImportBatch $batch, string $sheet, array $mapping): void
    {
        $period = $batch->invoicePeriod;
        abort_if($batch->import_type !== 'history' || $batch->committed_at || $batch->status === 'discarded' || $period->isLocked(), 423, 'File ini sudah diterapkan, ditarik, atau periodenya terkunci.');
        $path = Storage::disk('local')->path($batch->file_path);
        $analysis = $batch->metadata['analysis'] ?? [];
        if ($sheet !== ($analysis['sheet'] ?? null)) {
            $analysis = (new HistoryWorkbookReader)->analyse($path, $sheet);
        } else {
            $columns = array_keys($analysis['columns'] ?? []);
            foreach (array_keys(HistoryWorkbookReader::FIELDS) as $field) {
                $letter = $mapping[$field] ?? null;
                $analysis['mapping'][$field] = in_array($letter, $columns, true) ? $letter : null;
            }
        }
        $records = $this->historyRecords($path, $analysis);
        DB::transaction(function () use ($batch, $period, $analysis, $records) {
            $batch->rows()->delete();
            $batch->update(['metadata' => array_merge($batch->metadata ?? [], ['analysis' => $analysis]), 'total_rows' => count($records), 'input_version' => $period->input_version]);
            $this->storeRows($batch, $period, $records);
        });
    }

    /**
     * What applying a past-month workbook changes in the period's service list, for the preview page.
     *
     * @return array{missing_fields: list<string>, matched: int, added: list<string>, new_to_master: list<string>, removed: list<array{sid: string, branch: ?string, inputs: int}>, orders: array<string, int>, price_total: string, downtime_rows: int}
     */
    public function historySummary(ImportBatch $batch): array
    {
        $period = $batch->invoicePeriod;
        $mapping = $batch->metadata['analysis']['mapping'] ?? [];
        $rows = $batch->rows()->where('kind', 'history')->get();
        $used = $rows->where('status', '!=', 'ignored');
        $fileSids = $rows->pluck('sid')->filter()->map(fn ($s) => (string) $s)->all();
        $lines = $period->lines()->with('bankJatimLink')->get()->keyBy(fn ($line) => $line->billingSid());
        $known = BankJatimLink::withTrashed()->whereIn('ssid', $fileSids)->pluck('ssid')->map(fn ($s) => (string) $s)->all();
        $gone = $lines->keys()->diff($fileSids)->values();
        $inputs = [];
        foreach ([$period->serviceEvents(), $period->outages(), $period->adjustments()] as $relation) {
            foreach ($relation->whereIn('sid', $gone->all())->selectRaw('sid, count(*) as c')->groupBy('sid')->pluck('c', 'sid') as $sid => $count) {
                $inputs[$sid] = ($inputs[$sid] ?? 0) + $count;
            }
        }
        $removed = $gone->map(fn ($sid) => [
            'sid' => $sid, 'branch' => $lines[$sid]->service_snapshot['branch'] ?? $lines[$sid]->bankJatimLink?->officeType?->office_type_name,
            'inputs' => $inputs[$sid] ?? 0,
        ])->all();

        return [
            'missing_fields' => array_values(array_filter(array_keys(HistoryWorkbookReader::FIELDS), fn ($f) => HistoryWorkbookReader::FIELDS[$f][1] && empty($mapping[$f]))),
            'matched' => $used->filter(fn ($r) => $lines->has((string) $r->sid))->count(),
            'added' => $used->reject(fn ($r) => $lines->has((string) $r->sid))->pluck('sid')->map(fn ($s) => (string) $s)->values()->all(),
            'new_to_master' => array_values(array_diff($used->pluck('sid')->map(fn ($s) => (string) $s)->all(), $known)),
            'removed' => $removed,
            'orders' => $used->countBy(fn ($r) => $r->normalized['event_type'] ?? 'existing')->all(),
            'price_total' => BillingMath::sum($used->map(fn ($r) => is_numeric($r->normalized['reference_price'] ?? null) ? $r->normalized['reference_price'] : 0)->all()),
            'downtime_rows' => $used->filter(fn ($r) => ($r->normalized['downtime_seconds'] ?? null) !== null)->count(),
        ];
    }

    private function historyRecords(string $path, array $analysis): array
    {
        if (empty($analysis['mapping']['sid']) || ! $analysis['first_row']) {
            return [];
        }

        return (new HistoryWorkbookReader)->records($path, $analysis['sheet'], $analysis['mapping'], $analysis['first_row']);
    }

    private function storeRows(ImportBatch $batch, InvoicePeriod $period, array $records): void
    {
        $seen = [];
        $planned = array_map(fn ($r) => (string) ($r['normalized']['sid'] ?? ''), array_filter($records, fn ($r) => $r['kind'] === 'inventory'));
        foreach ($records as $record) {
            [$data, $errors] = $this->normalize($record['kind'], $record['normalized'], $period, $planned);
            $key = $record['kind'].'|'.($data['sid'] ?? '').'|'.($data['effective_date'] ?? '').'|'.($data['opened_at'] ?? '').'|'.($data['closed_at'] ?? '').'|'.($data['router_mode'] ?? '').'|'.($data['category'] ?? '').'|'.($data['service'] ?? '').'|'.($data['bandwidth'] ?? '').'|'.($data['contract_number'] ?? '');
            if ($record['kind'] === 'history') {
                $key = 'history|'.($data['sid'] ?? '');
            }
            if (! in_array($record['kind'], ['notes', 'reference', 'incidents']) && isset($seen[$key])) {
                $errors[] = 'Baris duplikat; periksa atau abaikan dengan alasan.';
            }
            $seen[$key] = true;
            ImportRow::create(array_merge($record, ['import_batch_id' => $batch->id, 'sid' => $data['sid'] ?? null, 'normalized' => $data, 'errors' => $errors, 'status' => $errors ? 'error' : 'valid']));
        }
        $batch->update(['failed_rows' => $batch->rows()->where('status', 'error')->count()]);
    }

    public function resolve(ImportRow $row, array $input, bool $ignore, string $reason): void
    {
        $batch = $row->batch;
        abort_if($batch->committed_at || $batch->invoicePeriod->isLocked(), 423, 'Import sudah diterapkan atau periode terkunci.');
        $history = array_merge($row->resolution_history ?? [], [['before' => $row->normalized, 'values' => $input, 'ignored' => $ignore, 'reason' => $reason, 'operator' => auth()->id(), 'at' => now()->toIso8601String()]]);
        $row->resolution_history = $history;
        if ($ignore) {
            $row->update(['status' => 'ignored', 'resolution_reason' => $reason, 'resolved_by' => auth()->id()]);

            return;
        }
        $planned = $batch->rows()->where('kind', 'inventory')->pluck('sid')->toArray();
        [$data, $errors] = $this->normalize($row->kind, array_merge($row->normalized, $input), $batch->invoicePeriod, $planned);
        $row->update(['normalized' => $data, 'sid' => $data['sid'] ?? null, 'errors' => $errors, 'status' => $errors ? 'error' : 'valid', 'resolution_reason' => $reason, 'resolved_by' => auth()->id()]);
    }

    public function commit(ImportBatch $batch): array
    {
        $this->affectedDrafts = [];

        return DB::transaction(function () use ($batch) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $period = InvoicePeriod::whereKey($batch->invoice_period_id)->lockForUpdate()->firstOrFail();
            abort_if($period->isLocked(), 423, 'Periode terkunci.');
            if ($batch->committed_at) {
                return ['batch_id' => $batch->id, 'already_committed' => true];
            }
            abort_if($batch->input_version !== $period->input_version, 409, 'Input periode berubah setelah preview. Validasi ulang import.');
            if ($batch->rows()->whereIn('status', ['error', 'pending'])->exists()) {
                throw ValidationException::withMessages(['import' => 'Selesaikan semua kesalahan import atau abaikan baris dengan alasan.']);
            }
            $history = $batch->import_type === 'history';
            if ($history && ($missing = $this->historySummary($batch)['missing_fields'])) {
                throw ValidationException::withMessages(['import' => 'Pilih kolom untuk: '.implode(', ', array_map(fn ($f) => HistoryWorkbookReader::FIELDS[$f][0], $missing)).'.']);
            }
            $rows = $batch->rows()->where('status', 'valid')->get()->sortBy(fn ($r) => array_search($r->kind, ['inventory', 'tariffs', 'events', 'outages', 'incidents', 'traffic', 'adjustments', 'reference', 'notes']));
            $planned = $rows->where('kind', 'inventory')->pluck('sid')->toArray();
            $keys = [];
            foreach ($rows as $row) {
                [$d, $errors] = $this->normalize($row->kind, $row->normalized, $period, $planned);
                if ($errors) {
                    throw ValidationException::withMessages(['import' => 'Baris '.$row->row_number.': '.implode(' ', $errors)]);
                }
                $key = $row->kind.'|'.($d['sid'] ?? '').'|'.($d['event_type'] ?? '').'|'.($d['effective_date'] ?? '').'|'.($d['opened_at'] ?? '').'|'.($d['closed_at'] ?? '');
                if (in_array($row->kind, ['inventory', 'traffic', 'events', 'history']) && isset($keys[$key])) {
                    throw ValidationException::withMessages(['import' => 'Masih terdapat baris duplikat.']);
                }
                $keys[$key] = true;
                $source = ['batch_id' => $batch->id, 'file_hash' => $batch->file_hash, 'sheet' => $row->sheet, 'row' => $row->row_number, 'original' => $row->original, 'resolution' => $row->resolution_reason, 'resolution_history' => $row->resolution_history];
                $this->apply($period, $batch, $row->kind, $d, $source);
            }
            if ($rows->contains('kind', 'inventory')) {
                InvoiceLine::regroup($period);
            }
            $removed = $history ? $this->syncHistoryPeriod($period, $batch) : [];
            $batch->update(['status' => 'completed', 'success_rows' => $rows->count(), 'failed_rows' => 0, 'committed_at' => now()]);
            if ($rows->contains('kind', 'tariffs') || $this->affectedDrafts) {
                $others = InvoicePeriod::whereKeyNot($period->id)->where('is_locked', false)->where('status', '!=', 'finalized');
                if (! $rows->contains('kind', 'tariffs')) {
                    $others->whereIn('id', array_keys($this->affectedDrafts));
                }
                foreach ($others->lockForUpdate()->get() as $other) {
                    $other->markInputsChanged('master_source_changed', ['batch_id' => $batch->id]);
                }
            }
            $period->markInputsChanged('import_committed', array_filter(['batch_id' => $batch->id, 'hash' => $batch->file_hash, 'removed_sids' => $removed]));

            return ['batch_id' => $batch->id, 'rows' => $rows->count(), 'input_version' => $period->input_version];
        });
    }

    public function revalidate(ImportBatch $batch): void
    {
        abort_if($batch->committed_at || $batch->invoicePeriod->isLocked(), 423);
        $planned = $batch->rows()->where('kind', 'inventory')->pluck('sid')->toArray();
        foreach ($batch->rows()->where('status', '!=', 'ignored')->get() as $r) {
            [$data, $errors] = $this->normalize($r->kind, $r->normalized, $batch->invoicePeriod, $planned);
            $r->update(['normalized' => $data, 'errors' => $errors, 'status' => $errors ? 'error' : 'valid']);
        }
        $batch->update(['input_version' => $batch->invoicePeriod->input_version]);
    }

    public function normalize(string $kind, array $d, InvoicePeriod $p, array $planned = []): array
    {
        $errors = [];
        $capture = function (string $key, callable $convert) use (&$d, &$errors) {
            try {
                $d[$key] = $convert($d[$key] ?? null);
            } catch (\Throwable $e) {
                $errors[] = $key.': '.$e->getMessage();
            }
        };
        if (in_array($kind, ['notes', 'reference'])) {
            return [$d, []];
        }
        if ($kind !== 'tariffs' || ! empty($d['sid'])) {
            $capture('sid', [SourceValue::class, 'sid']);
        }
        $sid = $d['sid'] ?? null;
        $link = $sid ? BankJatimLink::withTrashed()->where('ssid', $sid)->first() : null;
        $history = $kind === 'history';
        if (! $link && ! in_array($kind, ['inventory', 'tariffs', 'history']) && ! in_array($sid, $planned, true)) {
            $errors[] = 'SID belum tersedia di master/daftar layanan.';
        }
        if ($link && ! in_array($kind, ['inventory', 'tariffs', 'history']) && ! $p->lines()->where('bank_jatim_link_id', $link->id)->exists() && ! in_array($sid, $planned, true)) {
            $errors[] = 'SID belum termasuk dalam periode ini.';
        }
        foreach (['activation_date', 'billing_start_date', 'billing_end_date', 'router_billing_from', 'effective_date', 'effective_start_date', 'effective_end_date', 'coverage_start', 'coverage_end', 'usage_start', 'usage_end'] as $key) {
            // A past-month workbook parses its two date columns below, only where they matter.
            if (array_key_exists($key, $d) && ! ($history && in_array($key, ['activation_date', 'effective_date'], true))) {
                $capture($key, fn ($v) => SourceValue::date($v));
            }
        }
        foreach (['opened_at', 'closed_at'] as $key) {
            if (array_key_exists($key, $d)) {
                $capture($key, fn ($v) => SourceValue::date($v, true));
            }
        }
        foreach (['monthly_price', 'installation_price', 'relocation_price', 'inbound_kbps', 'outbound_kbps', 'amount', 'fee', 'duration_seconds', 'downtime_seconds', 'reference_price', 'installation_fee', 'relocation_fee', 'reference_downtime_seconds'] as $key) {
            if (array_key_exists($key, $d)) {
                $capture($key, fn ($v) => SourceValue::number($v, ($d['_number_format'] ?? null) === 'decimal' || in_array($key, ['inbound_kbps', 'outbound_kbps'])));
                if (is_numeric($d[$key] ?? null) && (float) $d[$key] < 0) {
                    $errors[] = $key.' tidak boleh negatif.';
                }
            }
        }
        if (in_array($kind, ['inventory', 'tariffs', 'history'])) {
            foreach (['branch' => [OfficeType::class, 'office_type_name', 'office_type_id'], 'category' => [LocationCategory::class, 'location_category_name', 'location_category_id'], 'service' => [Service::class, 'service_name', 'service_id']] as $key => [$class, $name, $id]) {
                if ($kind === 'tariffs' && $key === 'branch') {
                    continue;
                }
                if (! empty($d[$id]) && $class::whereKey($d[$id])->exists()) {
                    continue;
                }
                $value = $d[$key] ?? null;
                $alias = ['kk' => 'KANTOR KAS', 'pp' => 'PAYMENT POINT', 'kf' => 'KANTOR FUNGSIONAL'];
                if ($key === 'category' && isset($alias[SourceValue::header($value)])) {
                    $value = $alias[SourceValue::header($value)];
                    $d[$key] = $value;
                }
                if (empty($value) && $link) {
                    $d[$id] = $link->{$id};

                    continue;
                }
                $found = $class::all()->first(fn ($m) => SourceValue::header($m->{$name}) === SourceValue::header($value));
                if ($found) {
                    $d[$id] = $found->id;
                } elseif (! empty($d[$id]) && $class::whereKey($d[$id])->exists()) { /* explicit mapping */
                } else {
                    $errors[] = 'Pilih '.$key.' yang cocok dengan master: '.($value ?? 'kosong');
                }
            }
            $d['router_mode'] = $d['router_mode'] ?? ($link?->billable_router_status ?? 'none');
            if (! in_array($d['router_mode'], ['none', 'managed'], true)) {
                $errors[] = 'Router mode harus none atau managed.';
            }
        }
        if (array_key_exists('bandwidth', $d)) {
            $bw = trim((string) $d['bandwidth']);
            $match = BandwidthOption::all()->first(fn ($m) => SourceValue::header($m->bandwidth_name) === SourceValue::header($bw));
            if (! empty($d['bandwidth_option_id']) && BandwidthOption::whereKey($d['bandwidth_option_id'])->exists()) { /* reviewed mapping */
            } elseif ($match) {
                $d['bandwidth_option_id'] = $match->id;
            } elseif ($bw === '' && $link) {
                $d['bandwidth_option_id'] = $link->current_bandwidth_option_id;
            } elseif (in_array(strtolower($bw), ['0', '-', 'n/a', 'na', 'tidak berlaku'], true)) {
                $d['bandwidth_option_id'] = BandwidthOption::where('is_not_applicable', true)->value('id');
            } elseif (empty($d['bandwidth_option_id']) || ! BandwidthOption::whereKey($d['bandwidth_option_id'])->exists()) {
                $errors[] = 'Bandwidth belum cocok dengan master.';
            }
        }
        if ($kind === 'inventory' && ! $link && empty($d['activation_date'])) {
            $errors[] = 'Tanggal aktivasi fisik diperlukan untuk layanan baru.';
        }
        if ($history) {
            $errors = array_merge($errors, $this->normalizeHistory($d, $p, $link, $sid));
        }
        if (in_array($kind, ['inventory', 'tariffs']) && ! empty($d['contract_number'])) {
            $contract = AddendumContract::where('contract_number', $d['contract_number'])->first();
            if ($contract) {
                $d['addendum_contract_id'] = $contract->id;
            } elseif ($kind === 'inventory') {
                $errors[] = 'Kontrak inventory belum tersedia. Tambahkan kontrak di master billing.';
            }
        }
        if ($kind === 'events') {
            $d['event_type'] = SourceValue::order($d['event_type'] ?? '');
            if (! in_array($d['event_type'], ['pasang_baru', 'upgrade', 'downgrade', 'relokasi', 'pemutusan'])) {
                $errors[] = 'Type order tidak valid.';
            }
            // Relocation has no effective date; it flags the whole invoice month.
            if (empty($d['effective_date']) && $d['event_type'] === 'relokasi') {
                $d['effective_date'] = $p->start_date->toDateString();
            }
            if (empty($d['effective_date'])) {
                $errors[] = 'Tanggal efektif wajib diisi.';
            }
            if (in_array($d['event_type'], ['upgrade', 'downgrade']) && empty($d['bandwidth_option_id'])) {
                $errors[] = 'Bandwidth perubahan wajib diisi.';
            }
        }
        if ($kind === 'tariffs') {
            if (($d['monthly_price'] ?? null) === null) {
                $errors[] = 'Tarif bulanan wajib diisi.';
            }
            if (empty($d['effective_start_date'])) {
                $errors[] = 'Tanggal berlaku tarif wajib diisi.';
            }
        }
        if ($kind === 'traffic') {
            foreach (['inbound_kbps', 'outbound_kbps', 'coverage_start', 'coverage_end'] as $key) {
                if (($d[$key] ?? null) === null) {
                    $errors[] = $key.' wajib diisi.';
                }
            }
        }
        if (in_array($kind, ['outages', 'incidents'])) {
            $manualTotal = $kind === 'outages' && ! empty($d['_manual_total_template']);
            $d['kind'] ??= $kind === 'incidents' ? 'evidence' : ($manualTotal || (isset($d['duration_seconds']) && empty($d['opened_at']) && empty($d['closed_at'])) ? 'aggregate' : 'interval');
            $d['review_status'] = $kind === 'incidents' ? 'excluded' : ($d['review_status'] ?? ($manualTotal ? 'eligible' : 'pending'));
            if ($manualTotal) {
                $d['reason'] = 'Downtime dikonfirmasi melalui template dua kolom.';
                if (isset($d['duration_seconds']) && floor((float) $d['duration_seconds']) === (float) $d['duration_seconds']) {
                    $line = $link ? $p->lines()->where('bank_jatim_link_id', $link->id)->first() : null;
                    if ($line) {
                        $snapshot = $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line);
                        $activeStart = ! empty($snapshot['billing_start_date']) ? Carbon::parse($snapshot['billing_start_date'])->max($p->start_date) : $p->start_date->copy();
                        $activeEnd = ! empty($snapshot['billing_end_date']) ? Carbon::parse($snapshot['billing_end_date'])->min($p->end_date) : $p->end_date->copy();
                        foreach ($p->serviceEvents()->where('sid', $sid)->orderBy('effective_date')->get() as $event) {
                            if ($event->event_type === 'pasang_baru') $activeStart = $activeStart->max($event->effective_date);
                            if ($event->event_type === 'pemutusan') $activeEnd = $activeEnd->min($event->effective_date);
                        }
                        if ($activeStart->gt($activeEnd)) {
                            $errors[] = 'SSID tidak memiliki jendela aktif untuk downtime pada periode ini.';
                        } elseif ((float) $d['duration_seconds'] > ($activeStart->diffInDays($activeEnd) + 1) * 86400) {
                            $errors[] = 'Downtime melebihi durasi layanan aktif pada periode ini.';
                        } else {
                            $d['coverage_start'] = $activeStart->toDateString();
                            $d['coverage_end'] = $activeEnd->toDateString();
                        }
                    }
                }
            }
            if (! in_array($d['kind'], ['aggregate', 'interval', 'evidence'])) {
                $errors[] = 'Jenis downtime tidak valid.';
            }
            if (! in_array($d['review_status'], ['pending', 'eligible', 'excluded'])) {
                $errors[] = 'Status review tidak valid.';
            }
            if ($d['kind'] === 'aggregate' && ($d['duration_seconds'] ?? null) === null) {
                $errors[] = 'Durasi aggregate wajib diisi.';
            }
            if ($d['kind'] === 'aggregate' && is_numeric($d['duration_seconds'] ?? null) && floor((float) $d['duration_seconds']) != (float) $d['duration_seconds']) {
                $errors[] = 'Durasi detik harus bilangan bulat.';
            }
            if ($d['kind'] !== 'aggregate' && (empty($d['opened_at']) || empty($d['closed_at']))) {
                $errors[] = 'Waktu buka/tutup wajib diisi.';
            }
            if (! empty($d['opened_at']) && ! empty($d['closed_at'])) {
                try {
                    if (Carbon::parse($d['closed_at'])->lt(Carbon::parse($d['opened_at']))) {
                        $errors[] = 'Waktu tutup mendahului waktu buka.';
                    }
                } catch (\Throwable) {
                    $errors[] = 'Tanggal downtime belum valid.';
                }
            }
            if ($d['review_status'] !== 'pending' && $kind !== 'incidents' && empty($d['reason'])) {
                $errors[] = 'Keputusan review membutuhkan alasan.';
            }
            // A service may have one downtime source. The operator must say which one is right.
            if ($kind === 'outages' && $d['kind'] === 'aggregate' && $d['review_status'] !== 'excluded' && $link && ($d['conflict_choice'] ?? null) !== 'use_file') {
                $existing = DowntimeConflicts::active($p, $sid);
                if ($existing->isNotEmpty()) {
                    $names = ImportBatch::where('invoice_period_id', $p->id)->pluck('original_filename', 'id')->all();
                    $current = $existing->map(fn ($o) => DowntimeConflicts::describe($o, $names))->map(fn ($o) => $o['value'].' ('.$o['source'].')')->implode('; ');
                    $errors[] = DowntimeConflicts::ERROR_PREFIX.' SSID ini sudah punya downtime: '.$current.'. Nilai di file: '.number_format((float) ($d['duration_seconds'] ?? 0), 0, ',', '.').' detik. Pilih nilai yang benar.';
                }
            }
        }
        if ($kind === 'adjustments') {
            if (! in_array($d['kind'] ?? '', ['manual_credit', 'tariff_override'])) {
                $errors[] = 'Penyesuaian harus manual_credit atau tariff_override.';
            }
            foreach (['amount', 'reason', 'reference'] as $key) {
                if (($d[$key] ?? '') === '' || ($d[$key] ?? null) === null) {
                    $errors[] = $key.' wajib diisi.';
                }
            }
        }
        foreach (['effective_date', 'opened_at', 'closed_at'] as $key) {
            if (! empty($d[$key]) && in_array($kind, ['events', 'outages', 'incidents'])) {
                try {
                    $date = Carbon::parse($d[$key]);
                    // Intervals may cross a month boundary, but must overlap this usage window.
                    if ($kind === 'events' && ! $date->betweenIncluded($p->start_date->copy()->startOfDay(), $p->end_date->copy()->endOfDay())) {
                        $errors[] = 'Tanggal efektif tidak berada dalam periode pemakaian.';
                    }
                    if ($kind !== 'events' && ! empty($d['opened_at']) && ! empty($d['closed_at']) && (Carbon::parse($d['closed_at'])->lt($p->start_date) || Carbon::parse($d['opened_at'])->gt($p->end_date->copy()->endOfDay()))) {
                        $errors[] = 'Downtime tidak tumpang tindih dengan periode pemakaian.';
                    }
                } catch (\Throwable) {
                    $errors[] = 'Tanggal belum valid.';
                }
            }
        }
        if ($kind === 'traffic' && ! empty($d['coverage_start']) && ! empty($d['coverage_end'])) {
            try {
                $a = Carbon::parse($d['coverage_start']);
                $b = Carbon::parse($d['coverage_end']);
                if ($a->lt($p->start_date) || $b->gt($p->end_date->copy()->endOfDay()) || $b->lt($a)) {
                    $errors[] = 'Periode sumber trafik berbeda dengan periode pemakaian.';
                }
            } catch (\Throwable) {
                $errors[] = 'Tanggal cakupan trafik belum valid.';
            }
        }
        foreach (['usage_start' => $p->start_date, 'usage_end' => $p->end_date] as $key => $expected) {
            if (! empty($d[$key]) && substr((string) $d[$key], 0, 10) !== $expected->toDateString()) {
                $errors[] = 'Periode sumber '.$key.' berbeda dengan periode pemakaian.';
            }
        }

        return [$d, array_values(array_unique($errors))];
    }

    /**
     * One SSID row of a past month's Telkom workbook: its type order becomes a service event,
     * its downtime an aggregate covering the days the service was active that month.
     */
    private function normalizeHistory(array &$d, InvoicePeriod $p, ?BankJatimLink $link, ?string $sid): array
    {
        $errors = [];
        $type = trim((string) ($d['type_order'] ?? ''));
        $d['event_type'] = SourceValue::order($type === '' ? 'existing' : $type);
        if (! in_array($d['event_type'], ['existing', 'pasang_baru', 'pemutusan', 'relokasi', 'upgrade', 'downgrade'], true)) {
            $errors[] = 'Type order "'.$type.'" tidak dikenal. Isi salah satu: EKSISTING, PASANG BARU, PEMUTUSAN, RELOKASI, UPGRADE, DOWNGRADE.';
        }
        if (($d['period'] ?? null) !== null && $d['period'] !== '' && (string) (int) $d['period'] !== $p->start_date->format('Ym')) {
            $errors[] = 'Period di file ('.$d['period'].') berbeda dengan bulan pemakaian '.$p->start_date->format('Ym').'. Pastikan workbook sesuai periode ini.';
        }
        if (array_key_exists('activation_date', $d)) {
            try {
                $d['activation_date'] = SourceValue::date($d['activation_date']);
            } catch (\Throwable $e) {
                // Only an SSID new to the master keeps this date; for the others it is informational.
                if (! $link) {
                    $errors[] = 'Tanggal aktivasi: '.$e->getMessage();
                }
                $d['activation_date'] = $link ? null : $d['activation_date'];
            }
        }
        $dated = in_array($d['event_type'], ['pasang_baru', 'pemutusan', 'upgrade', 'downgrade'], true);
        $date = null;
        if ($dated) {
            try {
                $date = SourceValue::date($d['effective_date'] ?? null);
            } catch (\Throwable $e) {
                $errors[] = 'Tanggal mulai: '.$e->getMessage();
            }
            if ($date && ! Carbon::parse($date)->betweenIncluded($p->start_date->copy()->startOfDay(), $p->end_date->copy()->endOfDay())) {
                $errors[] = 'Tanggal mulai '.$date.' tidak berada dalam bulan pemakaian.';
                $date = null;
            } elseif (! $date && ! $errors) {
                $errors[] = 'Tanggal mulai wajib diisi untuk '.($type ?: $d['event_type']).'.';
            }
        }
        // Relocation has no effective date; it flags the whole invoice month.
        $d['effective_date'] = $dated ? ($date ?? $d['effective_date'] ?? null) : ($d['event_type'] === 'relokasi' ? $p->start_date->toDateString() : null);
        if (in_array($d['event_type'], ['upgrade', 'downgrade'], true)) {
            $raw = trim((string) ($d['bandwidth_'.$d['event_type']] ?? ''));
            $match = $raw === '' ? null : BandwidthOption::all()->first(fn ($m) => SourceValue::header($m->bandwidth_name) === SourceValue::header($raw));
            if ($match) {
                $d['bandwidth_change_option_id'] = $match->id;
            } elseif (empty($d['bandwidth_change_option_id']) || ! BandwidthOption::whereKey($d['bandwidth_change_option_id'])->exists()) {
                $errors[] = 'Bandwidth '.$d['event_type'].' belum cocok dengan master: '.($raw ?: 'kosong');
            }
        }
        if (! $link) {
            if (empty($d['activation_date'])) {
                $errors[] = 'Tanggal aktivasi diperlukan untuk SSID yang belum ada di Master.';
            }
            if (trim((string) ($d['address'] ?? '')) === '') {
                $errors[] = 'Alamat diperlukan untuk SSID yang belum ada di Master.';
            }
        }
        if (($d['downtime_seconds'] ?? null) !== null && is_numeric($d['downtime_seconds'])) {
            $start = $d['event_type'] === 'pasang_baru' && $date ? Carbon::parse($date) : $p->start_date->copy();
            $end = $d['event_type'] === 'pemutusan' && $date ? Carbon::parse($date) : $p->end_date->copy();
            $d['coverage_start'] = $start->toDateString();
            $d['coverage_end'] = $end->toDateString();
            if (floor((float) $d['downtime_seconds']) != (float) $d['downtime_seconds']) {
                $errors[] = 'Durasi downtime harus bilangan bulat (detik).';
            } elseif ((float) $d['downtime_seconds'] > ($start->diffInDays($end) + 1) * 86400) {
                $errors[] = 'Downtime melebihi durasi layanan aktif pada bulan ini.';
            } elseif ($link && ($d['conflict_choice'] ?? null) !== 'use_file') {
                // Downtime from an earlier workbook of this month is simply replaced; anything else needs the operator's choice.
                $earlier = $this->earlierHistoryBatches($p);
                $existing = DowntimeConflicts::active($p, $sid)->reject(fn ($o) => in_array($o->import_batch_id, $earlier, true));
                if ($existing->isNotEmpty()) {
                    $names = ImportBatch::where('invoice_period_id', $p->id)->pluck('original_filename', 'id')->all();
                    $current = $existing->map(fn ($o) => DowntimeConflicts::describe($o, $names))->map(fn ($o) => $o['value'].' ('.$o['source'].')')->implode('; ');
                    $errors[] = DowntimeConflicts::ERROR_PREFIX.' SSID ini sudah punya downtime: '.$current.'. Nilai di file: '.number_format((float) $d['downtime_seconds'], 0, ',', '.').' detik. Pilih nilai yang benar.';
                }
            }
        }

        return $errors;
    }

    /**
     * Applies one past-month row. The master is never changed: an SSID it does not know yet is
     * added as inactive (so later months do not pick it up), and the period line takes the
     * workbook's branch, address, category, service and bandwidth for this month only.
     */
    private function applyHistory(InvoicePeriod $p, ImportBatch $batch, array $d, array $source): void
    {
        $link = BankJatimLink::withTrashed()->where('ssid', $d['sid'])->first();
        if ($link?->trashed()) {
            throw ValidationException::withMessages(['import' => 'SID '.$d['sid'].' telah diarsipkan. Pulihkan melalui master layanan.']);
        }
        if (! $link) {
            $link = BankJatimLink::create([
                'ssid' => $d['sid'], 'office_type_id' => $d['office_type_id'], 'location_category_id' => $d['location_category_id'], 'service_id' => $d['service_id'],
                'sequence_in_office_type' => (BankJatimLink::where('office_type_id', $d['office_type_id'])->max('sequence_in_office_type') ?? 0) + 1,
                'current_bandwidth_option_id' => $d['bandwidth_option_id'] ?? null, 'address' => $d['address'], 'ip_host_telkom' => $d['ip'] ?? null,
                'service_start_date' => $d['activation_date'], 'link_status' => BankJatimLink::STATUS_INACTIVE, 'created_by' => auth()->id(),
                'notes' => 'Ditambahkan dari workbook '.$batch->original_filename.' untuk tagihan '.$p->period_code.'; tidak aktif di Master.',
            ]);
        }
        $line = InvoiceLine::firstOrCreate(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $link->id], ['line_number' => InvoiceLine::generateLineNumber($p->id), 'type_order_id' => TypeOrder::where('type_order_code', 'existing')->value('id'), 'existing_bandwidth_option_id' => $d['bandwidth_option_id'] ?? $link->current_bandwidth_option_id, 'address_snapshot' => $d['address'] ?? $link->address]);
        $line->load('bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service');
        $snapshot = array_merge(InvoiceCalculator::serviceSnapshot($line), array_filter([
            'branch' => OfficeType::whereKey($d['office_type_id'])->value('office_type_name'), 'address' => $d['address'] ?? null,
            'category' => LocationCategory::whereKey($d['location_category_id'])->value('location_category_name'), 'service' => Service::whereKey($d['service_id'])->value('service_name'),
            'location_category_id' => $d['location_category_id'], 'service_id' => $d['service_id'], 'bandwidth_option_id' => $d['bandwidth_option_id'] ?? null, 'ip' => $d['ip'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        // This month's window comes from the workbook's type order, not from today's master dates.
        $snapshot['billing_start_date'] = null;
        $snapshot['billing_end_date'] = null;
        $snapshot['source'] = $source;
        // Only a whole month is comparable with the monthly tariff; PSB/Cabut rows carry a prorated amount.
        $snapshot['reference_price'] = $d['event_type'] === 'existing' ? ($d['reference_price'] ?? null) : null;
        // A mapped but empty price cell is shown as a note, so the operator knows nothing was compared.
        $snapshot['reference_missing'] = $d['event_type'] === 'existing' && array_key_exists('reference_price', $d) && $d['reference_price'] === null;
        $line->update(['service_snapshot' => $snapshot, 'existing_bandwidth_option_id' => $snapshot['bandwidth_option_id'], 'address_snapshot' => $snapshot['address']]);

        // A corrected workbook of the same month replaces what an earlier one recorded for this SSID.
        $earlier = $this->earlierHistoryBatches($p, $batch->id);
        $p->serviceEvents()->where('sid', $d['sid'])->whereIn('import_batch_id', $earlier)->delete();
        if ($d['event_type'] !== 'existing') {
            $exists = $p->serviceEvents()->where('sid', $d['sid'])->where('event_type', $d['event_type'])->whereDate('effective_date', $d['effective_date'])->exists();
            if (! $exists) {
                $bandwidth = in_array($d['event_type'], ['upgrade', 'downgrade'], true) ? $d['bandwidth_change_option_id'] : ($d['event_type'] === 'pasang_baru' ? $snapshot['bandwidth_option_id'] : null);
                // The fee Telkom charged that month wins over the tariff (a migration PSB is billed 0).
                $fee = ['pasang_baru' => $d['installation_fee'] ?? null, 'relokasi' => $d['relocation_fee'] ?? null][$d['event_type']] ?? null;
                ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $d['sid'], 'event_type' => $d['event_type'], 'effective_date' => $d['effective_date'], 'attributes' => array_filter(['bandwidth_option_id' => $bandwidth, 'fee' => $fee, 'old_attributes' => $snapshot], fn ($v) => $v !== null), 'source' => $source, 'import_batch_id' => $batch->id]);
            }
        }
        if (($d['downtime_seconds'] ?? null) !== null) {
            $useFile = ($d['conflict_choice'] ?? null) === 'use_file';
            $replaced = DowntimeConflicts::active($p, $d['sid'])->filter(fn ($o) => in_array($o->import_batch_id, $earlier, true) || ($useFile && $o->import_batch_id !== $batch->id));
            DowntimeConflicts::supersede($replaced, 'Digantikan oleh file '.$batch->original_filename.' (baris '.($source['row'] ?? '?').')'.($useFile ? ' atas pilihan operator.' : ', workbook yang lebih baru untuk bulan ini.'), ['batch_id' => $batch->id]);
            OutageEvent::create(['invoice_period_id' => $p->id, 'sid' => $d['sid'], 'kind' => 'aggregate', 'duration_seconds' => (int) $d['downtime_seconds'], 'coverage_start' => $d['coverage_start'], 'coverage_end' => Carbon::parse($d['coverage_end'])->startOfDay()->addDay()->toDateTimeString(), 'review_status' => 'eligible', 'reason' => 'Downtime dari workbook Telkom '.$batch->original_filename.'.', 'source' => $source, 'import_batch_id' => $batch->id, 'reviewed_by' => auth()->id()]);
        }
    }

    /** @return list<int> ids of the period's applied past-month workbooks, other than $except */
    private function earlierHistoryBatches(InvoicePeriod $p, ?int $except = null): array
    {
        return ImportBatch::where('invoice_period_id', $p->id)->where('import_type', 'history')->whereNotNull('committed_at')->when($except, fn ($q) => $q->whereKeyNot($except))->pluck('id')->all();
    }

    /**
     * After a past-month workbook is applied, the period holds exactly the workbook's SSIDs, in its
     * order within each branch group (every invoice is grouped by branch). Lines not in the file are
     * removed together with their inputs for this period.
     */
    private function syncHistoryPeriod(InvoicePeriod $p, ImportBatch $batch): array
    {
        $rows = $batch->rows()->where('kind', 'history')->get()->sortBy(fn ($r) => $r->normalized['_order'] ?? $r->row_number);
        $fileSids = $rows->pluck('sid')->map(fn ($s) => (string) $s)->all();
        $lines = $p->lines()->with('bankJatimLink')->get()->keyBy(fn ($line) => $line->billingSid());
        $removed = [];
        foreach ($lines as $sid => $line) {
            if (in_array((string) $sid, $fileSids, true)) {
                continue;
            }
            $removed[$sid] = ['events' => $p->serviceEvents()->where('sid', $sid)->delete(), 'outages' => $p->outages()->where('sid', $sid)->delete(), 'adjustments' => $p->adjustments()->where('sid', $sid)->delete()];
            $line->delete();
            $lines->forget($sid);
        }
        $number = 0;
        foreach ($rows as $row) {
            if ($line = $lines->pull((string) $row->sid)) {
                DB::table('invoice_lines')->where('id', $line->id)->update(['line_number' => ++$number]);
            }
        }
        foreach ($lines->sortBy('line_number') as $line) {
            DB::table('invoice_lines')->where('id', $line->id)->update(['line_number' => ++$number]);
        }
        InvoiceLine::regroup($p);

        return $removed;
    }

    public function withdrawEvents(ImportBatch $batch): void
    {
        $events = ServiceEvent::where('import_batch_id', $batch->id)->orderBy('effective_date')->orderBy('id')->get();
        foreach ($events->groupBy('sid') as $sid => $removed) {
            $link = BankJatimLink::withTrashed()->where('ssid', $sid)->first();
            if (! $link) {
                continue;
            }
            $changes = [];
            foreach (['pasang_baru' => 'billing_start_date', 'pemutusan' => 'billing_end_date'] as $type => $field) {
                $withdrawn = $removed->where('event_type', $type)->last();
                if (! $withdrawn || $link->{$field}?->toDateString() !== $withdrawn->effective_date->toDateString()) {
                    continue;
                }
                $remaining = ServiceEvent::where('sid', $sid)->where('event_type', $type)->where(fn ($q) => $q->where('import_batch_id', '!=', $batch->id)->orWhereNull('import_batch_id'))->orderByDesc('effective_date')->orderByDesc('id')->first();
                $changes[$field] = $remaining?->effective_date ?? ($removed->first()->attributes['old_attributes'][$field] ?? null);
            }
            if ($changes) {
                $link->update($changes);
            }
        }
        ServiceEvent::where('import_batch_id', $batch->id)->delete();
    }

    private function preserveOtherDrafts(BankJatimLink $link, InvoicePeriod $period): void
    {
        $lines = InvoiceLine::where('bank_jatim_link_id', $link->id)->where('invoice_period_id', '!=', $period->id)->whereNull('service_snapshot')->whereHas('invoicePeriod', fn ($q) => $q->where('is_locked', false)->where('status', '!=', 'finalized'))->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service'])->get();
        foreach ($lines as $line) {
            $line->update(['service_snapshot' => InvoiceCalculator::serviceSnapshot($line)]);
            $this->affectedDrafts[$line->invoice_period_id] = true;
        }
    }

    private function apply(InvoicePeriod $p, ImportBatch $batch, string $kind, array $d, array $source): void
    {
        if ($kind === 'history') {
            $this->applyHistory($p, $batch, $d, $source);

            return;
        }
        $link = ! empty($d['sid']) ? BankJatimLink::withTrashed()->where('ssid', $d['sid'])->first() : null;
        if ($kind === 'inventory') {
            if ($link?->trashed()) {
                throw ValidationException::withMessages(['import' => 'SID telah diarsipkan. Pulihkan melalui master layanan.']);
            }
            $data = array_filter([
                'office_type_id' => $d['office_type_id'], 'location_category_id' => $d['location_category_id'], 'service_id' => $d['service_id'],
                'current_bandwidth_option_id' => $d['bandwidth_option_id'] ?? $link?->current_bandwidth_option_id, 'addendum_contract_id' => $d['addendum_contract_id'] ?? $link?->addendum_contract_id,
                'address' => $d['address'] ?? $link?->address, 'ip_host_telkom' => $d['ip'] ?? $link?->ip_host_telkom,
                'service_start_date' => $d['activation_date'] ?? $link?->service_start_date, 'billing_start_date' => $d['billing_start_date'] ?? $link?->billing_start_date,
                'billing_end_date' => $d['billing_end_date'] ?? $link?->billing_end_date, 'billable_router_status' => $d['router_mode'],
                'physical_router_status' => $d['physical_router_status'] ?? $link?->physical_router_status ?? 'unknown', 'router_billing_from' => $d['router_billing_from'] ?? $link?->router_billing_from,
            ], fn ($v) => $v !== null);
            if (! $link) {
                $data += ['ssid' => $d['sid'], 'sequence_in_office_type' => (BankJatimLink::where('office_type_id', $d['office_type_id'])->max('sequence_in_office_type') ?? 0) + 1, 'created_by' => auth()->id(), 'link_status' => 'active'];
                $link = BankJatimLink::create($data);
            } else {
                $this->preserveOtherDrafts($link, $p);
                $link->update($data);
            }
            $line = InvoiceLine::firstOrCreate(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $link->id], ['line_number' => InvoiceLine::generateLineNumber($p->id), 'type_order_id' => TypeOrder::where('type_order_code', 'existing')->value('id'), 'existing_bandwidth_option_id' => $link->current_bandwidth_option_id, 'address_snapshot' => $link->address]);
            $line->load('bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service');
            $line->existing_bandwidth_option_id = $link->current_bandwidth_option_id;
            $line->address_snapshot = $link->address;
            $snapshot = InvoiceCalculator::serviceSnapshot($line);
            $snapshot['source'] = $source;
            $snapshot['reference_price'] = $d['reference_price'] ?? null;
            $snapshot['reference_downtime_seconds'] = $d['reference_downtime_seconds'] ?? null;
            $line->update(['service_snapshot' => $snapshot, 'existing_bandwidth_option_id' => $link->current_bandwidth_option_id, 'address_snapshot' => $link->address]);
        } elseif ($kind === 'events') {
            $duplicate = $p->serviceEvents()->where('sid', $d['sid'])->where('event_type', $d['event_type'])->whereDate('effective_date', $d['effective_date'])->first();
            if ($duplicate) {
                $attributes = $duplicate->attributes;
                unset($attributes['_number_format'], $attributes['old_attributes']);
                $candidate = $d;
                unset($candidate['_number_format']);
                if ($attributes === $candidate) {
                    return;
                }
                throw ValidationException::withMessages(['import' => 'Perubahan SID '.$d['sid'].' pada tanggal ini bertentangan dengan sumber yang sudah diterapkan. Tarik sumber lama atau koreksi baris.']);
            }
            $line = $p->lines()->where('bank_jatim_link_id', $link->id)->firstOrFail();
            $d['old_attributes'] = $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line);
            if (! $line->service_snapshot) {
                $line->update(['service_snapshot' => $d['old_attributes']]);
            }
            ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $d['sid'], 'event_type' => $d['event_type'], 'effective_date' => $d['effective_date'], 'attributes' => $d, 'source' => $source, 'import_batch_id' => $batch->id]);
            $masterChanges = [];
            $latest = $p->serviceEvents()->where('sid', $d['sid'])->orderByDesc('effective_date')->orderByDesc('id')->get();
            $termination = $latest->firstWhere('event_type', 'pemutusan');
            if ($termination) {
                $masterChanges['billing_end_date'] = $termination->effective_date;
            }
            $installation = $latest->firstWhere('event_type', 'pasang_baru');
            if ($installation) {
                $masterChanges['billing_start_date'] = $installation->effective_date;
            }
            if ($masterChanges) {
                $this->preserveOtherDrafts($link, $p);
                $link->update($masterChanges);
            }
        } elseif (in_array($kind, ['outages', 'incidents'])) {
            if ($kind === 'outages' && ($d['conflict_choice'] ?? null) === 'use_file' && ($d['kind'] ?? null) === 'aggregate') {
                DowntimeConflicts::supersede(DowntimeConflicts::active($p, $d['sid'])->reject(fn ($o) => $o->import_batch_id === $batch->id), 'Digantikan oleh file '.$batch->original_filename.' (baris '.($source['row'] ?? '?').') atas pilihan operator.', ['batch_id' => $batch->id]);
            }
            OutageEvent::create(['invoice_period_id' => $p->id, 'sid' => $d['sid'], 'kind' => $d['kind'], 'opened_at' => $d['opened_at'] ?? null, 'closed_at' => $d['closed_at'] ?? null, 'duration_seconds' => $d['duration_seconds'] ?? null, 'coverage_start' => $d['coverage_start'] ?? $p->start_date->toDateString(), 'coverage_end' => Carbon::parse($d['coverage_end'] ?? $p->end_date)->startOfDay()->addDay()->toDateTimeString(), 'ticket' => $d['ticket'] ?? null, 'review_status' => $d['review_status'], 'reason' => $d['reason'] ?? ($kind === 'incidents' ? 'Bukti tiket; bukan sumber SLA yang dipilih.' : null), 'source' => $source, 'import_batch_id' => $batch->id, 'reviewed_by' => $d['review_status'] !== 'pending' ? auth()->id() : null]);
        } elseif ($kind === 'traffic') {
            $line = $p->lines()->where('bank_jatim_link_id', $link->id)->firstOrFail();
            $previous = $line->trafficUsage;
            if ($previous && $previous->import_batch_id !== $batch->id) {
                throw ValidationException::withMessages(['import' => 'Trafik SID '.$d['sid'].' sudah diimpor. Hapus sumber lama secara eksplisit sebelum mengganti.']);
            }
            InvoiceTrafficUsage::updateOrCreate(['invoice_line_id' => $line->id], ['inbound_kbps' => $d['inbound_kbps'], 'outbound_kbps' => $d['outbound_kbps'], 'coverage_start' => $d['coverage_start'], 'coverage_end' => $d['coverage_end'], 'import_batch_id' => $batch->id, 'source' => $source]);
        } elseif ($kind === 'adjustments') {
            InvoiceAdjustment::create(['invoice_period_id' => $p->id, 'sid' => $d['sid'], 'kind' => $d['kind'], 'amount' => $d['amount'], 'reason' => $d['reason'], 'reference' => $d['reference'], 'attributes' => $d, 'source' => $source, 'import_batch_id' => $batch->id, 'created_by' => auth()->id()]);
        } elseif ($kind === 'tariffs') {
            $contract = AddendumContract::firstOrCreate(['contract_number' => $d['contract_number'] ?? 'ADD II'], ['contract_title' => $d['contract_number'] ?? 'ADD II', 'effective_date' => $d['effective_start_date'], 'status' => 'active', 'uploaded_by' => auth()->id()]);
            $match = ['location_category_id' => $d['location_category_id'], 'service_id' => $d['service_id'], 'bandwidth_option_id' => $d['bandwidth_option_id'] ?? null, 'router_mode' => $d['router_mode'], 'sid' => $d['sid'] ?? null, 'addendum_contract_id' => $contract->id, 'effective_start_date' => $d['effective_start_date']];
            $old = PriceMaster::where($match)->first();
            if ($old) {
                throw ValidationException::withMessages(['import' => 'Tarif dengan kombinasi dan tanggal yang sama sudah ada. Buat versi/tanggal baru atau selesaikan lewat master harga.']);
            }
            PriceMaster::create($match + ['monthly_price' => $d['monthly_price'], 'installation_price' => $d['installation_price'] ?? null, 'relocation_price' => $d['relocation_price'] ?? null, 'effective_end_date' => $d['effective_end_date'] ?? null, 'addendum_reference' => $contract->contract_number, 'addendum_contract_id' => $contract->id, 'is_active' => true]);
        }
    }
}
