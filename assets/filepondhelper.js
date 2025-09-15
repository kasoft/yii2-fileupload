(function () {
    'use strict';

    // Utility functions
    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    function ensureFilePond(callback) {
        if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function') {
            return callback();
        }
        var attempts = 0;
        var interval = setInterval(function () {
            attempts++;
            if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function') {
                clearInterval(interval);
                callback();
            } else if (attempts > 50) {
                clearInterval(interval);
            }
        }, 100);
    }

    function parseAcceptedTypes(value) {
        if (!value) return undefined;
        if (Array.isArray(value)) return value;
        if (typeof value === 'string') {
            var types = value.split(',').map(function (s) {
                return s.trim();
            }).filter(Boolean);
            return types.length > 0 ? types : undefined;
        }
        return undefined;
    }

    function resolveUploadUrl(url, element) {
        // Return string URL if provided
        if (typeof url === 'string' && url.length > 0) {
            return url;
        }

        // Try to call function if provided
        if (typeof url === 'function') {
            try {
                var result = url();
                if (typeof result === 'string' && result.length > 0) {
                    return result;
                }
            } catch (e) {
                console.warn('[Kasoft FileUpload] URL function failed:', e);
            }
        }

        // Fallback to form action or current page
        var fallback = '';
        if (element && element.form && element.form.action) {
            fallback = element.form.action;
        } else {
            fallback = window.location.href;
        }

        return fallback;
    }

    // Main initialization function
    window.KasoftFileUploadInit = function (elementId, options) {

        ensureFilePond(function () {
            var element = typeof elementId === 'string' ? document.getElementById(elementId) : elementId;
            if (!element) {
                console.error('[Kasoft FileUpload] Element not found:', elementId);
                return;
            }

            options = options || {};
            var csrf = getCsrf();

            // Configure FilePond options
            var pondConfig = {
                allowMultiple: Boolean(options.multiple),
                //allowRemove: false,   // hides pre-upload remove
                allowRevert: false    // hides post-upload revert (the "X")
            };

            if (options.maxFiles != null) {
                pondConfig.maxFiles = parseInt(options.maxFiles, 10);
            }

            var acceptedTypes = parseAcceptedTypes(options.acceptedFiles);
            if (acceptedTypes) {
                pondConfig.acceptedFileTypes = acceptedTypes;
            }

            // Enable chunked uploads
            var enableChunking = options.chunkUploads !== false;
            pondConfig.chunkUploads = enableChunking;

            if (enableChunking) {
                pondConfig.chunkSize = options.chunkSize || 1024 * 1024; // 1 MB Default
                pondConfig.chunkRetryDelays = [500, 1000, 3000];
            }

            if(options.labelIdle !== undefined) {
                pondConfig.labelIdle = options.labelIdle;
            }

            // Resolve upload URL
            pondConfig.server = resolveUploadUrl(options.url, element);

            // console.log('[Kasoft FileUpload] FilePond config:', pondConfig);

            // Create FilePond instance
            try {
                element._kasoftPond = FilePond.create(element, pondConfig);

                // Set up event handlers
                element._kasoftPond.on('processfile', function (error, file) {
                    var eventName = error ? 'kasoft:fileupload:error' : 'kasoft:fileupload:success';
                    var event = new CustomEvent(eventName, {
                        detail: { file: file, error: error }
                    });
                    element.dispatchEvent(event);

                    if (!error) {
                        // Neues hidden input für jeden Upload
                        var hiddenInput = document.createElement("input");
                        hiddenInput.type = "hidden";
                        hiddenInput.name = "uploaded_file[]"; // [] für Mehrfach-Uploads
                        hiddenInput.value = file.serverId || file.filename;

                        // Hänge das hidden input direkt an den Root-Container
                        var root = document.getElementById(elementId);
                        root.appendChild(hiddenInput);
                    }
                });

                return element._kasoftPond;
            } catch (e) {
                console.error('[Kasoft FileUpload] Failed to create FilePond instance:', e);
            }

        });
    };


    // Parse upload response
    function parseUploadResponse(responseText) {
        try {
            var data = JSON.parse(responseText);

            // Return appropriate value based on response structure
            if (data.id) return data.id;
            if (data.filename) return data.filename;
            if (data.filenames && Array.isArray(data.filenames) && data.filenames.length > 0) {
                return data.filenames[0];
            }
            if (data.file) return data.file;

            // If it's an object but no recognized properties, return as string
            return JSON.stringify(data);
        } catch (e) {
            // If not valid JSON, return as-is
        }

        return responseText || 'upload_complete';
    }


})();
