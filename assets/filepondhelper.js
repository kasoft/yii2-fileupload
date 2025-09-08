(function(){
  function getCsrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : null;
  }
  function ensureFilePond(cb){
    if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function') return cb();
    var tries = 0;
    var intv = setInterval(function(){
      tries++;
      if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function') {
        clearInterval(intv);
        cb();
      } else if (tries > 50) {
        clearInterval(intv);
      }
    }, 100);
  }
  function toAcceptedTypes(val){
    if (!val) return undefined;
    if (Array.isArray(val)) return val;
    // split comma separated string
    if (typeof val === 'string') {
      var arr = val.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
      if (arr.length === 0 && val) return [val];
      return arr;
    }
    return undefined;
  }
  window.KasoftFileUploadInit = function(id, options){
    ensureFilePond(function(){
      var el = typeof id === 'string' ? document.getElementById(id) : id;
      if (!el) return;
      options = options || {};
      var csrf = getCsrf();
      // ensure input name reflects paramName
      if (options.paramName && el.name !== options.paramName) el.name = options.paramName;
      // destroy old pond
      if (el._kasoftPond && el._kasoftPond.destroy) {
        try { el._kasoftPond.destroy(); } catch(e) {}
      }
      var pondOpts = {
        allowMultiple: !!options.multiple,
      };
      if (options.maxFiles != null) pondOpts.maxFiles = options.maxFiles;
      var accepted = toAcceptedTypes(options.acceptedFiles);
      if (accepted) pondOpts.acceptedFileTypes = accepted;
      if (options.paramName) pondOpts.name = options.paramName;
      if (options.url) {
        var commonHeaders = (function(){
          var h = options.headers || {};
          if (csrf && !h['X-CSRF-Token']) h['X-CSRF-Token'] = csrf;
          return h;
        })();
        pondOpts.server = {
          process: {
            url: options.url,
            method: 'POST',
            name: options.paramName || 'file',
            headers: commonHeaders,
            ondata: function(formData){
              try {
                // append extraData passed via options
                if (options && options.extraData) {
                  for (var key in options.extraData) {
                    if (Object.prototype.hasOwnProperty.call(options.extraData, key)) {
                      formData.append(key, options.extraData[key]);
                    }
                  }
                }
                // append data-model-id attribute if present
                var modelId = el && el.getAttribute && el.getAttribute('data-model-id');
                if (modelId) {
                  formData.append('model_id', modelId);
                }
              } catch (e) {}
              return formData;
            },
            onload: function(responseText){
              // Try to parse JSON and return appropriate id/value
              try {
                var data = JSON.parse(responseText);
                // chunk init returns {id: "..."}
                if (data && data.id) return data.id;
                // non-chunk flow returns {filename: "..."} or {filenames:[]}
                if (data && data.filename) return data.filename;
                if (data && data.filenames && data.filenames.length) return data.filenames[0];
              } catch(e) {}
              return responseText;
            }
          }
        };
        // Enable chunking by default (can be overridden via options.chunkUploads=false)
        if (typeof options.chunkUploads === 'undefined') options.chunkUploads = true;
        pondOpts.chunkUploads = !!options.chunkUploads;
        if (pondOpts.chunkUploads) {
          pondOpts.chunkSize = options.chunkSize || 1048576; // 1MB default
          // FilePond uses options.server.patch for chunked uploading
          if (!pondOpts.server) pondOpts.server = {};
          pondOpts.server.patch = {
            url: '?patch=',
            method: 'PATCH',
            headers: commonHeaders
          };
        }
      }
      el._kasoftPond = FilePond.create(el, pondOpts);
      // bubble generic events
      el._kasoftPond.on('processfile', function(error, file){
        var detail = { file: file, error: error };
        var evName = error ? 'kasoft:fileupload:error' : 'kasoft:fileupload:success';
        var event = new CustomEvent(evName, { detail: detail });
        el.dispatchEvent(event);
      });
      return el._kasoftPond;
    });
  };

  function autoInit(){
    ensureFilePond(function(){
      var nodes = document.querySelectorAll('input.filepond');
      var csrf = getCsrf();
      Array.prototype.forEach.call(nodes, function(el){
        if (el._kasoftPond) return;
        var opts = {
          url: el.getAttribute('data-url') || el.getAttribute('data-action') || el.form && el.form.action || window.location.href,
          paramName: el.getAttribute('data-param') || el.getAttribute('name') || 'file',
          multiple: (el.getAttribute('data-multiple') || (el.hasAttribute('multiple') ? 'true':'false')) === 'true',
        };
        var acc = el.getAttribute('data-accepted');
        if (acc) opts.acceptedFiles = acc;
        var max = el.getAttribute('data-maxfiles');
        if (max) opts.maxFiles = parseInt(max, 10);
        var dataModelId = el.getAttribute('data-model-id');
        if (dataModelId) {
          opts.extraData = opts.extraData || {};
          opts.extraData.model_id = dataModelId;
        }
        opts.headers = opts.headers || {};
        if (csrf) opts.headers['X-CSRF-Token'] = csrf;
        window.KasoftFileUploadInit(el, opts);
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }
})();
