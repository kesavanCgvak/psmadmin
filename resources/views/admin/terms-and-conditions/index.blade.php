@extends('adminlte::page')

@section('title', 'Terms and Conditions')

@section('content_header')
    <h1>Terms and Conditions</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-file-contract"></i> Current Terms and Conditions
                    </h3>
                    <div class="card-tools">
                        @if($terms)
                            <small class="text-muted">
                                Last updated: {{ $terms->updated_at->format('F d, Y \a\t g:i A') }}
                            </small>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    @if($terms)
                        <div class="terms-content" style="background-color: #ffffff; padding: 30px; border-radius: 5px; max-height: 800px; overflow-y: auto; border: 1px solid #dee2e6;">
                            {!! $terms->description !!}
                        </div>
                    @else
                        <div class="alert alert-warning">
                            <i class="icon fas fa-exclamation-triangle"></i>
                            No terms and conditions have been set yet. Please create them using the button below.
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.terms-and-conditions.edit') }}" class="btn btn-primary">
                        <i class="fas fa-edit"></i> {{ $terms ? 'Edit Terms and Conditions' : 'Create Terms and Conditions' }}
                    </a>
                    <a href="{{ route('dashboard') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
<style>
    .terms-content {
        line-height: 1.8;
    }
    .terms-content h1,
    .terms-content h2,
    .terms-content h3,
    .terms-content h4,
    .terms-content h5,
    .terms-content h6 {
        margin-top: 1.5em;
        margin-bottom: 0.5em;
        font-weight: bold;
    }
    .terms-content h1 { font-size: 2em; }
    .terms-content h2 { font-size: 1.75em; }
    .terms-content h3 { font-size: 1.5em; }
    .terms-content h4 { font-size: 1.25em; }
    .terms-content p {
        margin-bottom: 1em;
    }
    .terms-content ul,
    .terms-content ol {
        margin-bottom: 1em;
        padding-left: 2em;
    }
    .terms-content li {
        margin-bottom: 0.5em;
    }
    .terms-content strong {
        font-weight: bold;
    }
    .terms-content em {
        font-style: italic;
    }
</style>
@stop
