@extends('adminlte::page')

@section('title', 'Job Ratings')

@section('content_header')
    <div class="row align-items-center">
        <div class="col">
            <h1 class="m-0">Job Ratings</h1>
        </div>
    </div>
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

    <!-- Statistics Cards -->
    <div class="row mb-3">
        <div class="col-lg-4 col-6">
            <a href="{{ route('admin.job-ratings.index', ['tab' => 'all']) }}" class="text-decoration-none">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3>{{ $stats['total_ratings'] }}</h3>
                        <p>Total Ratings</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-star"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-lg-4 col-6">
            <a href="{{ route('admin.job-ratings.index', ['tab' => 'low-rated']) }}" class="text-decoration-none">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3>{{ $stats['low_rated'] }}</h3>
                        <p>Low-Rated (≤{{ $lowRatingThreshold }} stars)</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-lg-4 col-6">
            <a href="{{ route('admin.job-ratings.index', ['tab' => 'pending']) }}" class="text-decoration-none">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3>{{ $stats['pending_rating'] }}</h3>
                        <p>Pending Rating</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'all' ? 'active' : '' }}" href="{{ route('admin.job-ratings.index', ['tab' => 'all']) }}">
                <i class="fas fa-list"></i> All Ratings
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'low-rated' ? 'active' : '' }}" href="{{ route('admin.job-ratings.index', ['tab' => 'low-rated']) }}">
                <i class="fas fa-thumbs-down"></i> Low-Rated Providers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'pending' ? 'active' : '' }}" href="{{ route('admin.job-ratings.index', ['tab' => 'pending']) }}">
                <i class="fas fa-hourglass-half"></i> Pending Ratings
            </a>
        </li>
    </ul>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ $listTitle }}</h3>
        </div>
        <div class="card-body">
            @if($tab === 'pending')
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Supply Job ID</th>
                                <th>Rental Job</th>
                                <th>Provider (Company)</th>
                                <th>Renter (Company)</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendingJobs as $job)
                                <tr>
                                    <td>{{ $job->id }}</td>
                                    <td>
                                        @if($job->rentalJob)
                                            <a href="{{ route('admin.rental-jobs.show', $job->rentalJob->id) }}">{{ $job->rentalJob->name }}</a>
                                            <br><small class="text-muted">#{{ $job->rental_job_id }}</small>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($job->providerCompany)
                                            <span class="badge badge-info">{{ $job->providerCompany->name }}</span>
                                            @if($job->providerCompany->blocked_by_admin_at)
                                                <span class="badge badge-dark ml-1">Blocked</span>
                                            @endif
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($job->rentalJob && $job->rentalJob->user && $job->rentalJob->user->company)
                                            <span class="badge badge-secondary">{{ $job->rentalJob->user->company->name }}</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>{{ $job->updated_at ? $job->updated_at->format($datetimeFormat) : '—' }}</td>
                                    <td>
                                        <a href="{{ route('admin.supply-jobs.show', $job->id) }}" class="btn btn-sm btn-outline-primary" title="View supply job">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if($job->providerCompany)
                                            @if($job->providerCompany->blocked_by_admin_at)
                                                <form action="{{ route('admin.job-ratings.unblock-company', $job->providerCompany) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Unblock company">
                                                        <i class="fas fa-unlock"></i> Unblock
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('admin.job-ratings.block-company', $job->providerCompany) }}" method="POST" class="d-inline js-confirm-block-form" data-company-name="{{ $job->providerCompany->name }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Block company (low-rated)">
                                                        <i class="fas fa-ban"></i> Block
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No jobs pending rating.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($pendingJobs && $pendingJobs->hasPages())
                    <div class="mt-2">
                        {{ $pendingJobs->withQueryString()->links() }}
                    </div>
                @endif
            @else
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Rating</th>
                                <th>Job / Supply Job</th>
                                <th>Provider (Company)</th>
                                <th>Renter (Company)</th>
                                <th>Renter comment</th>
                                <th>Provider reply</th>
                                <th>Rated At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ratings as $rating)
                                <tr>
                                    <td>
                                        @if($rating->rating <= 2)
                                            <span class="badge badge-danger">{{ $rating->rating }}/5</span>
                                        @elseif($rating->rating <= 4)
                                            <span class="badge badge-warning">{{ $rating->rating }}/5</span>
                                        @else
                                            <span class="badge badge-success">{{ $rating->rating }}/5</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($rating->supplyJob && $rating->supplyJob->rentalJob)
                                            <a href="{{ route('admin.rental-jobs.show', $rating->rental_job_id) }}">{{ $rating->supplyJob->rentalJob->name }}</a>
                                            <br><small class="text-muted">Supply #{{ $rating->supply_job_id }}</small>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($rating->supplyJob && $rating->supplyJob->providerCompany)
                                            @php $provider = $rating->supplyJob->providerCompany; @endphp
                                            <span class="badge badge-info">{{ $provider->name }}</span>
                                            @if($provider->blocked_by_admin_at)
                                                <span class="badge badge-dark ml-1">Blocked</span>
                                            @endif
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($rating->rentalJob && $rating->rentalJob->user && $rating->rentalJob->user->company)
                                            <span class="badge badge-secondary">{{ $rating->rentalJob->user->company->name }}</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($rating->comment)
                                            <span title="{{ $rating->comment }}">{{ Str::limit($rating->comment, 40) }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $providerReply = $rating->replies->where('supply_job_id', $rating->supply_job_id)->first() ?? $rating->replies->first();
                                        @endphp
                                        @if($providerReply)
                                            <span title="{{ $providerReply->reply }}">{{ Str::limit($providerReply->reply, 40) }}</span>
                                            @if($providerReply->replied_at)
                                                <br><small class="text-muted">{{ $providerReply->replied_at->format($datetimeFormat) }}</small>
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $rating->rated_at ? $rating->rated_at->format($datetimeFormat) : '—' }}</td>
                                    <td>
                                        @if($rating->supply_job_id)
                                            <a href="{{ route('admin.supply-jobs.show', $rating->supply_job_id) }}" class="btn btn-sm btn-outline-primary" title="View supply job">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        @endif
                                        @if($rating->supplyJob && $rating->supplyJob->providerCompany)
                                            @php $provider = $rating->supplyJob->providerCompany; @endphp
                                            @if($provider->blocked_by_admin_at)
                                                <form action="{{ route('admin.job-ratings.unblock-company', $provider) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Unblock company">
                                                        <i class="fas fa-unlock"></i> Unblock
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('admin.job-ratings.block-company', $provider) }}" method="POST" class="d-inline js-confirm-block-form" data-company-name="{{ $provider->name }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Block company">
                                                        <i class="fas fa-ban"></i> Block
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No ratings found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($ratings && $ratings->hasPages())
                    <div class="mt-2">
                        {{ $ratings->withQueryString()->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div class="modal fade" id="blockCompanyModal" tabindex="-1" role="dialog" aria-labelledby="blockCompanyModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="blockCompanyModalTitle">
                        <i class="fas fa-ban text-danger mr-2"></i>Block company
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body pt-0">
                    <p class="mb-2">Block this company? They will not appear in provider listings until unblocked.</p>
                    <p class="mb-0 font-weight-bold text-secondary" id="blockCompanyName"></p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="blockCompanyConfirmBtn">
                        <i class="fas fa-ban mr-1"></i>Block
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('blockCompanyModal');
            var nameEl = document.getElementById('blockCompanyName');
            var confirmBtn = document.getElementById('blockCompanyConfirmBtn');
            var formToSubmit = null;

            document.querySelectorAll('form.js-confirm-block-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    formToSubmit = form;
                    var name = form.getAttribute('data-company-name') || 'this company';
                    nameEl.textContent = 'Company: ' + name;
                    $(modal).modal('show');
                });
            });

            confirmBtn.addEventListener('click', function () {
                $(modal).modal('hide');
                if (formToSubmit) {
                    formToSubmit.submit();
                }
                formToSubmit = null;
            });

            $(modal).on('hidden.bs.modal', function () {
                formToSubmit = null;
            });
        })();
    </script>
@stop
