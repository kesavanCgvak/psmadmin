@extends('adminlte::page')

@section('title', 'Edit Terms and Conditions')

@section('content_header')
    <h1>Edit Terms and Conditions</h1>
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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="icon fas fa-ban"></i> Validation Error!</h5>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-edit"></i> {{ $terms->id ? 'Edit' : 'Create' }} Terms and Conditions
                    </h3>
                </div>
                <form action="{{ route('admin.terms-and-conditions.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card-body">
                        <div class="form-group">
                            <label for="description">Terms and Conditions Content</label>
                            <textarea 
                                class="form-control @error('description') is-invalid @enderror" 
                                id="description" 
                                name="description" 
                                rows="25" 
                                required
                                placeholder="Enter the terms and conditions content here..."
                            >{{ old('description', $terms->description ?? '') }}</textarea>
                            @error('description')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                            <small class="form-text text-muted">
                                Use the rich text editor toolbar above to format your content. You can add bold, italic, headings, lists, and more.
                            </small>
                        </div>

                        @if($terms && $terms->id)
                        <div class="alert alert-info">
                            <h5><i class="icon fas fa-info"></i> Last Updated</h5>
                            <p class="mb-0">
                                <strong>Last modified:</strong> {{ $terms->updated_at->format('F d, Y \a\t g:i A') }}<br>
                                <strong>Created:</strong> {{ $terms->created_at->format('F d, Y \a\t g:i A') }}
                            </p>
                        </div>
                        @endif

                        <div class="alert alert-warning">
                            <h5><i class="icon fas fa-exclamation-triangle"></i> Important Notes</h5>
                            <ul class="mb-0">
                                <li>Changes to terms and conditions will be immediately reflected in the system.</li>
                                <li>Users accessing the API will receive the updated version with HTML formatting.</li>
                                <li>Make sure to review all content before saving.</li>
                                <li>Use the rich text editor toolbar to format headings, lists, bold, italic, and more.</li>
                                <li>All formatting and styling will be preserved when saved.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Terms and Conditions
                        </button>
                        <a href="{{ route('admin.terms-and-conditions.index') }}" class="btn btn-default">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop

@section('js')
<!-- TinyMCE Script -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize TinyMCE Rich Text Editor
        tinymce.init({
            selector: '#description',
            height: 650,
            menubar: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'table', 'wordcount', 'help'
            ],
            toolbar: 'undo redo | formatselect | ' +
                'bold italic underline strikethrough | forecolor backcolor | ' +
                'alignleft aligncenter alignright alignjustify | ' +
                'bullist numlist | outdent indent | ' +
                'link | table | code | fullscreen | help',
            formats: {
                h1: { block: 'h1', classes: 'h1' },
                h2: { block: 'h2', classes: 'h2' },
                h3: { block: 'h3', classes: 'h3' },
                h4: { block: 'h4', classes: 'h4' },
                h5: { block: 'h5', classes: 'h5' },
                h6: { block: 'h6', classes: 'h6' }
            },
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.6; } ' +
                'h1 { font-size: 2em; margin-top: 1em; margin-bottom: 0.5em; } ' +
                'h2 { font-size: 1.75em; margin-top: 1em; margin-bottom: 0.5em; } ' +
                'h3 { font-size: 1.5em; margin-top: 1em; margin-bottom: 0.5em; } ' +
                'h4 { font-size: 1.25em; margin-top: 1em; margin-bottom: 0.5em; } ' +
                'p { margin-bottom: 1em; } ' +
                'ul, ol { margin-bottom: 1em; padding-left: 2em; } ' +
                'li { margin-bottom: 0.5em; }',
            branding: false,
            promotion: false,
            resize: true,
            elementpath: true,
            paste_as_text: false,
            paste_remove_styles_if_webkit: false,
            paste_strip_class_attributes: 'none',
            valid_elements: '*[*]',
            extended_valid_elements: '*[*]',
            setup: function(editor) {
                editor.on('init', function() {
                    console.log('TinyMCE initialized successfully');
                });
            }
        });
    });
</script>
@stop
