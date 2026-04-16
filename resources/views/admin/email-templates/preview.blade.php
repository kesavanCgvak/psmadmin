<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preview: {{ $template->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .preview-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .preview-header {
            border-bottom: 2px solid #726d6c;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .preview-header h2 {
            margin: 0;
            color: #333;
        }
        .preview-meta {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }
        .preview-subject {
            background-color: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #726d6c;
            margin-bottom: 20px;
        }
        .preview-subject strong {
            color: #333;
        }
        .preview-body {
            border: 1px solid #dee2e6;
            padding: 20px;
            min-height: 200px;
        }
        .preview-footer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="preview-header">
            <h2>Email Template Preview</h2>
            <div class="preview-meta">
                <strong>Template:</strong> {{ $template->name }}<br>
                <strong>Preview Date:</strong> {{ now()->format('F d, Y \a\t g:i A') }}
            </div>
        </div>

        <div class="preview-subject">
            <strong>Subject:</strong> {{ $subject }}
        </div>

        <div class="preview-body">
            {!! $body !!}
        </div>

        <div class="preview-footer">
            <p>This is a preview with sample data. Actual emails will use real data.</p>
            <button onclick="window.close()" style="padding: 8px 16px; background-color: #726d6c; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Close Preview
            </button>
        </div>
    </div>
</body>
</html>
