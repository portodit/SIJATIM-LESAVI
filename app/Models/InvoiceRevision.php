<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvoiceRevision extends Model
{
    protected $guarded = [];
    protected $casts = ['snapshot' => 'array', 'approved_at' => 'datetime', 'generation_started_at' => 'datetime'];
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function invoicePeriod() { return $this->belongsTo(InvoicePeriod::class); }
    public function outputs() { return $this->hasMany(InvoiceOutput::class); }
    protected static function booted(): void
    {
        static::updating(function ($revision) {
            if ($revision->isDirty(['snapshot', 'snapshot_hash', 'input_version', 'version', 'invoice_id', 'invoice_period_id', 'approved_by', 'approved_at', 'reason'])) {
                throw new \LogicException('Snapshot invoice yang disetujui tidak dapat diubah.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Revisi invoice tidak dapat dihapus.'));
    }
}
