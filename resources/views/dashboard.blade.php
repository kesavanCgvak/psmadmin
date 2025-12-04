@extends('adminlte::page')

@section('title', 'Dashboard')

@section('head')
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
<link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
@endsection

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-3 col-6">
            <!-- Small box -->
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ \App\Models\Product::count() }}</h3>
                    <p>Total Products</p>
                </div>
                <div class="icon">
                    <i class="fas fa-cubes"></i>
                </div>
                <a href="{{ route('admin.products.index') }}" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <!-- ./col -->
        <div class="col-lg-3 col-6">
            <!-- Small box -->
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ \App\Models\Company::count() }}</h3>
                    <p>Registered Companies</p>
                </div>
                <div class="icon">
                    <i class="fas fa-building"></i>
                </div>
                <a href="{{ route('admin.companies.index') }}" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <!-- ./col -->
        <div class="col-lg-3 col-6">
            <!-- Small box -->
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ \App\Models\User::where('role', '!=', 'super_admin')->count() }}</h3>
                    <p>Total Users</p>
                </div>
                <div class="icon">
                    <i class="fas fa-users"></i>
                </div>
                <a href="{{ route('admin.users.index') }}" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <!-- ./col -->
        <div class="col-lg-3 col-6">
            <!-- Small box -->
            <div class="small-box bg-danger">
                <div class="inner">
                    <h3>{{ \App\Models\Equipment::count() }}</h3>
                    <p>All Equipment</p>
                </div>
                <div class="icon">
                    <i class="fas fa-boxes"></i>
                </div>
                <a href="{{ route('admin.equipment.index') }}" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <!-- ./col -->
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie mr-1"></i>
                        System Statistics
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon"><i class="fas fa-handshake"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Active Rental Jobs</span>
                                    <span class="info-box-number">{{ \App\Models\RentalJob::where('status', 'open')->count() }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon"><i class="fas fa-copyright"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Brands</span>
                                    <span class="info-box-number">{{ \App\Models\Brand::count() }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon"><i class="fas fa-th-large"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Categories</span>
                                    <span class="info-box-number">{{ \App\Models\Category::count() }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon"><i class="fas fa-th"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Sub Categories</span>
                                    <span class="info-box-number">{{ \App\Models\SubCategory::count() }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- <dl class="row">
                        <dt class="col-sm-6">Active Rental Jobs</dt>
                        <dd class="col-sm-6">{{ \App\Models\RentalJob::where('status', 'open')->count() }}</dd>

                        <dt class="col-sm-6">Brands</dt>
                        <dd class="col-sm-6">{{ \App\Models\Brand::count() }}</dd>

                        <dt class="col-sm-6">Categories</dt>
                        <dd class="col-sm-6">{{ \App\Models\Category::count() }}</dd>

                        <dt class="col-sm-6">Sub Categories</dt>
                        <dd class="col-sm-6">{{ \App\Models\SubCategory::count() }}</dd>
                    </dl> -->
                </div>
            </div>
        </div>
    </div>

@stop

@section('css')
    {{-- Add here extra stylesheets --}}
@stop

@section('js')
    <script> console.log("Dashboard loaded!"); </script>
@stop
