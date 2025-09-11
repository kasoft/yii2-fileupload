<?php

namespace kasoft\fileupload;

use Yii;

/**
 * Static upload helper class to be safely called from controllers.
 */
class UploadHandler
{
    /**
     * Process an upload based on a configuration array.
     */
    public static function processUpload(array $config)
    {
        $modelClass = $config['modelClass'] ?? null;

        // Target path
        $targetPath = $config['targetPath'] ?? \Yii::getAlias('@webroot/uploads');
        if (!is_dir($targetPath)) {
            @mkdir($targetPath, 0755, true);
            if (!is_dir($targetPath)) {
                \Yii::$app->response->statusCode = 500;
                \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
                \Yii::$app->response->content = 'Target directory is not writable.".';
                \Yii::$app->response->send();
                \Yii::$app->end();
            }
        }

        // Chunk temp storage
        $tmpPath = $config['tmpPath'] ?? \Yii::getAlias('@runtime/fileupload');
        if (!is_dir($tmpPath)) {
            @mkdir($tmpPath, 0755, true);
        }

        // Early handle FilePond chunk server protocol
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $patchId = $_GET['patch'] ?? ($config['chunkFileId'] ?? null);
        if ($patchId) {
            $patchId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$patchId);
        }

        $uploadLength = isset($_SERVER['HTTP_UPLOAD_LENGTH']) ? (int)$_SERVER['HTTP_UPLOAD_LENGTH'] : null;
        $uploadOffset = isset($_SERVER['HTTP_UPLOAD_OFFSET']) ? (int)$_SERVER['HTTP_UPLOAD_OFFSET'] : null;
        $uploadNameHeader = isset($_SERVER['HTTP_UPLOAD_NAME']) ? (string)$_SERVER['HTTP_UPLOAD_NAME'] : null;
        if ($uploadNameHeader) {
            // reverse potential encodeURIComponent from client
            $uploadNameHeader = urldecode($uploadNameHeader);
        }

        // Helpers for chunk files
        $tmpFileFor = function ($id) use ($tmpPath) {
            return rtrim($tmpPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.part';
        };

        // 1) Init: POST without files but with Upload-Length
        if ($method === 'POST' && $uploadLength) {
            $id = bin2hex(random_bytes(16));

            // IMPORTANT: Return plain text id per FilePond spec and terminate
            \Yii::$app->response->statusCode = 201; // Created
            \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            \Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            \Yii::$app->response->headers->set('Cache-Control', 'no-cache');
            \Yii::$app->response->content = $id;
            \Yii::$app->response->send();
            \Yii::$app->end();
        }

        // 2) HEAD offset for resume: ?patch=<id>
        if ($method === 'HEAD' && $patchId) {
            $tmpFile = $tmpFileFor($patchId);
            $offset = is_file($tmpFile) ? filesize($tmpFile) : 0;

            // Set headers for FilePond
            header('Upload-Offset: ' . $offset);
            header('Cache-Control: no-cache');

            // IMPORTANT: Don't return JSON, just end with proper HTTP status
            \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            \Yii::$app->response->statusCode = 200;
            \Yii::$app->response->send();
            \Yii::$app->end();
        }

        // 3) PATCH chunk append: ?patch=<id>
        if ($method === 'PATCH' && $patchId !== null) {
            $tmpFile = $tmpFileFor($patchId);
            $current = is_file($tmpFile) ? filesize($tmpFile) : 0;

            // Verify offset matches
            if ($uploadOffset !== null && $uploadOffset !== $current) {
                // Inform client about current offset so it can recover
                header('Upload-Offset: ' . $current);
                header('Cache-Control: no-store');
                \Yii::$app->response->statusCode = 409; // conflict
                \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
                \Yii::$app->response->content = 'Offset mismatch';
                \Yii::$app->response->send();
                \Yii::$app->end();
            }

            // Append raw body to tmp file
            $in = fopen('php://input', 'rb');
            $out = fopen($tmpFile, 'ab');
            if (!$in || !$out) {
                \Yii::$app->response->statusCode = 500;
                \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
                \Yii::$app->response->content = 'Cannot open streams';
                \Yii::$app->response->send();
                \Yii::$app->end();
            }

            while (!feof($in)) {
                $buf = fread($in, 1048576); //1 MB pro Durchgang
                if ($buf === false) break;
                fwrite($out, $buf);
            }
            fclose($in);
            fclose($out);

            // Get final size after writing
            clearstatcache();
            $finalSize = filesize($tmpFile);

            // If final chunk (size equals Upload-Length), finalize: move to target directory
            if ($uploadLength !== null && $finalSize >= $uploadLength) {

                $params = Yii::$app->request->get();
                if (self::processFile($targetPath, $uploadNameHeader, $tmpFile, $params, $modelClass)) {

                    // Set headers and respond OK
                    header('Upload-Offset: ' . $finalSize);
                    header('Cache-Control: no-store');
                    \Yii::$app->response->statusCode = 200;
                    \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
                    \Yii::$app->response->content = 'Upload complete';
                    \Yii::$app->response->send();
                    \Yii::$app->end();
                } else {
                    \Yii::$app->response->statusCode = 500;
                    \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
                    \Yii::$app->response->content = 'Failed to move file';
                    \Yii::$app->response->send();
                    \Yii::$app->end();
                }
            }

            // Not final yet - continue chunking
            header('Upload-Offset: ' . $finalSize);
            header('Cache-Control: no-store');
            \Yii::$app->response->statusCode = 204; // No Content
            \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            \Yii::$app->response->headers->set('Content-Length', '0');
            \Yii::$app->response->send();
            \Yii::$app->end();
        }

        if ($method === 'DELETE') {
            \Yii::$app->response->statusCode = 500;
            \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            \Yii::$app->response->content = 'Not implemented yet.';
            \Yii::$app->response->send();
            \Yii::$app->end();

        }

        // Normal Upload without Chunks
        $files = reset($_FILES);
        $params = Yii::$app->request->get();
        if (self::processFile($targetPath, $files['name'] ?? null, $files['tmp_name'] ?? null, $params, $modelClass)) {
            \Yii::$app->response->statusCode = 200;
            \Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            \Yii::$app->response->send();
            \Yii::$app->end();
        }

    }


    public static function processFile($targetPath, $filename, $tmp_name, $params, $modelClass = null)
    {

        // Derive target directory similar as below
        $safeName = self::sanitizeFilename($filename ?: ('upload_' . time()), true);

        if (!is_dir($targetPath)) {
            @mkdir($targetPath, 0755, true);
            if (!is_dir($targetPath)) {
                return ['code' => 'error', 'message' => 'Could not create folder to save file.'];
            }
        }

        if (!empty($params['moveToIdFolder']) && !empty($params['model_id'])) {
            $metaModelId = $params['model_id'];
            $finalDir = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $metaModelId;
            if (!is_dir($finalDir)) {
                @mkdir($finalDir, 0755, true);
            } else {
                if (!empty($params['emptyIdFolder'])) {
                    self::delete_folder($finalDir);
                    @mkdir($finalDir, 0755, true);
                    if (!is_dir($finalDir)) {
                        return ['code' => 'error', 'message' => 'Could not create folder to save file.'];
                    }
                }
            }
            $finalPath = rtrim($finalDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
        } else {
            $finalPath = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
        }

        if (@rename($tmp_name, $finalPath)) {
            if (!empty($params['model_attribute']) && !empty($params['model_id'])) {
                $model = @$modelClass::findOne($params['model_id']);
                if (!empty($model)) {
                    @$model->{$params['model_attribute']} = $filename;
                    @$model->save();
                }
            }
            return true;
        }
        return false;
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
