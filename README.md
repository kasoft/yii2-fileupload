File Upload und Handler Extension für Yii2
==========================================

FilePond Integration

Installation
------------

Install via Composer:

```
composer require kasoft/yii2-fileupload "~1.1"
```

Basics
------
This extension provides two parts:
- A Widget to render one or multiple FilePond upload inputs in your views.
- A handler class to process file uploads in your controller workflow.

Usage: View (render one FilePond input)
---------------------------------------
```php
use kasoft\fileupload\FileUpload;

echo FileUpload::widget([
    'id' => 'my-filepond',
    'url' => ['/upload/handle'], // controller action route
    'model' => ['model_id' => $model->id], // additional parameters for URL generation
    'multiple' => true,
    'maxFiles' => 5,
    'acceptedFiles' => 'image/*',
    'options' => [
        // additional FilePond options
        'chunkUploads' => true, // enabled by default for large files
    ],
]);
```

Widget Properties:
- `id`: HTML ID for the filepond input (default: 'my-filepond')
- `url`: Route or URL for the upload handler
- `model`: Array of parameters to pass to the upload URL
- `multiple`: Allow multiple file selection (default: true)
- `maxFiles`: Maximum number of files (default: 5)
- `acceptedFiles`: MIME types or file extensions (e.g., 'image/*', 'image/*,application/pdf')
- `options`: Additional FilePond configuration options

Usage: Controller (basic file upload)
-------------------------------------

```php
use kasoft\fileupload\UploadHandler;

public function actionHandle($model_id = null)
{
    UploadHandler::processUpload([
        'targetPath' => \Yii::getAlias('@webroot/uploads'),
        'modelClass' => \app\models\YourModel::class,
    ]);
    // Handler terminates the request automatically
}
```

Usage: Controller (model-bound upload with auto-save)
----------------------------------------------------

```php
use kasoft\fileupload\UploadHandler;

public function actionHandle($model_id = null, $model_attribute = 'filename')
{
    UploadHandler::processUpload([
        'targetPath' => \Yii::getAlias('@webroot/uploads'),
        'tmpPath' => \Yii::getAlias('@runtime/fileupload'), // for chunked uploads
        'modelClass' => \app\models\YourModel::class,
    ]);
    // The handler will:
    // 1. Create model_id subdirectory if moveToIdFolder=1 is passed
    // 2. Save filename to model attribute if model_id and model_attribute are passed
    // 3. Handle chunked uploads automatically
}
```

URL Parameters (GET)
-------------------
The upload handler reads these parameters from the request:

- `model_id`: ID of the model to update
- `model_attribute`: Model attribute to store the filename
- `moveToIdFolder`: If set to 1, creates a subfolder named after the model_id
- `emptyIdFolder`: If set to 1, empties the model_id folder before upload
- `patch`: Used internally for chunked uploads
- `chunkFileId`: Alternative parameter for chunk file identification

Chunked Uploads
---------------
The extension supports FilePond's chunked upload protocol automatically:

1. **Initialization (POST)**: Client sends Upload-Length header to get a transfer ID
2. **Upload Chunks (PATCH)**: Client sends file chunks using ?patch=<id>
3. **Resume Support (HEAD)**: Client can query current offset for resume
4. **Completion**: When all chunks are received, file is moved to final location

Chunked uploads bypass PHP's `post_max_size` and `upload_max_filesize` limitations.

File Processing
---------------
The `UploadHandler::processFile()` method handles:

- Filename sanitization (removes special characters)
- Directory creation with proper permissions
- Model integration (saves filename to specified attribute)
- Support for organized folder structure by model ID

Image Conversion
----------------
The extension includes an image conversion utility:

```php
use kasoft\fileupload\UploadHandler;

// Convert/resize image
$success = UploadHandler::convertImage(
    $sourceFile,     // source image path
    $targetFile,     // target image path  
    $targetWidth,    // target width (null to maintain aspect)
    $targetHeight    // target height (null to maintain aspect)
);
```

Supports JPEG, PNG, and GIF formats with transparency preservation for PNG.

Configuration Options (UploadHandler::processUpload)
---------------------------------------------------

- `targetPath`: Directory where files will be stored (default: @webroot/uploads)
- `tmpPath`: Temporary directory for chunked uploads (default: @runtime/fileupload)
- `modelClass`: ActiveRecord class name for model binding
- `chunkFileId`: Custom chunk file identifier (usually handled automatically)

Security Features
-----------------

- Filename sanitization removes dangerous characters
- Directory permissions are set to 0755
- Chunk file IDs are sanitized to prevent directory traversal
- Support for CSRF token validation via X-CSRF-Token header

Working Example
---------------
This repository includes a demo implementation. Check:
- Controller: `controllers/UploadController.php`
- View: `views/upload/index.php`

Ensure `@webroot/uploads` and `@runtime/fileupload` directories exist and are writable.

JavaScript Integration
---------------------
The widget automatically registers required assets and initializes FilePond. The JavaScript looks for:
- `window.KasoftFileUploadInit` function for manual initialization
- CSRF token from `<meta name="csrf-token">` for secure uploads

Multiple widget instances are supported by using different `id` values.

Error Handling
--------------
The handler returns appropriate HTTP status codes:
- 200: Success
- 201: Chunk transfer initialized
- 204: Chunk received, more expected
- 409: Chunk offset conflict (resume scenario)
- 500: Server errors (directory issues, file operations)

All responses terminate the PHP execution automatically.
