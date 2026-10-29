<?php

return [
    /*
    | Start a one-shot queue worker from the application whenever invoice documents are waiting,
    | so Excel/PDF generation does not depend on a separately started `queue:work`.
    | Set BILLING_AUTO_WORKER=false when a supervised worker is run instead (e.g. on a server).
    */
    'auto_worker' => (bool) env('BILLING_AUTO_WORKER', true),

    /*
    | PHP memory limit (MB) for document generation. A month of ~775 services peaks near 650 MB;
    | PHP's default of 128 MB makes the Excel writer run out of memory.
    */
    'worker_memory_mb' => (int) env('BILLING_WORKER_MEMORY_MB', 1024),
];
