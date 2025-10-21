<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplyJob;
use Illuminate\Http\Request;

class SupplyJobController extends Controller
{
    /**
     * Display a listing of supply jobs.
     */
    public function index()
    {
        $supplyJobs = SupplyJob::with([
            'rentalJob.user.profile',
            'rentalJob.user.company',
            'provider',
            'products.product.brand',
            'products.product.category'
        ])
        ->withCount(['products', 'comments'])
        ->orderBy('created_at', 'desc')
        ->get();

        return view('admin.supply-jobs.index', compact('supplyJobs'));
    }

    /**
     * Display the specified supply job (read-only).
     */
    public function show(SupplyJob $supplyJob)
    {
        $supplyJob->load([
            'rentalJob.user.profile',
            'rentalJob.user.company',
            'rentalJob.products.product.brand',
            'provider.region',
            'provider.country',
            'provider.city',
            'provider.currency',
            'products.product.brand',
            'products.product.category',
            'products.product.subCategory',
            'comments.sender.profile',
            'comments.recipient.profile'
        ]);

        return view('admin.supply-jobs.show', compact('supplyJob'));
    }
}

