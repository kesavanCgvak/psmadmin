@if($emailLog->acceptsRetryAction())
    @php
        $retryBlockReason = $emailLog->temporaryRetryBlockReason();
    @endphp
    @if($retryBlockReason)
        <button type="button" class="btn btn-warning btn-sm {{ !empty($showLabel) ? 'btn-block' : '' }}" disabled title="{{ $retryBlockReason }}">
            <i class="fas fa-redo"></i>@if(!empty($showLabel)) Retry Email @else <span class="sr-only">Retry Email</span>@endif
        </button>
    @else
        <form method="POST" action="{{ route('admin.email-logs.retry', $emailLog) }}" class="js-email-retry-form {{ !empty($showLabel) ? '' : 'd-inline' }}">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm {{ !empty($showLabel) ? 'btn-block' : '' }}" title="Retry Email">
                <i class="fas fa-redo"></i>@if(!empty($showLabel)) Retry Email @else <span class="sr-only">Retry Email</span>@endif
            </button>
        </form>
    @endif
@endif
