@php
    $selector = $selector ?? '#content_html';
    $height = $height ?? 550;
@endphp
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    $(document).ready(function() {
        tinymce.init({
            selector: '{{ $selector }}',
            height: {{ (int) $height }},
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
                'link image media | table | code | fullscreen | help',
            branding: false,
            promotion: false,
            resize: true,
            paste_as_text: false,
            image_title: true,
            automatic_uploads: true,
            images_upload_handler: function(blobInfo, progress) {
                return new Promise(function(resolve, reject) {
                    var xhr = new XMLHttpRequest();
                    xhr.withCredentials = true;
                    xhr.open('POST', '{{ route('admin.cms-pages.upload-image') }}');

                    xhr.upload.onprogress = function(e) {
                        if (e.lengthComputable) {
                            progress((e.loaded / e.total) * 100);
                        }
                    };

                    xhr.onload = function() {
                        var json;

                        if (xhr.status < 200 || xhr.status >= 300) {
                            reject('HTTP Error: ' + xhr.status);
                            return;
                        }

                        try {
                            json = JSON.parse(xhr.responseText);
                        } catch (error) {
                            reject('Invalid JSON: ' + xhr.responseText);
                            return;
                        }

                        if (!json || typeof json.location !== 'string') {
                            reject('Invalid response payload');
                            return;
                        }

                        resolve(json.location);
                    };

                    xhr.onerror = function() {
                        reject('Image upload failed due to a transport error.');
                    };

                    var formData = new FormData();
                    formData.append('_token', '{{ csrf_token() }}');
                    formData.append('file', blobInfo.blob(), blobInfo.filename());
                    xhr.send(formData);
                });
            },
            file_picker_types: 'image',
            file_picker_callback: function(callback, value, meta) {
                if (meta.filetype !== 'image') {
                    return;
                }

                var input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');

                input.onchange = function() {
                    var file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file) {
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function() {
                        var id = 'blobid' + (new Date()).getTime();
                        var blobCache = tinymce.activeEditor.editorUpload.blobCache;
                        var base64 = reader.result.split(',')[1];
                        var blobInfo = blobCache.create(id, file, base64);

                        blobCache.add(blobInfo);
                        callback(blobInfo.blobUri(), { title: file.name });
                    };
                    reader.readAsDataURL(file);
                };

                input.click();
            },
            setup: function(editor) {
                editor.on('init', function() {});
            }
        });

        $('{{ $selector }}').closest('form').on('submit', function() {
            if (typeof tinymce !== 'undefined') {
                tinymce.triggerSave();
            }
        });
    });
</script>
