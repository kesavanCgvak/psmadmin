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
                'insertdatetime', 'table', 'wordcount', 'help'
            ],
            toolbar: 'undo redo | formatselect | ' +
                'bold italic underline strikethrough | forecolor backcolor | ' +
                'alignleft aligncenter alignright alignjustify | ' +
                'bullist numlist | outdent indent | ' +
                'link | table | code | fullscreen | help',
            branding: false,
            promotion: false,
            resize: true,
            paste_as_text: false,
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
