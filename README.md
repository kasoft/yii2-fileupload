Yii2 File Upload with FilePond Integration
===========================================

This extension provides a compact upload widget for Yii2 based on FilePond and a matching server-side upload handler. It lets you upload single or multiple files from views and store them on the server — optionally with ActiveRecord binding and image variants.

Features
--------
- FilePond as a Yii2 widget (including auto-initialization for plain `<input>` fields)
- Multiple uploads and acceptance filters (MIME types/extensions)
- Chunked uploads (enabled by default) to bypass PHP post_max_size limits
- Server-side UploadHandler with:
  - configurable target path (@webroot/uploads by default)
  - optional subfolder by model_id
  - optional cleaning of the target folder on reassignment
  - automatic model binding (ActiveRecord) and attribute assignment
  - optional creation of image variants (a_ and w_)
- CSRF support via X-CSRF-Token header

Requirements
------------
- PHP >= 8.1
- Yii2 ~ 2.0.43
- npm-asset/filepond ^4 (installed via Composer dependency)

Installation
------------
Install via Composer:

```bash
composer require kasoft/yii2-fileupload
```

PSR-4 autoload configuration is included in the package.

Quick Start: Use the widget in a view
-------------------------------------
In a view (e.g., views/site/index.php):


```php
<?php
use kasoft\fileupload\FileUpload;
use yii\helpers\Url;

echo FileUpload::widget([
    'id' => 'my-filepond',
    'url' => Url::to(['/upload/handle']), // Controller action that calls UploadHandler::processUpload()
    'paramName' => 'file',                // Name of the file field
    'acceptedFiles' => 'image/*',         // e.g. 'image/*,application/pdf'
    'maxFiles' => 5,
    'multiple' => true,
    // 'model_id' => $model->id ?? null,  // optional: used server-side as subfolder
    // 'options' => [ 'chunkUploads' => false ], // FilePond options (see below)
]);
?>
```

Multiple instances in the same view
-----------------------------------
The widget supports multiple instances via options['instances']:

```php
<?php
echo FileUpload::widget([
    'options' => [
        'instances' => [
            [
                'id' => 'pond-a',
                'url' => Url::to(['/upload/handle']),
                'paramName' => 'file_a',
                'multiple' => false,
                'acceptedFiles' => 'image/*',
            ],
            [
                'id' => 'pond-b',
                'url' => Url::to(['/upload/handle']),
                'paramName' => 'file_b',
                'multiple' => true,
                'maxFiles' => 3,
            ],
        ],
    ],
]);
?>
```

Alternative: plain `<input>` without the widget
---------------------------------------------
The JS helper auto-initializes all inputs with class="filepond" on DOM ready. Supported data attributes:
- data-url (or data-action)
- data-param
- data-multiple ("true" | "false")
- data-accepted (e.g., "image/*,application/pdf")
- data-maxfiles (number)
- data-model-id (optional; sent to the server and used as a subfolder)

Example:

```html
<input type="file" class="filepond" name="file" data-url="/upload/handle" data-multiple="true" data-accepted="image/*" data-maxfiles="5" />
```

Controller: process the upload
------------------------------
Call the static handler from your action. It returns an array that you typically send back as JSON.

```php
<?php
use yii\web\Response;
use kasoft\fileupload\UploadHandler;

public function actionHandle($id = null)
{
    \Yii::$app->response->format = Response::FORMAT_JSON;
    return UploadHandler::processUpload([
        // Target path
        // 'basePath' => \Yii::getAlias('@webroot/uploads'),
        // 'path' => \Yii::getAlias('@webroot/uploads/custom'), // overrides basePath

        // Source (field name)
        'postName' => 'file', // alias: 'paramName'

        // Model binding (optional)
        // 'modelClass' => app\models\MyModel::class,
        // 'modelId' => $id,                    // or via POST 'model_id'
        // 'attribute' => 'file',               // attribute to receive the filename
        // 'assign' => 'first',                 // 'first' | 'json' | 'array' for multi-file uploads
        // 'saveModel' => true,
        // 'validate' => false,

        // Variants (optional, images only)
        // 'createVariants' => true,            // creates a_ and w_
        // 'a_' => [null, 200],                 // height 200, keep aspect ratio
        // 'w_' => [800, null],                 // width 800, keep aspect ratio

        // Target folder behavior
        // 'cleanTarget' => true,                // default: true when modelId is set, else false

        // Chunk temp path (optional)
        // 'tmpPath' => \Yii::getAlias('@runtime/filepond-chunks'),
    ]);
}
?>
```

Where are files saved?
----------------------
- By default to @webroot/uploads.
- When a model_id is provided (via widget option 'model_id' or POST field 'model_id'), a subfolder @webroot/uploads/{model_id} is used.
- When 'path' is set, that absolute path is used (no extra subfolder), unless you add one yourself.
- cleanTarget: When model_id is used, the target folder is cleaned before saving by default (can be controlled via 'cleanTarget').

Chunked uploads (FilePond)
--------------------------
- Client: The JS helper enables chunk uploads by default when a URL is set. You can disable it with 'options' => ['chunkUploads' => false] in the widget or via data attributes.
- Server: UploadHandler::processUpload supports the FilePond protocol:
  - POST (init, no $_FILES, header Upload-Length present) -> response: { id: "..." }
  - HEAD (offset check) to ?patch=<id> -> header Upload-Offset
  - PATCH (chunk) to ?patch=<id> with Upload-Offset/Upload-Length/Upload-Name -> appends data; after the final chunk the file is moved to the target directory.
- The client helper sends the CSRF token automatically as X-CSRF-Token header (from `<meta name="csrf-token">`). If your CSRF validation blocks PATCH/HEAD, adjust the action accordingly or allow header-based validation. By default the header should suffice.

Responses
---------
- Standard upload (POST with $_FILES):
  - Success (single file): { code: 'success', message: '...', filename: '...' }
  - Success (multiple files): { code: 'success', message: '...', filenames: ['...','...'], results: [...] }
  - Error: { code: 'error', message: '...' }
- Chunked upload:
  - Init (POST without files): { id: '...' }
  - Final PATCH: 200 OK without body; the JS client side handles this internally.

Widget options (excerpt)
------------------------
- id: HTML id of the input (default: 'filepond')
- url: Upload URL (path to your controller action)
- paramName: Upload field name (default: 'file')
- multiple: bool, allow selecting multiple files (default: true)
- maxFiles: int|null, maximum number of files
- acceptedFiles: string|array, accepted types (e.g., 'image/*,application/pdf')
- model_id: mixed|null, sent as an extra field and used server-side as subfolder
- options: Array of additional FilePond options; e.g.:
  - chunkUploads: bool (default: true)
  - chunkSize: int (default: 1_048_576 bytes)
  - headers: array of additional headers
  - extraData: array of additional form fields

UploadHandler options (excerpt)
-------------------------------
- basePath: Base upload directory (default: @webroot/uploads)
- path: Target directory (overrides basePath)
- cleanTarget: Clean target folder before saving (default: true when modelId is set; else false)
- postName / paramName: Field name (default: 'file')
- model / modelClass + modelId: ActiveRecord instance or class + id for auto-loading
- modelIdParam: POST field name for the id (default: 'model_id')
- attribute: Model attribute to store the filename(s)
- assign: 'first' | 'json' | 'array' (for multiple uploads)
- saveModel: bool (default: true)
- validate: bool (default: false)
- createVariants: bool (when true, creates a_ and w_; default sizes a_=[null,200], w_=[800,null])
- a_: [width,height] for a_
- w_: [width,height] for w_
- tmpPath: Path for chunk temp storage (default: @runtime/filepond-chunks)

Notes
-----
- Assets (FilePond CSS/JS) are included via the FilePondAsset and FileUploadAsset asset bundles.
- The FileUpload widget registers assets automatically; for plain <input> fields the auto-init script takes care of it.
- Filenames are sanitized on the server (only A–Z, a–z, 0–9, -, _ and a lowercase extension).
- Image variants are created using GD (input formats: JPG/PNG/GIF; output: PNG keeps transparency, otherwise JPEG quality 90).

License
-------
MIT
