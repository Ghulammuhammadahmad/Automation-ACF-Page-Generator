jQuery(document).ready(function ($) {

    $('#aapg-save-hub-maker-settings-btn').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Saving...');

        var formData = {
            action: 'aapg_save_hub_maker_settings',
            nonce: aapgAjax.nonce,
            acf_field_group_key: $('#acf_field_group_key').val(),
            elementor_template_id: $('#elementor_template_id').val(),
            page_title: $('#page_title').val(),
            prompt_id: $('#prompt_id').val(),
        };

        $.post(aapgAjax.ajaxUrl, formData)
            .done(function (response) {
                if (response && response.success) {
                    alert(response.data && response.data.message ? response.data.message : 'Hub Maker settings saved.');
                } else {
                    var errorMessage = 'Failed to save settings';
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    }
                    alert(errorMessage);
                }
            })
            .fail(function () {
                alert('Request failed while saving settings');
            })
            .always(function () {
                $btn.prop('disabled', false).text(originalText);
            });

    });

    $('#aapg-save-stub-maker-settings-btn').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Saving...');

        var formData = {
            action: 'aapg_save_stub_maker_settings',
            nonce: aapgAjax.nonce,
            elementor_template_id: $('#stub_elementor_template_id').val(),
            acf_group_id: $('#stub_acf_group_id').val(),
            prompt_id: $('#stub_prompt_id').val(),
            prompt: $('#stub_library').val() + " " + $('#stub_prompt').val(),
            parent_page_id: $('#stub_parent_page_id').val(),
        };

        $.post(aapgAjax.ajaxUrl, formData)
            .done(function (response) {
                if (response && response.success) {
                    alert(response.data && response.data.message ? response.data.message : 'Stub Maker settings saved.');
                } else {
                    var errorMessage = 'Failed to save settings';
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    }
                    alert(errorMessage);
                }
            })
            .fail(function () {
                alert('Request failed while saving settings');
            })
            .always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
    });

    $('#aapg-generate-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#aapg-generate-btn');
        var $progress = $('#aapg-progress');
        var $progressText = $('#aapg-progress-text');
        var $result = $('#aapg-result');

        $btn.prop('disabled', true).text('Generating...');
        $progress.show();
        $result.hide();

        var steps = [
            'Generating',
            'Generating.',
            'Generating..',
            'Generating...',
            'Generating....',
            'Generating.....',
            'Generating......',
            'Generating.......',
            'Generating',
            'Generating.',
            'Generating..',
            'Generating...',
            'Generating....',
            'Generating.....',
            'Generating......',
            'Generating.......',
            'Generating',
            'Generating.',
            'Generating..',
            'Generating...',
            'Generating....',
            'Generating.....',
            'Generating......',
            'Generating.......'

        ];

        var idx = 0;
        $progressText.html('<p>' + steps[idx] + '</p>');

        var interval = setInterval(function () {
            idx++;
            if (idx < steps.length) {
                $progressText.html('<p>' + steps[idx] + '</p>');
            }
        }, 4000);

        var formData = {
            action: 'aapg_generate_page',
            nonce: aapgAjax.nonce,
            acf_field_group_key: $('#acf_field_group_key').val(),
            elementor_template_id: $('#elementor_template_id').val(),
            parent_page_id: $('#parent_page_id').val(),
            page_title: $('#page_title').val(),
            input_text: $('#input_text').val(),
            prompt_id: $('#prompt_id').val(),
        };

        $.post(aapgAjax.ajaxUrl, formData)
            .done(function (response) {
                clearInterval(interval);
                $btn.prop('disabled', false).text('Generate Page');

                if (response && response.success) {
                    $progressText.html('<p>✔ Completed!</p>');
                    
                    var resultHtml = '<div class="notice notice-success">' +
                        '<p><strong>Page Created Successfully!</strong></p>' +
                        '<p>Page Title: ' + (response.data.page_title || 'Unknown') + '</p>' +
                        '<p><a href="' + response.data.edit_url + '" target="_blank" class="button">Edit Page</a> ' +
                        '<a href="' + response.data.view_url + '" target="_blank" class="button">View Page</a></p>';
                    
                    // Add OpenAI response section if available
                    if (response.data.openai_response) {
                        resultHtml += '<hr><h4>' + aapgAjax.openaiResponseTitle + '</h4>' +
                            '<div class="aapg-response-log">' +
                            '<pre>' +
                            JSON.stringify(response.data.openai_response, null, 2) +
                            '</pre></div>';
                    }
                    
                    resultHtml += '</div>';
                    $result.html(resultHtml).show();
                    
                    // Add the new page to the generated pages list dynamically
                    var newPageRow = '<tr>' +
                        '<td><strong>' + (response.data.page_title || 'Unknown') + '</strong></td>' +
                        '<td><span class="aapg-status-badge status-draft">Draft</span></td>' +
                        '<td><span class="aapg-no-parent">—</span></td>' +
                        '<td>' +
                            '<a href="' + response.data.edit_url + '" target="_blank" class="button button-small">Edit</a> ' +
                            '<a href="' + response.data.view_url + '" target="_blank" class="button button-small">View</a> ' +
                            '<button type="button" class="button button-small button-primary aapg-publish-btn" data-page-id="' + response.data.page_id + '" data-page-title="' + (response.data.page_title || 'Unknown') + '">Publish</button>' +
                        '</td>' +
                        '</tr>';
                    
                    // Add to the top of the generated pages table
                    var $tableBody = $('.aapg-generated-list tbody');
                    if ($tableBody.length) {
                        $tableBody.prepend(newPageRow);
                    } else {
                        // If table doesn't exist, create it
                        var $table = $('<div class="aapg-section aapg-generated-list">' +
                            '<h3>Generated Pages</h3>' +
                            '<table class="widefat striped">' +
                            '<thead><tr><th>Title</th><th>Status</th><th>Parent</th><th>Actions</th></tr></thead>' +
                            '<tbody>' + newPageRow + '</tbody>' +
                            '</table></div>');
                        $('.aapg-generator-wrap').append($table);
                    }
                    
                } else {
                    // Handle error response
                    var errorMessage = 'Unknown error occurred';
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    } else if (response && response.message) {
                        errorMessage = response.message;
                    } else if (typeof response === 'string') {
                        errorMessage = response;
                    }

                    $progress.hide();
                    $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + errorMessage + '</p></div>').show();
                }
            })
            .fail(function (xhr, status, error) {
                clearInterval(interval);
                $btn.prop('disabled', false).text('Generate Page');
                $progress.hide();

                var errorMessage = 'Request failed';
                if (xhr.responseText) {
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data && errorResponse.data.message) {
                            errorMessage = errorResponse.data.message;
                        }
                    } catch (e) {
                        errorMessage = xhr.responseText;
                    }
                } else if (error) {
                    errorMessage = error;
                }

                $result.html('<div class="notice notice-error"><p><strong>Request Failed:</strong> ' + errorMessage + '</p></div>').show();
            });
    });

    // Handle publish button clicks
    $(document).on('click', '.aapg-publish-btn', function (e) {
        e.preventDefault();
        
        var $btn = $(this);
        var pageId = $btn.data('page-id');
        var pageTitle = $btn.data('page-title');
        
        if (!pageId) {
            alert('Invalid page ID');
            return;
        }
        
        // Confirm before publishing
        if (!confirm('Are you sure you want to publish "' + pageTitle + '"?')) {
            return;
        }
        
        var $originalBtn = $btn;
        var originalText = $btn.text();
        
        $btn.prop('disabled', true).text('Publishing...');
        
        var formData = {
            action: 'aapg_publish_page',
            nonce: aapgAjax.nonce,
            page_id: pageId
        };
        
        $.post(aapgAjax.ajaxUrl, formData)
            .done(function (response) {
                if (response && response.success) {
                    // Show success message
                    var successHtml = '<div class="notice notice-success is-dismissible">' +
                        '<p><strong>Success!</strong> ' + (response.data.message || 'Page published successfully!') + '</p>' +
                        '<p><a href="' + response.data.view_url + '" target="_blank" class="button">View Page</a> ' +
                        '<a href="' + response.data.edit_url + '" target="_blank" class="button">Edit Page</a></p>' +
                        '</div>';
                    
                    // Insert notice after the generator form
                    $('.aapg-generator-wrap').prepend(successHtml);
                    
                    // Remove the publish button and update status
                    $originalBtn.closest('tr').find('.aapg-status-badge')
                        .removeClass('status-draft')
                        .addClass('status-publish')
                        .text('Publish');
                    $originalBtn.remove();
                    
                    // Auto-dismiss the notice after 5 seconds
                    setTimeout(function() {
                        $('.notice-success.is-dismissible').fadeOut(function() {
                            $(this).remove();
                        });
                    }, 5000);
                    
                } else {
                    // Handle error response
                    var errorMessage = 'Unknown error occurred';
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    } else if (response && response.message) {
                        errorMessage = response.message;
                    }
                    
                    alert('Error: ' + errorMessage);
                    $btn.prop('disabled', false).text(originalText);
                }
            })
            .fail(function (xhr, status, error) {
                var errorMessage = 'Request failed';
                if (xhr.responseText) {
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data && errorResponse.data.message) {
                            errorMessage = errorResponse.data.message;
                        }
                    } catch (e) {
                        errorMessage = xhr.responseText;
                    }
                } else if (error) {
                    errorMessage = error;
                }
                
                alert('Request Failed: ' + errorMessage);
                $btn.prop('disabled', false).text(originalText);
            });
    });

    // Handle stream test button
    $('#aapg-test-stream-btn').on('click', function () {
        var $btn = $(this);
        var $stopBtn = $('#aapg-stop-stream-btn');
        var $output = $('#aapg-stream-output');
        var $content = $('#aapg-stream-content');
        var $prompt = $('#test_prompt');

        var testPrompt = $prompt.val().trim();
        if (!testPrompt) {
            alert('Please enter a test prompt.');
            return;
        }

        $btn.prop('disabled', true).text('Testing...');
        $stopBtn.show();
        $output.show();
        $content.empty().append('<div style="color: #666;">Starting stream...</div>');

        // Create EventSource for SSE
        var eventSource = new EventSource(aapgAjax.ajaxUrl + '?action=aapg_test_stream&nonce=' + aapgAjax.nonce + '&test_prompt=' + encodeURIComponent(testPrompt));

        eventSource.onmessage = function (event) {
            try {
                var data = JSON.parse(event.data);
                
                if (data.error) {
                    $content.append('<div style="color: red;">Error: ' + data.error + '</div>');
                } else if (data.content) {
                    $content.append('<div>' + data.content + '</div>');
                } else if (data.reasoning) {
                    $content.append('<div style="color: #666; font-style: italic;">Reasoning: ' + data.reasoning + '</div>');
                } else if (data.done) {
                    $content.append('<div style="color: green; font-weight: bold;">Stream completed' + (data.reason ? ' (' + data.reason + ')' : '') + '</div>');
                    eventSource.close();
                    $btn.prop('disabled', false).text('Test Stream');
                    $stopBtn.hide();
                }
                
                // Auto-scroll to bottom
                $content.scrollTop($content[0].scrollHeight);
                
            } catch (e) {
                $content.append('<div style="color: red;">Parse error: ' + event.data + '</div>');
            }
        };

        eventSource.onerror = function (event) {
            $content.append('<div style="color: red;">Stream error occurred</div>');
            eventSource.close();
            $btn.prop('disabled', false).text('Test Stream');
            $stopBtn.hide();
        };

        // Store eventSource reference for stopping
        $btn.data('eventSource', eventSource);
    });

    // Handle stop stream button
    $('#aapg-stop-stream-btn').on('click', function () {
        var $testBtn = $('#aapg-test-stream-btn');
        var $stopBtn = $(this);
        var eventSource = $testBtn.data('eventSource');

        if (eventSource) {
            eventSource.close();
            $testBtn.removeData('eventSource');
        }

        $('#aapg-stream-content').append('<div style="color: orange; font-weight: bold;">Stream stopped by user</div>');
        $testBtn.prop('disabled', false).text('Test Stream');
        $stopBtn.hide();
    });

    // Handle image generation test button
    $('#aapg-test-image-btn').on('click', function () {
        var $btn = $(this);
        var $output = $('#aapg-image-output');
        var $loading = $('#aapg-image-loading');
        var $result = $('#aapg-image-result');
        var $image = $('#aapg-generated-image');
        var $url = $('#aapg-image-url');

        var positivePrompt = $('#positive_prompt').val().trim();
        var negativePrompt = $('#negative_prompt').val().trim();
        var resolution = $('#resolution').val();
        var customWidth = $('#custom_width').val();
        var customHeight = $('#custom_height').val();

        if (!positivePrompt) {
            alert('Please enter a positive prompt.');
            return;
        }

        $btn.prop('disabled', true).text('Generating...');
        $output.show();
        $loading.show();
        $result.hide();

        $.ajax({
            url: aapgAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aapg_test_image',
                nonce: aapgAjax.nonce,
                positive_prompt: positivePrompt,
                negative_prompt: negativePrompt,
                resolution: resolution,
                custom_width: customWidth,
                custom_height: customHeight
            },
            success: function (response) {
                $loading.hide();
                
                if (response.success) {
                    $image.attr('src', response.data.image_url);
                    $url.attr('href', response.data.image_url).text(response.data.image_url);
                    $result.show();
                } else {
                    $result.html('<div style="color: red;">Error: ' + response.data.message + '</div>').show();
                }
            },
            error: function (xhr, status, error) {
                $loading.hide();
                $result.html('<div style="color: red;">AJAX Error: ' + error + '</div>').show();
            },
            complete: function () {
                $btn.prop('disabled', false).text('Generate Image');
            }
        });
    });

    // Handle stub node test button
    $('#aapg-test-stub-node-btn').on('click', function () {
        var $btn = $(this);
        var $output = $('#aapg-stub-output');
        var $loading = $('#aapg-stub-loading');
        var $result = $('#aapg-stub-result');

        var $contentBox = $('#aapg-stub-content');

        var elementorTemplateId = $('#stub_elementor_template_id').val().trim();
        var library = $('#stub_library').val().trim();
        var acfGroupId = $('#stub_acf_group_id').val().trim();
        var promptId = $('#stub_prompt_id').val().trim();
        var prompt = $('#stub_prompt').val().trim();
        var parentPageId = $('#stub_parent_page_id').val().trim();

        if (!elementorTemplateId || !acfGroupId || !promptId || !prompt) {
            alert('Please fill in Elementor Template ID, ACF Group ID, Prompt ID, and Prompt Content.');
            return;
        }

        $btn.prop('disabled', true).text('Generating...');
        $output.show();
        $loading.show();
        $result.hide();

        // Live stream UI
        var liveBoxId = 'aapg-stub-live-stream';
        var $liveBox = $('#' + liveBoxId);
        if (!$liveBox.length) {
            $liveBox = $('<div id="' + liveBoxId + '" style="margin-top:10px; border:1px solid #ddd; background:#fff; padding:10px; max-height:220px; overflow:auto; font-family:monospace; white-space:pre-wrap;"></div>');
            $contentBox.append($liveBox);
        }
        $liveBox.text('');

        // Build prompt as you currently want: library + prompt
        var combinedPrompt = (library ? (library + ' ') : '') + prompt;

        fetch(aapgAjax.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: $.param({
                action: 'aapg_stub_node_generate_stream',
                nonce: aapgAjax.nonce,
                elementor_template_id: elementorTemplateId,
                acf_group_id: acfGroupId,
                prompt_id: promptId,
                prompt: combinedPrompt,
                parent_page_id: parentPageId
            })
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }
            var reader = resp.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var buffer = '';
            var currentEvent = null;
            var currentData = '';

            function handleSseBlock(block) {
                currentEvent = null;
                currentData = '';
                block.split('\n').forEach(function (line) {
                    line = line.trim();
                    if (line.indexOf('event:') === 0) {
                        currentEvent = line.substring(6).trim();
                    } else if (line.indexOf('data:') === 0) {
                        currentData += line.substring(5).trim();
                    }
                });

                if (!currentEvent || !currentData) {
                    return;
                }

                var parsed;
                try {
                    parsed = JSON.parse(currentData);
                } catch (e) {
                    return;
                }

                if (currentEvent === 'delta' && parsed.delta) {
                    $liveBox.append(parsed.delta);
                    $liveBox.scrollTop($liveBox[0].scrollHeight);
                }

                if (currentEvent === 'error') {
                    $loading.hide();
                    $result.html('<div style="color: red;">Error: ' + (parsed.message || 'Stream error') + '</div>').show();
                }

                if (currentEvent === 'result') {
                    $loading.hide();

                    var data = parsed;
                    var html = '<div style="margin-bottom: 15px;">';
                    html += '<p><strong>✅ Page Generated Successfully!</strong></p>';
                    html += '<p><strong>Page ID:</strong> ' + data.page_id + '</p>';
                    html += '<p><strong>Original Title:</strong> ' + data.original_title + '</p>';
                    html += '<p><strong>AI Generated Title:</strong> ' + data.page_title + '</p>';
                    html += '<p><strong>Page URL:</strong> <a href="' + data.page_url + '" target="_blank">' + data.page_url + '</a></p>';
                    html += '<p><strong>Parent Page ID:</strong> ' + (data.parent_page_id || 'None') + '</p>';
                    html += '</div>';

                    $result.html(html).show();
                }
            }

            function pump() {
                return reader.read().then(function (res) {
                    if (res.done) {
                        return;
                    }
                    buffer += decoder.decode(res.value, { stream: true });
                    var idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        var block = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        handleSseBlock(block);
                    }
                    return pump();
                });
            }

            return pump();
        }).catch(function (err) {
            $loading.hide();
            $result.html('<div style="color: red;">Stream Request Failed: ' + err.message + '</div>').show();
        }).finally(function () {
            $btn.prop('disabled', false).text('Generate Page with Stub Node');
        });
    });

    $('#aapg-generate-research-btn').on('click', function () {
        var $btn = $(this);
        var $output = $('#aapg-research-output');
        var $loading = $('#aapg-research-loading');
        var $result = $('#aapg-research-result');

        var $contentBox = $('#aapg-research-content');

        var postType = $('#research_post_type').val();
        var promptId = $('#research_prompt_id').val().trim();
        var prompt = $('#research_prompt').val().trim();

        if (!postType || !promptId || !prompt) {
            alert('Please fill in Target Post Type, Prompt ID and Prompt Content.');
            return;
        }

        $btn.prop('disabled', true).text('Generating...');
        $output.show();
        $loading.show();
        $result.hide();

        var liveBoxId = 'aapg-research-live-stream';
        var $liveBox = $('#' + liveBoxId);
        if (!$liveBox.length) {
            $liveBox = $('<div id="' + liveBoxId + '" style="margin-top:10px; border:1px solid #ddd; background:#fff; padding:10px; max-height:220px; overflow:auto; font-family:monospace; white-space:pre-wrap;"></div>');
            $contentBox.append($liveBox);
        }
        $liveBox.text('');

        fetch(aapgAjax.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: $.param({
                action: 'aapg_research_generate_stream',
                nonce: aapgAjax.nonce,
                post_type: postType,
                prompt_id: promptId,
                prompt: prompt
            })
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }

            var reader = resp.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var buffer = '';

            function handleSseBlock(block) {
                var currentEvent = null;
                var currentData = '';

                block.split('\n').forEach(function (line) {
                    line = line.trim();
                    if (line.indexOf('event:') === 0) {
                        currentEvent = line.substring(6).trim();
                    } else if (line.indexOf('data:') === 0) {
                        currentData += line.substring(5).trim();
                    }
                });

                if (!currentEvent || !currentData) {
                    return;
                }

                var parsed;
                try {
                    parsed = JSON.parse(currentData);
                } catch (e) {
                    return;
                }

                if (currentEvent === 'delta' && parsed.delta) {
                    $liveBox.append(parsed.delta);
                    $liveBox.scrollTop($liveBox[0].scrollHeight);
                }

                if (currentEvent === 'error') {
                    $loading.hide();
                    $result.html('<div style="color: red;">Error: ' + (parsed.message || 'Stream error') + '</div>').show();
                }

                if (currentEvent === 'result') {
                    $loading.hide();

                    var data = parsed;
                    var html = '<div style="margin-bottom: 15px;">';
                    html += '<p><strong>✅ Research Created Successfully!</strong></p>';
                    html += '<p><strong>Post ID:</strong> ' + data.post_id + '</p>';
                    html += '<p><strong>Title:</strong> ' + data.post_title + '</p>';
                    if (data.post_url) {
                        html += '<p><strong>Post URL:</strong> <a href="' + data.post_url + '" target="_blank">' + data.post_url + '</a></p>';
                    }
                    if (data.meta_title) {
                        html += '<p><strong>Meta Title:</strong> ' + data.meta_title + '</p>';
                    }
                    if (data.meta_description) {
                        html += '<p><strong>Meta Description:</strong> ' + data.meta_description + '</p>';
                    }
                    if (data.URL_RESOLUTION_TABLE && data.URL_RESOLUTION_TABLE.length) {
                        html += '<p><strong>URL Resolution Table:</strong> ' + data.URL_RESOLUTION_TABLE.length + ' rows</p>';
                    }
                    html += '</div>';

                    $result.html(html).show();
                }
            }

            function pump() {
                return reader.read().then(function (res) {
                    if (res.done) {
                        return;
                    }
                    buffer += decoder.decode(res.value, { stream: true });
                    var idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        var block = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        handleSseBlock(block);
                    }
                    return pump();
                });
            }

            return pump();
        }).catch(function (err) {
            $loading.hide();
            $result.html('<div style="color: red;">Stream Request Failed: ' + err.message + '</div>').show();
        }).finally(function () {
            $btn.prop('disabled', false).text('Generate Research (Streaming)');
        });
    });

});
