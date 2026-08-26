<?php

namespace App\Http\Controllers;

use App\Models\InvoicePeriod;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // Get statistics
        $totalPeriods = InvoicePeriod::count();
        $totalInvoices = Invoice::count();

        // Periods this month
        $periodsThisMonth = InvoicePeriod::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Total value this month
        $totalValueThisMonth = Invoice::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        // Recent periods
        $recentPeriods = InvoicePeriod::with('creator')
            ->latest()
            ->take(5)
            ->get();

        // Recent invoices
        $recentInvoices = Invoice::with('invoicePeriod')
            ->latest()
            ->take(5)
            ->get();

        // Monthly stats for chart (last 6 months)
        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $count = InvoicePeriod::whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
            $value = Invoice::whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('total_amount');

            $monthlyStats[] = [
                'month' => $month->format('M Y'),
                'count' => $count,
                'value' => $value,
            ];
        }

        return view('dashboard', [
            'title' => 'Beranda',
            'subtitle' => 'Dashboard Sistem Informasi Invoice Bank Jatim',
            'totalPeriods' => $totalPeriods,
            'totalInvoices' => $totalInvoices,
            'periodsThisMonth' => $periodsThisMonth,
            'totalValueThisMonth' => $totalValueThisMonth,
            'recentPeriods' => $recentPeriods,
            'recentInvoices' => $recentInvoices,
            'monthlyStats' => $monthlyStats,
        ]);
    }
}
