<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use Illuminate\Http\Request;

class RentalJobController extends Controller
{
    /**
     * Display a listing of rental jobs.
     */
    public function index()
    {
        $rentalJobs = RentalJob::with([
            'user.profile',
            'user.company',
            'products.product.brand',
            'products.product.category',
            'supplyJobs.provider'
        ])
        ->withCount(['products', 'supplyJobs', 'comments'])
        ->orderBy('created_at', 'desc')
        ->get();

        return view('admin.rental-jobs.index', compact('rentalJobs'));
    }

    /**
     * Display the specified rental job (read-only).
     */
    public function show(RentalJob $rentalJob)
    {
        $rentalJob->load([
            'user.profile',
            'user.company',
            'products.product.brand',
            'products.product.category',
            'products.product.subCategory',
            'products.company',
            'supplyJobs.provider',
            'supplyJobs.products.product.brand',
            'comments.sender.profile',
            'comments.recipient.profile'
        ]);

        return view('admin.rental-jobs.show', compact('rentalJob'));
    }
}

