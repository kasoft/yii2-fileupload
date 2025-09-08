<?php

namespace kasoft\fileupload;

/**
 * Static upload helper class to be safely called from controllers.
 */
class UploadHandler
{
    /**
     * Process an upload based on a configuration array.
     * Required keys: none (uses sensible defaults); Recommended: path or basePath.
     * - basePath: string, defaults to @webroot/uploads if not set.
     * - path: string|null, target directory; overrides basePath if set.
     * - cleanTarget: bool, empty target dir before saving (defaults to true when modelId is used).
     * - postName/paramName: string, input name, default 'file'.
     * - model / modelClass + modelId: ActiveRecord instance or autoload class+id.
     * - attribute: string, model attribute to store filename(s).
     * - assign: 'first'|'json'|'array' for multi-file assignment.
     * - createVariants: bool, when true will create a_ and w_ variants (defaults a_=[null,200], w_=[800,null]).
     * - a_: [width,height] for the a_ variant (optional, overrides default when createVariants is true or when provided).
     * - w_: [width,height] for the w_ variant (optional, overrides default when createVariants is true or when provided).
     *
     * Additionally supports FilePond chunked uploads when the client enables chunkUploads.
     * This method will handle the following requests:
     * - POST (init): no $_FILES but header 'Upload-Length' present => returns {id: <transferId>}
     * - HEAD (offset): with ?patch=<id> => responds with header 'Upload-Offset'
     * - PATCH (chunk): with ?patch=<id> and headers 'Upload-Offset', 'Upload-Length', 'Upload-Name' => appends data to temp file; on completion moves to target and responds 200
     */
    public static function processUpload(array $config)
    {
        $postField = $config['postName'] ?? ($config['paramName'] ?? 'file');

        // Common paths
        $basePath = $config['basePath'] ?? \Yii::getAlias('@webroot/uploads');
        $targetPath = $config['path'] ?? null;

        // Chunk temp storage
        $tmpBase = $config['tmpPath'] ?? \Yii::getAlias('@runtime/filepond-chunks');
        if (!is_dir($tmpBase)) { @mkdir($tmpBase, 0755, true); }

        // Early handle FilePond chunk server protocol
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $patchId = isset($_GET['patch']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['patch']) : null;
        $uploadLength = isset($_SERVER['HTTP_UPLOAD_LENGTH']) ? (int)$_SERVER['HTTP_UPLOAD_LENGTH'] : null;
        $uploadOffset = isset($_SERVER['HTTP_UPLOAD_OFFSET']) ? (int)$_SERVER['HTTP_UPLOAD_OFFSET'] : null;
        $uploadNameHeader = isset($_SERVER['HTTP_UPLOAD_NAME']) ? (string)$_SERVER['HTTP_UPLOAD_NAME'] : null;

        // Helpers for chunk files
        $tmpFileFor = function($id){ return rtrim(\Yii::getAlias('@runtime/filepond-chunks'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.part'; };
        $metaFileFor = function($id){ return rtrim(\Yii::getAlias('@runtime/filepond-chunks'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.json'; };

        // 1) Init: POST without files but with Upload-Length
        if ($method === 'POST' && $uploadLength && (!isset($_FILES[$postField]) || empty($_FILES[$postField]['tmp_name']))) {
            $id = bin2hex(random_bytes(16));
            // capture model_id if provided
            $modelIdParam = $config['modelIdParam'] ?? 'model_id';
            $initModelId = isset($_POST[$modelIdParam]) ? (string)$_POST[$modelIdParam] : null;
            // write meta file
            @file_put_contents($metaFileFor($id), json_encode(['length'=>$uploadLength, 'created'=>time(), 'model_id'=>$initModelId]));
            // Respond JSON (controller may force JSON response)
            return ['id' => $id];
        }

        // 2) HEAD offset for resume: ?patch=<id>
        if ($method === 'HEAD' && $patchId) {
            $tmpFile = $tmpFileFor($patchId);
            $offset = is_file($tmpFile) ? filesize($tmpFile) : 0;
            header('Upload-Offset: ' . $offset);
            // IMPORTANT: end quickly, FilePond only needs header
            exit(0);
        }

        // 3) PATCH chunk append: ?patch=<id>
        if ($method === 'PATCH' && $patchId !== null) {
            $tmpFile = $tmpFileFor($patchId);
            $current = is_file($tmpFile) ? filesize($tmpFile) : 0;
            // Verify offset matches
            if ($uploadOffset !== null && $uploadOffset !== $current) {
                http_response_code(409); // conflict
                echo 'Offset mismatch';
                exit(0);
            }
            // Append raw body to tmp file
            $in = fopen('php://input', 'rb');
            $out = fopen($tmpFile, 'ab');
            if (!$in || !$out) {
                http_response_code(500);
                echo 'Cannot open streams';
                exit(0);
            }
            while (!feof($in)) {
                $buf = fread($in, 1048576);
                if ($buf === false) break;
                fwrite($out, $buf);
            }
            fclose($in); fclose($out);

            // If final chunk (size equals Upload-Length), finalize: move to target directory
            $finalSize = filesize($tmpFile);
            if ($uploadLength !== null && $finalSize >= $uploadLength) {
                // read meta for optional model_id
                $meta = @json_decode(@file_get_contents($metaFileFor($patchId)), true) ?: [];
                $metaModelId = isset($meta['model_id']) ? (string)$meta['model_id'] : null;
                // Derive target directory similar as below
                $safeName = self::sanitizeFilename($uploadNameHeader ?: ('upload_' . $patchId), true);
                // compute final directory using defaults and optional modelId subdir
                $finalDir = $targetPath === null ? rtrim($basePath, DIRECTORY_SEPARATOR) : $targetPath;
                if ($metaModelId !== null && $metaModelId !== '') {
                    $finalDir .= DIRECTORY_SEPARATOR . $metaModelId;
                }
                if (!is_dir($finalDir)) { @mkdir($finalDir, 0755, true); }
                $finalPath = rtrim($finalDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
                @rename($tmpFile, $finalPath);
                // remove meta file
                @unlink($metaFileFor($patchId));
                // respond OK
                http_response_code(200);
                echo 'OK';
                exit(0);
            }
            // Not final yet
            http_response_code(204);
            exit(0);
        }
        $attribute = $config['attribute'] ?? null;
        $assignMode = $config['assign'] ?? 'first';
        $createVariants = (bool)($config['createVariants'] ?? false);
        $variantA = $config['a_'] ?? null; // [w,h]
        $variantW = $config['w_'] ?? null; // [w,h]

        // Derive model and target path
        $model = $config['model'] ?? null;
        $modelClass = $config['modelClass'] ?? null;
        $modelIdParam = $config['modelIdParam'] ?? 'model_id';
        $modelId = $config['modelId'] ?? (isset($_POST[$modelIdParam]) ? $_POST[$modelIdParam] : null);
        if (!$model && $modelClass && $modelId !== null) {
            try { $model = $modelClass::findOne($modelId); } catch (\Throwable $e) { /* ignore */ }
        }
        $basePath = $config['basePath'] ?? \Yii::getAlias('@webroot/uploads');
        $targetPath = $config['path'] ?? null;
        if ($targetPath === null) {
            $targetPath = rtrim($basePath, DIRECTORY_SEPARATOR);
            if ($modelId !== null && $modelId !== '') {
                $targetPath .= DIRECTORY_SEPARATOR . $modelId;
            }
        }

        // Clean target dir when needed
        $shouldClean = array_key_exists('cleanTarget', $config) ? (bool)$config['cleanTarget'] : ($modelId !== null && $modelId !== '');
        if (is_dir($targetPath) && $shouldClean) {
            @self::delete_folder($targetPath);
        }
        if (!is_dir($targetPath)) {
            @mkdir($targetPath, 0755, true);
        }
        if (!is_dir($targetPath)) {
            return ['code' => 'error', 'message' => 'Ordner zum Speichern der Datei konnte nicht erstellt werden.'];
        }

        if (!isset($_FILES[$postField])) {
            return ['code' => 'error', 'message' => 'No file posted.'];
        }

        $names = $_FILES[$postField]['name'];
        $tmps = $_FILES[$postField]['tmp_name'];
        $isMultiple = is_array($names);
        $saved = [];

        $doVariants = $createVariants || $variantA !== null || $variantW !== null;
        if ($doVariants) {
            if ($variantA === null) $variantA = [null, 200];
            if ($variantW === null) $variantW = [800, null];
        }

        $processOne = function($name, $tmp) use ($targetPath, $doVariants, $variantA, $variantW, &$saved) {
            $original = self::sanitizeFilename($name, true);
            if (!$tmp || !is_uploaded_file($tmp)) {
                return ['code' => 'error', 'message' => 'Failed to upload.'];
            }
            $final = rtrim($targetPath, '/').'/'.$original;
            if (!@move_uploaded_file($tmp, $final)) {
                return ['code' => 'error', 'message' => 'Failed to move uploaded file.'];
            }
            if ($doVariants) {
                $pi = pathinfo($final);
                $base = ($pi['dirname'] ?? dirname($final)).'/';
                if (is_array($variantA) && (isset($variantA[0]) || isset($variantA[1]))) {
                    @self::convertImage($final, $base.'a_'.($pi['basename'] ?? basename($final)), $variantA[0], $variantA[1]);
                }
                if (is_array($variantW) && (isset($variantW[0]) || isset($variantW[1]))) {
                    @self::convertImage($final, $base.'w_'.($pi['basename'] ?? basename($final)), $variantW[0], $variantW[1]);
                }
            }
            $saved[] = $original;
            return ['code' => 'success', 'message' => 'File uploaded successfully.', 'filename' => $original];
        };

        if ($isMultiple) {
            $results = [];
            foreach ($names as $idx => $n) {
                if ($n === null || $n === '') continue;
                $results[] = $processOne($n, $tmps[$idx] ?? null);
            }
            $response = ['code' => 'success', 'message' => 'Files uploaded successfully.', 'filenames' => $saved, 'results' => $results];
        } else {
            $res = $processOne($names, $tmps);
            if ($res['code'] !== 'success') return $res;
            $response = $res;
        }

        // Optional model binding
        if ($model && $attribute) {
            $value = null;
            if (!empty($saved)) {
                if ($isMultiple) {
                    if ($assignMode === 'json') {
                        $value = json_encode($saved);
                    } elseif ($assignMode === 'array') {
                        $value = $saved;
                    } else {
                        $value = $saved[0];
                    }
                } else {
                    $value = $saved[0];
                }
            }
            try {
                $model->{$attribute} = $value;
                $saveModel = array_key_exists('saveModel', $config) ? (bool)$config['saveModel'] : true;
                $validate = array_key_exists('validate', $config) ? (bool)$config['validate'] : false;
                $ok = $model->save($validate);
                $response['modelSaved'] = (bool)$ok;
                if (!$ok && property_exists($model, 'errors')) {
                    $response['modelErrors'] = $model->errors;
                }
            } catch (\Throwable $e) {
                $response['modelSaved'] = false;
                $response['modelError'] = $e->getMessage();
            }
        }

        // Provide some context in response
        $response['path'] = $targetPath;
        if ($modelId !== null) $response['modelId'] = $modelId;
        return $response;
    }

    public static function convertImage($sourceFile, $targetFile, $targetWidth, $targetHeight)
    {
        list($sourceWidth, $sourceHeight, $sourceType) = getimagesize($sourceFile);
        switch ($sourceType) {
            case IMAGETYPE_GIF:
                $sourceGdImage = imagecreatefromgif($sourceFile);
                break;
            case IMAGETYPE_JPEG:
                $sourceGdImage = imagecreatefromjpeg($sourceFile);
                break;
            case IMAGETYPE_PNG:
                $sourceGdImage = imagecreatefrompng($sourceFile);
                break;
            default:
                $sourceGdImage = false;
        }
        if ($sourceGdImage === false) {
            return false;
        }
        $sourceAspectRatio = $sourceWidth / $sourceHeight;
        if ($targetWidth === null) {
            $targetWidth = (int)($targetHeight * $sourceAspectRatio);
        } elseif ($targetHeight === null) {
            $targetHeight = (int)($targetWidth / $sourceAspectRatio);
        }
        $targetGdImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($sourceType == IMAGETYPE_PNG) {
            imagealphablending($targetGdImage, false);
            imagesavealpha($targetGdImage, true);
            $transparent = imagecolorallocatealpha($targetGdImage, 0, 0, 0, 127);
            imagefilledrectangle($targetGdImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        imagecopyresampled($targetGdImage, $sourceGdImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        if ($sourceType == IMAGETYPE_PNG) {
            imagepng($targetGdImage, $targetFile);
        } else {
            imagejpeg($targetGdImage, $targetFile, 90);
        }
        imagedestroy($sourceGdImage);
        imagedestroy($targetGdImage);
        return true;
    }

    public static function sanitizeFilename($file, $withExtension = false)
    {
        if ($withExtension) {
            $path_parts = pathinfo($file);
            $ext = isset($path_parts['extension']) ? $path_parts['extension'] : '';
            $datei = $path_parts['filename'];
            $ext = mb_ereg_replace("[^A-Za-z0-9]", '', $ext);
        } else {
            $datei = $file;
        }
        $datei = mb_ereg_replace("[^A-Za-z0-9\-\_]", '', $datei);
        if ($withExtension) {
            return $datei . "." . strtolower($ext);
        } else {
            return $datei;
        }
    }

    public static function delete_folder($tmp_path)
    {
        $ds = DIRECTORY_SEPARATOR;
        if (!is_writeable($tmp_path) && is_dir($tmp_path)) {
            @chmod($tmp_path, 0777);
        }
        if (!is_dir($tmp_path)) return true;
        $handle = opendir($tmp_path);
        while ($tmp = readdir($handle)) {
            if ($tmp != '..' && $tmp != '.' && $tmp != '') {
                if (is_writeable($tmp_path . $ds . $tmp) && is_file($tmp_path . $ds . $tmp)) {
                    @unlink($tmp_path . $ds . $tmp);
                } elseif (!is_writeable($tmp_path . $ds . $tmp) && is_file($tmp_path . $ds . $tmp)) {
                    @chmod($tmp_path . $ds . $tmp, 0666);
                    @unlink($tmp_path . $ds . $tmp);
                }
            }
        }
        closedir($handle);
        @rmdir($tmp_path);
        return !is_dir($tmp_path);
    }
}
