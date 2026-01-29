jQuery(document).ready(function ($) {

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

});
