<?php

namespace kasoft\fileupload;

use yii\base\Widget;
use yii\helpers\Url;

class FileUpload extends Widget
{
    public $id = 'my-filepond';
    public $url; // upload URL
    public $model = [];
    public $multiple = true;
    public $maxFiles = 5;
    public $acceptedFiles = null; // e.g. 'image/*' or 'image/*,application/pdf'
    public $options = []; // extra FilePond options

    public function init() {
        parent::init();
        $this->registerAssets();
    }

    public function run() {

        $params = [$this->url];
        foreach($this->model as $key => $value){
            $params[$key] = $value;
        }
        $url = urldecode(\yii\helpers\Url::to($params));

        $html = "<input type=\"file\" class=\"filepond\" id=\"$this->id\">";
        $jsOptions = [
            'url' => $url,
            'multiple' => (bool)$this->multiple,
        ];
        if ($this->maxFiles !== null) $jsOptions['maxFiles'] = $this->maxFiles;
        if ($this->acceptedFiles !== null) $jsOptions['acceptedFiles'] = $this->acceptedFiles;
        foreach ($this->options as $k => $v) { if ($k !== 'instances') { $jsOptions[$k] = $v; } }
        $optionsJson = json_encode($jsOptions);
        $this->getView()->registerJs("window.KasoftFileUploadInit && window.KasoftFileUploadInit('$this->id', $optionsJson);");
        return $html;
    }

    /**
     * Registers the needed assets
     */
    public function registerAssets() {
        $view = $this->getView();
        FileUploadAsset::register($view);
    }

}
