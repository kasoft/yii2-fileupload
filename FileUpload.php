<?php

namespace kasoft\fileupload;

use yii\base\Widget;
use kasoft\fileupload\FileUploadAsset;

class FileUpload extends Widget
{
    public $id = 'filepond';
    public $url; // upload URL
    public $paramName = 'file';
    public $multiple = true;
    public $maxFiles = null;
    public $acceptedFiles = null; // e.g. 'image/*' or 'image/*,application/pdf'
    public $model_id = null; // optional model id to send alongside upload
    public $options = []; // extra FilePond options

    public function init() {
        parent::init();
        $this->registerAssets();
    }

    public function run() {
        // Support multiple instances via options['instances'] array
        $instances = $this->options['instances'] ?? null;
        if (is_array($instances) && !empty($instances)) {
            $html = '';
            foreach ($instances as $cfg) {
                $id = $cfg['id'] ?? ('filepond_' . uniqid());
                $url = $cfg['url'] ?? $this->url;
                $url = $url !== null ? urldecode($url) : $url;
                $paramName = $cfg['paramName'] ?? $this->paramName;
                $multiple = $cfg['multiple'] ?? $this->multiple;
                $maxFiles = $cfg['maxFiles'] ?? $this->maxFiles;
                $acceptedFiles = $cfg['acceptedFiles'] ?? $this->acceptedFiles;
                $extra = $cfg['options'] ?? [];
                $multipleAttr = $multiple ? ' multiple' : '';
                $nameAttr = htmlspecialchars($paramName, ENT_QUOTES);
                $dataModel = '';
                $modelId = $cfg['model_id'] ?? $this->model_id;
                if ($modelId !== null && $modelId !== '') {
                    $dataModel = ' data-model-id="' . htmlspecialchars((string)$modelId, ENT_QUOTES) . '"';
                }
                $html .= "<input type=\"file\" class=\"filepond\" id=\"$id\" name=\"$nameAttr\"$multipleAttr$dataModel>";
                $jsOptions = [
                    'url' => $url,
                    'paramName' => $paramName,
                    'multiple' => (bool)$multiple,
                ];
                if ($modelId !== null && $modelId !== '') { $jsOptions['extraData']['model_id'] = (string)$modelId; }
                if ($maxFiles !== null) $jsOptions['maxFiles'] = $maxFiles;
                if ($acceptedFiles !== null) $jsOptions['acceptedFiles'] = $acceptedFiles;
                foreach ($extra as $k => $v) { $jsOptions[$k] = $v; }
                $optionsJson = json_encode($jsOptions);
                $this->getView()->registerJs("window.KasoftFileUploadInit && window.KasoftFileUploadInit('$id', $optionsJson);");
            }
            return $html;
        }
        // Single instance
        $id = $this->id;
        $multipleAttr = $this->multiple ? ' multiple' : '';
        $nameAttr = htmlspecialchars($this->paramName, ENT_QUOTES);
        $url = $this->url !== null ? urldecode($this->url) : $this->url;
        $dataModel = '';
        if ($this->model_id !== null && $this->model_id !== '') {
            $dataModel = ' data-model-id="' . htmlspecialchars((string)$this->model_id, ENT_QUOTES) . '"';
        }
        $html = "<input type=\"file\" class=\"filepond\" id=\"$id\" name=\"$nameAttr\"$multipleAttr$dataModel>";
        $jsOptions = [
            'url' => $url,
            'paramName' => $this->paramName,
            'multiple' => (bool)$this->multiple,
        ];
        if ($this->model_id !== null && $this->model_id !== '') { $jsOptions['extraData']['model_id'] = (string)$this->model_id; }
        if ($this->maxFiles !== null) $jsOptions['maxFiles'] = $this->maxFiles;
        if ($this->acceptedFiles !== null) $jsOptions['acceptedFiles'] = $this->acceptedFiles;
        foreach ($this->options as $k => $v) { if ($k !== 'instances') { $jsOptions[$k] = $v; } }
        $optionsJson = json_encode($jsOptions);
        $this->getView()->registerJs("window.KasoftFileUploadInit && window.KasoftFileUploadInit('$id', $optionsJson);");
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
