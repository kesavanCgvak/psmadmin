@extends('adminlte::page')

@section('title', 'Currency Details')

@section('content_header')
    <h1>Currency Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">{{ $currency->code }} - {{ $currency->name }}</h3>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h1 style="font-size: 4em;">{{ $currency->symbol }}</h1>
                    </div>

                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $currency->id }}</dd>

                        <dt class="col-sm-4">Code</dt>
                        <dd class="col-sm-8"><span class="badge badge-primary">{{ $currency->code }}</span></dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $currency->name }}</strong></dd>

                        <dt class="col-sm-4">Symbol</dt>
                        <dd class="col-sm-8"><span style="font-size: 1.5em;">{{ $currency->symbol }}</span></dd>

                        <dt class="col-sm-4">Companies Using</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $currency->companies->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $currency->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $currency->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.currencies.edit', $currency) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.currencies.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Companies Using This Currency</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($currency->companies->count() > 0)
                        <ul class="list-group">
                            @foreach($currency->companies as $company)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $company->name }}
                                    <span class="badge badge-primary badge-pill">{{ $company->country?->name ?? 'N/A' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No companies using this currency yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

