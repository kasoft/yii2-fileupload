<?php

namespace kasoft\fileupload;

use yii\web\AssetBundle;

/**
 * @author Nils Menrad
 * @since 1.0
 */
class FileUploadAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/assets';
    public $css = [
        'filepondhelper.css',
    ];
    public $js = [
        'filepondhelper.js',
    ];
    public $depends = [
        'yii\web\YiiAsset',
        'kasoft\\fileupload\\FilePondAsset',
    ];
}
