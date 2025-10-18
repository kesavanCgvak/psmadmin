@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Admin Dashboard</h1>
@stop

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Users</span>
                        <span class="info-box-number">-</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-building"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Companies</span>
                        <span class="info-box-number">-</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-boxes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Products</span>
                        <span class="info-box-number">-</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Equipment</span>
                        <span class="info-box-number">-</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Welcome to PSM Admin Panel</h3>
                    </div>
                    <div class="card-body">
                        <p>Use the navigation menu on the left to manage your application.</p>
                        <ul>
                            <li><strong>User Management:</strong> Create and manage user accounts</li>
                            <li><strong>Company Management:</strong> Manage companies and their details</li>
                            <li><strong>Product Catalog:</strong> Manage categories, brands, and products</li>
                            <li><strong>Geography:</strong> Manage regions, countries, states, and cities</li>
                            <li><strong>Equipment:</strong> Track company equipment</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
@stop
