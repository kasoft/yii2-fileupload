File Upload und Handler Extension für Yii2
==========================================

FilePond Integration

Installation
------------

Install via Composer:

```
composer require kasoft/yii2-fileupload "~1.0"
```

Basics
------
This extension provides two parts:
- A Widget to render one or multiple FilePond upload inputs in your views.
- A handler class to process file uploads in your controller or model-bound workflow.

Usage: View (render one FilePond input)
---------------------------------------
```php
use kasoft\fileupload\FileUpload;

echo FileUpload::widget([
    'id' => 'my-filepond',
    'url' => \yii\helpers\Url::to(['/upload/handle']), // controller action URL
    'paramName' => 'file',
    'acceptedFiles' => 'image/*',
    'maxFiles' => 5,
    'multiple' => true,
]);
```
You can render multiple instances by calling the widget multiple times with different `id` values.

Usage: Controller (minimal, model-bound upload)
-----------------------------------------------

```php
use app\models\Treetest;use kasoft\fileupload\UploadHandler;use yii\web\Response;

public function actionHandle($id = null)
{
    \Yii::$app->response->format = Response::FORMAT_JSON;
    return UploadHandler::processUpload([
        'modelClass' => Treetest::class, // optional, set to bind to an AR
        'modelId' => $id,                // optional, handler also reads POST model_id
        'attribute' => 'file',           // AR attribute to store filename
        'postName' => 'file',            // input field name
        // basePath defaults to @webroot/uploads and subdir @webroot/uploads/{model_id} is created automatically
        'createVariants' => false,       // set true to generate a_ and w_ image variants
    ]);
}
```

Usage: Controller (custom basePath and variant sizes)
----------------------------------------------------

```php
use app\models\Treetest;use kasoft\fileupload\UploadHandler;use yii\web\Response;

public function actionHandleCustom($id = null)
{
    \Yii::$app->response->format = Response::FORMAT_JSON;
    return UploadHandler::processUpload([
        'modelClass' => Treetest::class,
        'modelId' => $id,
        'attribute' => 'file',
        'postName' => 'file',
        'basePath' => \Yii::getAlias('@webroot/custom-uploads'),
        // Configure a_ and w_ variants (4 values across both arrays):
        'createVariants' => true,
        'a_' => [null, 150],  // a_ prefix => height 150 keep aspect
        'w_' => [1200, null], // w_ prefix => width 1200 keep aspect
        // cleanTarget defaults to true when modelId is used; set explicitly if needed
        // 'cleanTarget' => true,
    ]);
}
```

Working example in this app
---------------------------
This repository ships with a minimal demo:
- Controller: `controllers/UploadController.php`
- View: `views/upload/index.php`

Open `/index.php?r=upload/index` (or pretty URL `/upload/index`) to see the demo. Ensure the directory `@webroot/uploads` exists and is writable by the web server user.

Notes
-----
- Assets (FilePond JS/CSS) are provided via Composer (npm-asset/filepond) and auto-registered by the widget.
- If you add a plain `<input type="file" class="filepond" data-url="...">` without using the widget, the JS auto-initializes it on DOM ready. You can control options via data-attributes: `data-url`, `data-param`, `data-multiple`, `data-accepted`, `data-maxfiles`.
- CSRF token is read automatically from `<meta name="csrf-token" ...>` and sent as `X-CSRF-Token` header.
- The helper returns a JSON-ready array with `code` and `message` and the `filename` on success.
- Chunked uploads: The widget now enables FilePond chunk uploads by default to bypass PHP post_max_size limits. The server handler accepts the FilePond chunk protocol using the same URL. It will initialize a transfer id, accept PATCH chunks at `?patch=<id>`, and assemble the file on completion. You can disable chunking with `'options' => ['chunkUploads' => false]` when rendering the widget. If you use Yii CSRF validation, either ensure the `X-CSRF-Token` header is sent (it is by default) or disable CSRF for the upload action because PATCH and HEAD requests don't carry form parameters.


Options (UploadHandler::processUpload)
-------------------------------------
- Always pass a configuration array.
- Keys:
  - basePath: Base directory for uploads (default: @webroot/uploads). Used when no explicit path is given.
  - path: Absolute target directory. Overrides basePath if set.
  - cleanTarget: If true, the target directory is emptied before saving the new file(s). Defaults to true when a modelId-based subfolder is used; otherwise false.
  - postName / paramName: Name of the file field (default: "file").
  - createVariants: If true or when variant arrays are provided, create image variants with prefixes a_ and w_.
  - a_: [width,height] for the a_ variant (e.g. [null,200] for height 200 maintain aspect).
  - w_: [width,height] for the w_ variant (e.g. [800,null] for width 800 maintain aspect).
  - model / modelClass + modelId: ActiveRecord instance or provide class and id to auto-load.
  - attribute: Model attribute to store the filename(s).
  - saveModel: Whether to save the model (default: true if model present, else true by default here).
  - validate: Whether to validate on save (default: false).
  - assign: "first" | "json" | "array" for multi-file assignment handling.
