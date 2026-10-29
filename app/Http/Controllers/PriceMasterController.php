<?php

namespace App\Http\Controllers;

use App\Models\PriceMaster;
use App\Services\Billing\TaxRate;
use Illuminate\Http\Request;

class PriceMasterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $priceMasters = PriceMaster::with(['locationCategory', 'service', 'bandwidthOption'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('price-masters.index', [
            'title' => 'Master Harga',
            'subtitle' => 'Daftar Harga Layanan',
            'priceMasters' => $priceMasters,
            'ppnRate' => TaxRate::current(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(PriceMaster $priceMaster)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PriceMaster $priceMaster)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PriceMaster $priceMaster)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PriceMaster $priceMaster)
    {
        //
    }
}
