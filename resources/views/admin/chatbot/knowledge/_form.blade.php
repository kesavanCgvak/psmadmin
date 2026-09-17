@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card">
    <form action="{{ $action }}" method="POST">
        @csrf
        @if(strtoupper($method) !== 'POST')
            @method($method)
        @endif
        <div class="card-body">
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                       value="{{ old('title', $entry->title ?? '') }}" required maxlength="255">
                @error('title')<span class="invalid-feedback">{{ $message }}</span>@enderror
            </div>
            <div class="row">
                <div class="col-12 col-md-6">
                    <div class="form-group">
                        <label for="category">Category</label>
                        <input type="text" class="form-control @error('category') is-invalid @enderror" id="category" name="category"
                               value="{{ old('category', $entry->category ?? '') }}" maxlength="100" placeholder="e.g. Account, Billing, Jobs">
                        @error('category')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="form-group">
                        <label for="sort_order">Sort order</label>
                        <input type="number" class="form-control @error('sort_order') is-invalid @enderror" id="sort_order" name="sort_order"
                               value="{{ old('sort_order', $entry->sort_order ?? 0) }}" min="0" max="999999">
                        @error('sort_order')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="question">Question / topic (optional)</label>
                <input type="text" class="form-control @error('question') is-invalid @enderror" id="question" name="question"
                       value="{{ old('question', $entry->question ?? '') }}" maxlength="500"
                       placeholder="How do I create a rental request?">
                @error('question')<span class="invalid-feedback">{{ $message }}</span>@enderror
            </div>
            <div class="form-group">
                <label for="content">Answer / content</label>
                <textarea class="form-control @error('content') is-invalid @enderror" id="content" name="content" rows="8" required>{{ old('content', $entry->content ?? '') }}</textarea>
                @error('content')<span class="invalid-feedback">{{ $message }}</span>@enderror
                <small class="form-text text-muted">This text is fed to the chatbot as knowledge when users ask related questions.</small>
            </div>
            <div class="form-group">
                <label for="keywords">Keywords (optional)</label>
                <input type="text" class="form-control @error('keywords') is-invalid @enderror" id="keywords" name="keywords"
                       value="{{ old('keywords', $entry->keywords ?? '') }}" maxlength="1000"
                       placeholder="rental, request, job, offer">
                @error('keywords')<span class="invalid-feedback">{{ $message }}</span>@enderror
                <small class="form-text text-muted">Comma-separated terms that help match this entry to user questions.</small>
            </div>
            <div class="form-group mb-0">
                <input type="hidden" name="is_active" value="0">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', ($entry->is_active ?? true) ? '1' : '0') == '1')>
                    <label class="custom-control-label" for="is_active">Active (available to the chatbot)</label>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ $submitLabel }}</button>
            <a href="{{ route('admin.chatbot.knowledge.index') }}" class="btn btn-default">Cancel</a>
        </div>
    </form>
</div>
