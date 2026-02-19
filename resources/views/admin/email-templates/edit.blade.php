@extends('adminlte::page')

@section('title', 'Edit Email Template')

@section('content_header')
    <h1>Edit Email Template: {{ $emailTemplate->name }}</h1>
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
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-edit"></i> Edit Email Template
                    </h3>
                </div>
                <form action="{{ route('admin.email-templates.update', $emailTemplate) }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card-body">
                        <div class="form-group">
                            <label for="name">Template Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="name" 
                                   name="name" 
                                   value="{{ $emailTemplate->name }}" 
                                   readonly
                                   disabled>
                            <small class="form-text text-muted">
                                Template identifier (cannot be changed)
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="subject">Email Subject <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control @error('subject') is-invalid @enderror" 
                                   id="subject" 
                                   name="subject" 
                                   value="{{ old('subject', $emailTemplate->subject) }}" 
                                   required
                                   placeholder="e.g., Welcome to ProSub Marketplace">
                            @error('subject')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                            <small class="form-text text-muted">
                                You can use variables like @{{name}}, @{{$email}}, etc.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" 
                                      name="description" 
                                      rows="2"
                                      placeholder="Brief description of what this email is used for">{{ old('description', $emailTemplate->description) }}</textarea>
                            @error('description')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="body">Email Body <span class="text-danger">*</span></label>
                            
                            <!-- Editor Toggle -->
                            <div class="btn-group mb-2" role="group" aria-label="Editor Type">
                                <button type="button" class="btn btn-sm btn-outline-primary active" id="visualEditorBtn">
                                    <i class="fas fa-eye"></i> Visual Editor
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="htmlEditorBtn">
                                    <i class="fas fa-code"></i> HTML Editor
                                </button>
                            </div>
                            
                            <!-- Visual Editor (TinyMCE) -->
                            <div id="visualEditorContainer">
                                <textarea class="form-control @error('body') is-invalid @enderror" 
                                          id="body" 
                                          name="body" 
                                          rows="20"
                                          required>{{ old('body', $emailTemplate->body) }}</textarea>
                            </div>
                            
                            <!-- HTML Editor (CodeMirror) -->
                            <div id="htmlEditorContainer" style="display: none;">
                                <textarea class="form-control @error('body') is-invalid @enderror" 
                                          id="bodyHtml" 
                                          name="" 
                                          rows="20"
                                          required>{{ old('body', $emailTemplate->body) }}</textarea>
                            </div>
                            
                            @error('body')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                            <small class="form-text text-muted">
                                <strong>Visual Editor:</strong> Use the toolbar to format text. Click "Source code" button to edit HTML directly.<br>
                                <strong>HTML Editor:</strong> Edit HTML code directly. Variables: @{{name}}, @{{$email}}, etc.
                            </small>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" 
                                       class="form-check-input" 
                                       id="is_active" 
                                       name="is_active" 
                                       value="1"
                                       {{ old('is_active', $emailTemplate->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    Active (Template will be used when sending emails)
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Template
                        </button>
                        <a href="{{ route('admin.email-templates.index') }}" class="btn btn-default">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <a href="{{ route('admin.email-templates.preview', $emailTemplate) }}" 
                           class="btn btn-info" 
                           target="_blank">
                            <i class="fas fa-eye"></i> Preview
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-code"></i> Available Variables
                    </h3>
                </div>
                <div class="card-body">
                    @if($emailTemplate->variables && count($emailTemplate->variables) > 0)
                        <p class="text-muted small">Click to insert variable:</p>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach($emailTemplate->variables as $variable)
                                @php
                                    $varName = is_array($variable) ? ($variable['name'] ?? '') : $variable;
                                @endphp
                                <button type="button" 
                                        class="btn btn-sm btn-outline-info insert-variable-btn" 
                                        data-variable="{{ $varName }}"
                                        title="Insert {{ $varName }}">
                                    {{ $varName }}
                                </button>
                            @endforeach
                        </div>
                        <p class="text-muted small mb-0">
                            <strong>Format:</strong> <code>@{{ $variable_name }}</code> (Blade format - recommended)
                            <br><small>Also supports: <code>@{{variable_name}}</code> or <code>@{{$variable_name}}</code></small>
                        </p>
                    @else
                        <p class="text-muted">No variables defined for this template.</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i> Template Info
                    </h3>
                </div>
                <div class="card-body">
                    <p><strong>Created:</strong><br>
                    <small>{{ $emailTemplate->created_at->format('F d, Y \a\t g:i A') }}</small></p>
                    
                    <p><strong>Last Updated:</strong><br>
                    <small>{{ $emailTemplate->updated_at->format('F d, Y \a\t g:i A') }}</small></p>
                    
                    <p><strong>Status:</strong><br>
                    @if($emailTemplate->is_active)
                        <span class="badge badge-success">Active</span>
                    @else
                        <span class="badge badge-secondary">Inactive</span>
                    @endif
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-lightbulb"></i> Tips
                    </h3>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled small">
                        <li class="mb-2">
                            <i class="fas fa-check text-success"></i> Use Visual Editor for easy formatting
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success"></i> Switch to HTML Editor for advanced editing
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success"></i> Test with Preview before saving
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success"></i> Variables are case-sensitive
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success"></i> Keep templates mobile-friendly
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
<!-- TinyMCE Script -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

<!-- CodeMirror Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>

<script>
    $(document).ready(function() {
        var visualEditor, htmlEditor;
        var currentEditor = 'visual'; // 'visual' or 'html'
        var htmlEditorInitialized = false;
        
        // Initialize TinyMCE Visual Editor
        tinymce.init({
            selector: '#body',
            height: 600,
            menubar: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'table', 'wordcount', 'help', 'image', 'media'
            ],
            toolbar: 'undo redo | formatselect | ' +
                'bold italic underline strikethrough | forecolor backcolor | ' +
                'alignleft aligncenter alignright alignjustify | ' +
                'bullist numlist | outdent indent | ' +
                'link image table | code | fullscreen | help',
            formats: {
                h1: { block: 'h1', classes: 'h1' },
                h2: { block: 'h2', classes: 'h2' },
                h3: { block: 'h3', classes: 'h3' },
                h4: { block: 'h4', classes: 'h4' },
                h5: { block: 'h5', classes: 'h5' },
                h6: { block: 'h6', classes: 'h6' }
            },
            content_style: 'body { font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; } ' +
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
                visualEditor = editor;
                editor.on('init', function() {
                    console.log('TinyMCE Visual Editor initialized');
                });
            }
        });

        // Function to initialize CodeMirror HTML Editor
        function initHtmlEditor() {
            if (!htmlEditorInitialized) {
                // Get initial content - try from TinyMCE first, then textarea
                var initialContent = '';
                try {
                    if (tinymce.get('body')) {
                        initialContent = tinymce.get('body').getContent();
                    }
                } catch(e) {
                    initialContent = $('#bodyHtml').val() || $('#body').val() || '';
                }
                
                if (!initialContent) {
                    initialContent = $('#bodyHtml').val() || '';
                }
                
                // Show container temporarily for proper initialization
                var container = $('#htmlEditorContainer');
                var wasHidden = container.is(':hidden');
                
                if (wasHidden) {
                    container.show();
                }
                
                // Initialize CodeMirror
                htmlEditor = CodeMirror.fromTextArea(document.getElementById('bodyHtml'), {
                    lineNumbers: true,
                    mode: 'htmlmixed',
                    theme: 'monokai',
                    indentUnit: 2,
                    indentWithTabs: false,
                    lineWrapping: true,
                    autoCloseTags: true,
                    matchTags: true,
                    value: initialContent, // Set initial content
                    extraKeys: {
                        "Ctrl-J": "toMatchingTag",
                        "Ctrl-Space": "autocomplete"
                    }
                });
                
                // Set content explicitly
                if (initialContent) {
                    htmlEditor.setValue(initialContent);
                }
                
                htmlEditorInitialized = true;
                console.log('CodeMirror HTML Editor initialized with content length:', initialContent.length);
                
                // Hide again if it was hidden
                if (wasHidden && currentEditor === 'visual') {
                    container.hide();
                }
            }
        }

        // Editor Toggle Functions
        $('#visualEditorBtn').on('click', function() {
            if (currentEditor === 'html' && htmlEditorInitialized) {
                // Save HTML editor content to visual editor
                var htmlContent = htmlEditor.getValue();
                tinymce.get('body').setContent(htmlContent);
            }
            
            // Switch to visual editor
            $('#visualEditorContainer').show();
            $('#htmlEditorContainer').hide();
            $('#visualEditorBtn').removeClass('btn-outline-primary').addClass('btn-primary active');
            $('#htmlEditorBtn').removeClass('btn-secondary active').addClass('btn-outline-secondary');
            
            currentEditor = 'visual';
        });

        $('#htmlEditorBtn').on('click', function() {
            // Initialize HTML editor if not already initialized
            if (!htmlEditorInitialized) {
                initHtmlEditor();
            }
            
            if (currentEditor === 'visual') {
                // Save visual editor content to HTML editor
                var visualContent = tinymce.get('body').getContent();
                htmlEditor.setValue(visualContent);
            }
            
            // Switch to HTML editor
            $('#visualEditorContainer').hide();
            $('#htmlEditorContainer').show();
            $('#htmlEditorBtn').removeClass('btn-outline-secondary').addClass('btn-secondary active');
            $('#visualEditorBtn').removeClass('btn-primary active').addClass('btn-outline-primary');
            
            currentEditor = 'html';
            
            // Refresh CodeMirror to ensure it's visible and properly sized
            setTimeout(function() {
                if (htmlEditor) {
                    htmlEditor.refresh();
                    htmlEditor.focus();
                }
            }, 200);
        });

        // Variable insertion buttons
        $('.insert-variable-btn').on('click', function() {
            var variable = $(this).data('variable');
            // Build variable syntax: Blade format with dollar sign and spaces
            // Use String.fromCharCode to avoid Blade parsing issues
            var openBrace = String.fromCharCode(123); // '{'
            var closeBrace = String.fromCharCode(125); // '}'
            var dollarSign = String.fromCharCode(36); // '$'
            var space = ' ';
            // Format: braces + space + dollar + variable + space + braces
            var variableText = openBrace + openBrace + space + dollarSign + variable + space + closeBrace + closeBrace;
            
            console.log('Inserting variable:', variableText); // Debug log
            
            if (currentEditor === 'visual') {
                // Insert into TinyMCE
                tinymce.get('body').insertContent(variableText);
            } else {
                // Make sure HTML editor is initialized
                if (!htmlEditorInitialized) {
                    initHtmlEditor();
                }
                // Insert into CodeMirror
                var cursor = htmlEditor.getCursor();
                htmlEditor.replaceRange(variableText, cursor);
                htmlEditor.focus();
            }
        });

        // Update textarea value before form submission
        $('form').on('submit', function(e) {
            if (currentEditor === 'visual') {
                // Ensure TinyMCE content is saved to the form field
                try {
                    if (tinymce.get('body')) {
                        tinymce.triggerSave();
                    }
                } catch(err) {
                    console.error('Error saving TinyMCE:', err);
                }
                // TinyMCE saves to #body textarea automatically
            } else {
                // HTML Editor is active - sync content to main body field
                if (htmlEditor && htmlEditorInitialized) {
                    // Save CodeMirror content back to textarea
                    htmlEditor.save();
                }
                // Get the content from HTML editor textarea
                var htmlContent = $('#bodyHtml').val();
                // Always update the main body field which has name="body"
                if (htmlContent) {
                    $('#body').val(htmlContent);
                }
            }
            
            // Debug: log what we're submitting
            console.log('Submitting form with body length:', $('#body').val().length);
            console.log('Current editor:', currentEditor);
            
            // Ensure form validation passes
            var bodyValue = $('#body').val();
            if (!bodyValue || bodyValue.trim() === '') {
                e.preventDefault();
                alert('Email body cannot be empty!');
                return false;
            }
        });
    });
</script>
@stop

@section('css')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<style>
    .CodeMirror {
        border: 1px solid #ddd;
        height: 600px;
        font-size: 14px;
        background-color: #272822 !important;
        color: #f8f8f2 !important;
    }
    
    .CodeMirror-scroll {
        min-height: 600px;
    }
    
    .CodeMirror-gutters {
        background-color: #272822 !important;
        border-right: 1px solid #555 !important;
    }
    
    .CodeMirror-linenumber {
        color: #90908a !important;
    }
    
    #htmlEditorContainer {
        position: relative;
    }
    
    #htmlEditorContainer .CodeMirror {
        display: block;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    #visualEditorBtn.active,
    #htmlEditorBtn.active {
        z-index: 1;
    }
    
    .btn-group .btn {
        border-radius: 0;
    }
    
    .btn-group .btn:first-child {
        border-top-left-radius: 0.25rem;
        border-bottom-left-radius: 0.25rem;
    }
    
    .btn-group .btn:last-child {
        border-top-right-radius: 0.25rem;
        border-bottom-right-radius: 0.25rem;
    }
    
    .insert-variable-btn {
        margin: 2px;
        font-size: 11px;
        padding: 4px 8px;
    }
    
    .gap-1 {
        gap: 0.25rem;
    }
</style>
@stop
