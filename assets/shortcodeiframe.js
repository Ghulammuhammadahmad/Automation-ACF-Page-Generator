/**
 * [aapg_iframe] shortcode – Edit with AI + ACF edit form.
 * Depends: aapg-tinymce (when ACF form with WYSIWYG is present).
 * Localized: aapgShortcodeIframe.tinymceConfig (optional).
 */
(function() {
    'use strict';

    var tinymceConfig = (typeof aapgShortcodeIframe !== 'undefined' && aapgShortcodeIframe.tinymceConfig)
        ? aapgShortcodeIframe.tinymceConfig
        : {
            selector: 'textarea.aapg-tinymce',
            menubar: false,
            statusbar: false,
            branding: false,
            promotion: false,
            plugins: 'anchor link lists',
            toolbar: 'undo redo | bold italic underline strikethrough | link | removeformat'
        };

    window.aapgReloadIframe = function() {
        var iframe = document.getElementById('aapg-iframe-content');
        if (!iframe || !iframe.src) return;
        try {
            var url = new URL(iframe.src, document.baseURI || window.location.href);
            url.searchParams.set('_t', Date.now().toString());
            iframe.src = url.toString();
        } catch (e) {}
        if (iframe) {
            iframe.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    function initTinyMCE(selector) {
        if (typeof tinymce === 'undefined') return;
        var config = typeof selector === 'string'
            ? Object.assign({}, tinymceConfig, { selector: selector })
            : tinymceConfig;
        tinymce.init(config);
    }

    function initRepeaters() {
        document.querySelectorAll('.aapg-repeater-add-row').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var repeater = btn.previousElementSibling;
                if (!repeater) return;
                var rows = repeater.querySelectorAll('.aapg-repeater-row');
                var newIndex = rows.length;
                var template = repeater.querySelector('.aapg-repeater-row');
                if (!template) return;
                var clone = template.cloneNode(true);
                clone.setAttribute('data-index', newIndex);
                clone.classList.remove('aapg-row-expanded');
                clone.classList.add('aapg-row-collapsed');
                var cloneHeader = clone.querySelector('.aapg-repeater-row-header');
                if (cloneHeader) cloneHeader.setAttribute('aria-expanded', 'false');
                var numEl = clone.querySelector('.aapg-repeater-row-number');
                if (numEl) numEl.textContent = 'Row ' + (newIndex + 1);
                var wrapper = clone.querySelector('.aapg-wysiwyg-wrapper');
                if (wrapper) {
                    var ta = wrapper.querySelector('textarea.aapg-tinymce');
                    if (ta) {
                        wrapper.innerHTML = '';
                        wrapper.appendChild(ta);
                    }
                }
                clone.querySelectorAll('input, textarea, select').forEach(function(input) {
                    var oldName = input.getAttribute('name');
                    if (oldName) {
                        var newName = oldName.replace(/\[\d+\]/, '[' + newIndex + ']');
                        input.setAttribute('name', newName);
                        if (input.id && input.id.indexOf('aapg_wysiwyg_') === 0) {
                            input.id = 'aapg_wysiwyg_' + newName.replace(/[^a-zA-Z0-9_-]/g, '_');
                        }
                        input.value = '';
                    }
                });
                repeater.appendChild(clone);
                var newTextarea = clone.querySelector('textarea.aapg-tinymce');
                if (newTextarea && newTextarea.id) initTinyMCE('#' + newTextarea.id);
            });
        });
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('aapg-repeater-remove-row')) {
                e.stopPropagation();
                var row = e.target.closest('.aapg-repeater-row');
                if (row && confirm('Remove this row?')) row.remove();
            }
        });
    }

    function initCollapsibles() {
        var formToggle = document.getElementById('aapg-acf-edit-form-toggle');
        var formInner = document.getElementById('aapg-acf-edit-form-inner');
        if (formToggle && formInner) {
            formToggle.addEventListener('click', function() {
                formInner.classList.toggle('aapg-form-collapsed');
                formToggle.setAttribute('aria-expanded', !formInner.classList.contains('aapg-form-collapsed'));
            });
            formToggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    formToggle.click();
                }
            });
        }
        var researchToggle = document.getElementById('aapg-research-editor-toggle');
        var researchInner = document.getElementById('aapg-research-editor-inner');
        if (researchToggle && researchInner) {
            researchToggle.addEventListener('click', function() {
                researchInner.classList.toggle('aapg-form-collapsed');
                researchToggle.setAttribute('aria-expanded', !researchInner.classList.contains('aapg-form-collapsed'));
            });
            researchToggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    researchToggle.click();
                }
            });
        }
        document.addEventListener('click', function(e) {
            var header = e.target.closest('.aapg-repeater-row-header');
            if (!header || e.target.classList.contains('aapg-repeater-remove-row')) return;
            var row = header.closest('.aapg-repeater-row');
            if (!row) return;
            row.classList.toggle('aapg-row-collapsed');
            row.classList.toggle('aapg-row-expanded');
            header.setAttribute('aria-expanded', row.classList.contains('aapg-row-expanded'));
        });
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var header = e.target.closest('.aapg-repeater-row-header');
            if (!header || e.target.classList.contains('aapg-repeater-remove-row')) return;
            var tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            e.preventDefault();
            header.click();
        });
    }

    function initAcfForm() {
        var form = document.getElementById('aapg-iframe-acf-form');
        if (!form) return;
        var btn = document.getElementById('aapg-iframe-acf-save-btn');
        var status = document.getElementById('aapg-iframe-acf-status');
        var ajaxurl = form.getAttribute('data-ajaxurl');
        var nonce = form.getAttribute('data-nonce');
        var postId = form.getAttribute('data-post-id');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (typeof tinymce !== 'undefined') tinymce.triggerSave();
            var fields = {};
            var inputs = form.querySelectorAll('[name^="acf_field_"]');
            for (var i = 0; i < inputs.length; i++) {
                var el = inputs[i];
                var fullName = el.getAttribute('name');
                if (fullName.match(/\[\d+\]/)) {
                    var baseName = fullName.split('[')[0];
                    if (!fields[baseName]) fields[baseName] = {};
                    var matches = fullName.match(/\[(\d+)\]\[([^\]]+)\]/);
                    if (matches) {
                        var rowIndex = matches[1], subFieldName = matches[2];
                        if (!fields[baseName][rowIndex]) fields[baseName][rowIndex] = {};
                        fields[baseName][rowIndex][subFieldName] = el.value;
                    }
                } else {
                    var name = fullName.replace(/\[\]$/, '');
                    if (fullName.indexOf('[]') !== -1) {
                        if (!fields[name]) fields[name] = [];
                        if (el.checked) fields[name].push(el.value);
                    } else {
                        fields[name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                    }
                }
            }
            for (var key in fields) {
                if (typeof fields[key] === 'object' && !Array.isArray(fields[key])) {
                    var arr = [];
                    for (var idx in fields[key]) arr[parseInt(idx, 10)] = fields[key][idx];
                    fields[key] = arr.filter(function(v) { return v !== undefined; });
                }
            }
            var data = new FormData();
            data.append('action', 'aapg_save_iframe_acf');
            data.append('nonce', nonce);
            data.append('post_id', postId);
            data.append('fields', JSON.stringify(fields));
            if (btn) btn.disabled = true;
            if (status) { status.textContent = ''; status.className = 'aapg-acf-edit-form-status'; }
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (status) {
                        status.className = 'aapg-acf-edit-form-status ' + (res.success ? 'aapg-success' : 'aapg-error');
                        status.textContent = (res.data && res.data.message) ? res.data.message : (res.success ? 'Saved.' : 'Save failed.');
                    }
                    if (res.success && typeof window.aapgReloadIframe === 'function') window.aapgReloadIframe();
                })
                .catch(function() {
                    if (status) { status.className = 'aapg-acf-edit-form-status aapg-error'; status.textContent = 'Request failed.'; }
                })
                .then(function() { if (btn) btn.disabled = false; });
        });
    }

    function initResearchContentEditor() {
        var form = document.getElementById('aapg-research-content-form');
        if (!form) return;
        var btn = document.getElementById('aapg-research-content-save-btn');
        var status = document.getElementById('aapg-research-content-status');
        var ajaxurl = form.getAttribute('data-ajaxurl');
        var nonce = form.getAttribute('data-nonce');
        var postId = form.getAttribute('data-post-id');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (typeof tinymce !== 'undefined') tinymce.triggerSave();
            var contentEl = document.getElementById('aapg_research_content_editor');
            var content = contentEl ? contentEl.value : '';
            var data = new FormData();
            data.append('action', 'aapg_iframe_save_research_content');
            data.append('nonce', nonce);
            data.append('post_id', postId);
            data.append('content', content);
            if (btn) btn.disabled = true;
            if (status) { status.textContent = ''; status.className = 'aapg-acf-edit-form-status'; }
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (status) {
                        status.className = 'aapg-acf-edit-form-status ' + (res.success ? 'aapg-success' : 'aapg-error');
                        status.textContent = (res.data && res.data.message) ? res.data.message : (res.success ? 'Saved.' : 'Save failed.');
                    }
                    if (res.success && typeof window.aapgReloadIframe === 'function') window.aapgReloadIframe();
                })
                .catch(function() {
                    if (status) { status.className = 'aapg-acf-edit-form-status aapg-error'; status.textContent = 'Request failed.'; }
                })
                .then(function() { if (btn) btn.disabled = false; });
        });
    }

    function initPublishUnpublish() {
        var bar = document.getElementById('aapg-iframe-publish-bar');
        if (!bar) return;
        var btn = bar.querySelector('.aapg-iframe-publish-btn');
        var statusValue = bar.querySelector('.aapg-iframe-status-value');
        var statusMsg = document.getElementById('aapg-iframe-publish-status-msg');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var postId = bar.getAttribute('data-post-id');
            var nonce = bar.getAttribute('data-nonce');
            var ajaxurl = bar.getAttribute('data-ajaxurl');
            var setStatus = btn.getAttribute('data-set-status');
            if (!postId || !nonce || !ajaxurl || !setStatus) return;
            btn.disabled = true;
            if (statusMsg) statusMsg.textContent = '';
            var data = new FormData();
            data.append('action', 'aapg_iframe_set_post_status');
            data.append('nonce', nonce);
            data.append('post_id', postId);
            data.append('status', setStatus);
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success && res.data) {
                        bar.setAttribute('data-status', res.data.status);
                        if (statusValue) statusValue.textContent = res.data.status_label;
                        btn.textContent = res.data.next_action;
                        btn.setAttribute('data-set-status', res.data.next_status);
                        if (typeof window.aapgReloadIframe === 'function') window.aapgReloadIframe();
                    }
                    if (statusMsg) {
                        statusMsg.textContent = (res.data && res.data.message) ? res.data.message : (res.success ? '' : (res.data && res.data.message ? res.data.message : 'Request failed.'));
                        statusMsg.className = 'aapg-iframe-publish-status-msg ' + (res.success ? 'aapg-success' : 'aapg-error');
                    }
                })
                .catch(function() {
                    if (statusMsg) {
                        statusMsg.textContent = 'Request failed.';
                        statusMsg.className = 'aapg-iframe-publish-status-msg aapg-error';
                    }
                })
                .then(function() { btn.disabled = false; });
        });
    }

    function initEditWithAi() {
        var form = document.getElementById('aapg-edit-with-ai-form');
        if (!form) return;
        var submitBtn = document.getElementById('aapg-edit-with-ai-submit');
        var statusEl = document.getElementById('aapg-edit-with-ai-status');
        var streamEl = document.getElementById('aapg-edit-with-ai-stream');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var pageId = form.getAttribute('data-page-id');
            var ajaxurl = form.getAttribute('data-ajaxurl');
            var nonce = form.getAttribute('data-nonce');
            var postType = form.getAttribute('data-post-type') || 'post';
            var editPromptEl = form.querySelector('[name="aapg_edit_prompt"]');
            var editPrompt = editPromptEl ? editPromptEl.value : '';
            var pageTypeRadios = form.querySelectorAll('input[name="aapg_edit_page_type"]');
            var pageType = 'stub';
            for (var i = 0; i < pageTypeRadios.length; i++) {
                if (pageTypeRadios[i].checked) { pageType = pageTypeRadios[i].value; break; }
            }
            if (!editPrompt || !pageId) return;
            if (submitBtn) submitBtn.disabled = true;
            if (statusEl) { statusEl.textContent = 'Processing…'; statusEl.className = 'aapg-edit-with-ai-status'; }
            if (streamEl) {
                streamEl.style.display = 'block';
                streamEl.textContent = '';
                streamEl.setAttribute('aria-busy', 'true');
            }
            var body = new FormData();
            body.append('action', 'aapg_iframe_edit_with_ai');
            body.append('nonce', nonce);
            body.append('page_id', pageId);
            body.append('edit_prompt', editPrompt);
            body.append('page_type', pageType);
            body.append('post_type', postType);

            function onStreamComplete() {
                if (statusEl && statusEl.textContent === 'Processing…') {
                    statusEl.textContent = 'Content updated.';
                    statusEl.className = 'aapg-edit-with-ai-status aapg-success';
                }
                if (streamEl && streamEl.parentNode) {
                    streamEl.parentNode.removeChild(streamEl);
                }
                if (typeof window.aapgReloadIframe === 'function') window.aapgReloadIframe();
            }

            function processSSELine(eventType, dataStr) {
                if (eventType === 'done') {
                    onStreamComplete();
                    return;
                }
                var raw = (dataStr && typeof dataStr === 'string') ? dataStr.trim() : '';
                if (raw === '' || raw === '[DONE]') return;
                try {
                    var j = JSON.parse(raw);
                    if (eventType === 'delta' && j.delta && streamEl) {
                        streamEl.textContent += j.delta;
                        streamEl.scrollTop = streamEl.scrollHeight;
                    }
                    if (eventType === 'result' && j) {
                        onStreamComplete();
                    }
                    if (eventType === 'error' && j.message && statusEl) {
                        statusEl.textContent = j.message;
                        statusEl.className = 'aapg-edit-with-ai-status aapg-error';
                        if (streamEl && streamEl.parentNode) {
                            streamEl.parentNode.removeChild(streamEl);
                        }
                    }
                } catch (err) {}
            }

            fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(res) {
                    if (!res.ok) throw new Error('Request failed');
                    if (!res.body) return res.text().then(function(text) {
                        var lines = text.split(/\r?\n/);
                        var currentEvent = '';
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (line.indexOf('event: ') === 0) currentEvent = line.slice(7).trim();
                            if (line.indexOf('data: ') === 0) {
                                processSSELine(currentEvent, line.slice(6));
                                currentEvent = '';
                            }
                        }
                        onStreamComplete();
                    });
                    var reader = res.body.getReader();
                    var decoder = new TextDecoder();
                    var buffer = '';
                    var currentEvent = '';
                    function processBuffer() {
                        var parts = buffer.split(/\n\n/);
                        buffer = parts.pop() || '';
                        for (var p = 0; p < parts.length; p++) {
                            var block = parts[p];
                            var blockLines = block.split(/\r?\n/);
                            var dataStr = '';
                            for (var k = 0; k < blockLines.length; k++) {
                                var ln = blockLines[k].trim();
                                if (ln.indexOf('event: ') === 0) currentEvent = ln.slice(7).trim();
                                if (ln.indexOf('data: ') === 0) dataStr = ln.slice(6).trim();
                            }
                            if (dataStr) {
                                processSSELine(currentEvent, dataStr);
                                currentEvent = '';
                            }
                        }
                    }
                    function readChunk(result) {
                        if (result.done) {
                            processBuffer();
                            if (buffer.length) {
                                var blockLines = buffer.split(/\r?\n/);
                                var dataStr = '';
                                for (var k = 0; k < blockLines.length; k++) {
                                    var ln = blockLines[k].trim();
                                    if (ln.indexOf('event: ') === 0) currentEvent = ln.slice(7).trim();
                                    if (ln.indexOf('data: ') === 0) dataStr = ln.slice(6).trim();
                                }
                                if (dataStr) processSSELine(currentEvent, dataStr);
                            }
                            onStreamComplete();
                            return;
                        }
                        buffer += decoder.decode(result.value, { stream: true });
                        processBuffer();
                        return reader.read().then(readChunk);
                    }
                    return reader.read().then(readChunk);
                })
                .then(function() {
                    if (streamEl && streamEl.parentNode && streamEl.getAttribute('aria-busy') === 'true') {
                        streamEl.setAttribute('aria-busy', 'false');
                    }
                })
                .catch(function() {
                    if (statusEl) {
                        statusEl.textContent = 'Request failed.';
                        statusEl.className = 'aapg-edit-with-ai-status aapg-error';
                    }
                    if (streamEl && streamEl.parentNode) streamEl.setAttribute('aria-busy', 'false');
                })
                .finally(function() { if (submitBtn) submitBtn.disabled = false; });
        });
    }

    function onReady() {
        initTinyMCE();
        initRepeaters();
        initCollapsibles();
        initPublishUnpublish();
        initAcfForm();
        initResearchContentEditor();
        initEditWithAi();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
