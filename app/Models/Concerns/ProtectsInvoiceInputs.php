<?php

namespace App\Models\Concerns;

use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;

trait ProtectsInvoiceInputs
{
    public static function bootProtectsInvoiceInputs(): void
    {
        $protect = function ($model) {
            $id = $model->invoice_period_id ?? ($model->invoice_line_id ? InvoiceLine::whereKey($model->invoice_line_id)->value('invoice_period_id') : null);
            if ($id && InvoicePeriod::whereKey($id)->first()?->isLocked()) {
                throw new \LogicException('Issued invoice inputs are locked. Create a revision before editing.');
            }
        };
        static::saving($protect);
        static::deleting($protect);
    }
}
