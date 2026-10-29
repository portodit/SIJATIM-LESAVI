<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentBillingTest extends TestCase
{
    public function test_concurrent_finalization_creates_one_invoice_revision_and_job(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $database = tempnam($directory, 'billing-concurrent-');
        try {
            $script = base_path('tests/Support/concurrent-invoice.php');
            $setup = new Process([PHP_BINARY, $script, 'setup', $database], base_path());
            $setup->setTimeout(60);
            $setup->mustRun();
            $fixture = json_decode($setup->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $args = [PHP_BINARY, $script, 'finalize', $database, (string) $fixture['period'], (string) $fixture['version']];
            $first = new Process($args, base_path());
            $second = new Process($args, base_path());
            $first->setTimeout(60);
            $second->setTimeout(60);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $this->assertJson($first->getOutput(), $first->getOutput());
            $this->assertJson($second->getOutput(), $second->getOutput());
            $this->assertSame(json_decode($first->getOutput(), true), json_decode($second->getOutput(), true));
            $pdo = new \PDO('sqlite:'.$database);
            foreach (['invoices', 'invoice_revisions', 'jobs'] as $table) {
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            }$pdo = null;
        } finally {
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
