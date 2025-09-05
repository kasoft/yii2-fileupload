<?php

namespace kasoft\fileupload;

use yii\web\AssetBundle;

/**
 * Asset bundle for FilePond loaded from Composer (npm-asset/filepond).
 */
class FilePondAsset extends AssetBundle
{
    public $sourcePath = '@vendor/npm-asset/filepond/dist';
    public $css = [
        'filepond.min.css',
    ];
    public $js = [
        'filepond.min.js',
    ];
    public $depends = [
        'yii\web\YiiAsset',
    ];
}
