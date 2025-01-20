jQuery(document).ready(function($) {
    console.log('Instagram Import JS loaded');
    
    const form = $('#instagram-upload-form');
    const fileInput = $('#instagram_zip');
    const uploadProgress = $('#upload-progress');
    const uploadProgressFill = uploadProgress.find('.progress-bar-fill');
    const uploadProgressText = uploadProgress.find('.progress-text');
    const uploadStatusText = uploadProgress.find('.upload-status');
    
    const importProgress = $('#import-progress');
    const importProgressFill = importProgress.find('.progress-bar-fill');
    const importProgressText = importProgress.find('.progress-text');
    const importStatusText = importProgress.find('.import-status');

    if (form.length === 0) {
        console.error('Form not found');
        return;
    }

    form.on('submit', function(e) {
        console.log('Form submit intercepted');
        e.preventDefault();
        
        const file = fileInput[0].files[0];
        if (!file) {
            alert('Please select a file');
            return;
        }

        // Reset and show progress bars
        uploadProgress.show();
        uploadProgressFill.css('width', '0%');
        uploadProgressText.text('0%');
        uploadStatusText.text('Preparing upload...');
        
        importProgress.hide();
        importProgressFill.css('width', '0%');
        importProgressText.text('0%');
        importStatusText.text('');

        // Generate unique upload ID
        const uploadId = 'upload_' + Date.now();
        
        // Use a smaller chunk size - 1MB
        const chunkSize = 1024 * 1024;
        const chunks = Math.ceil(file.size / chunkSize);
        let currentChunk = 0;
        let retryCount = 0;
        const maxRetries = 3;

        console.log('Starting upload of', file.name, '(', file.size, 'bytes) in', chunks, 'chunks of', chunkSize, 'bytes each');

        // Upload chunks
        function uploadChunk() {
            const start = currentChunk * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);

            console.log('Preparing chunk', currentChunk + 1, 'of', chunks, '(', chunk.size, 'bytes)');

            const formData = new FormData();
            formData.append('action', 'upload_chunk');
            formData.append('nonce', instagramImport.nonce);
            formData.append('chunk', chunk, 'chunk');
            formData.append('chunk_index', currentChunk);
            formData.append('total_chunks', chunks);
            formData.append('filename', file.name);
            formData.append('upload_id', uploadId);

            console.log('Sending chunk', currentChunk + 1);

            $.ajax({
                url: instagramImport.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    console.log('Server response for chunk', currentChunk + 1, ':', response);
                    
                    if (response.success) {
                        // Reset retry count on success
                        retryCount = 0;

                        // Update upload progress
                        const progress = Math.round(((currentChunk + 1) / chunks) * 100);
                        uploadProgressFill.css('width', progress + '%');
                        uploadProgressText.text(progress + '%');
                        uploadProgress.find('.progress-wrapper').attr('data-progress', progress);
                        uploadStatusText.text('Uploading file... ' + (currentChunk + 1) + ' of ' + chunks + ' chunks');

                        if (response.data.complete) {
                            uploadStatusText.text('Upload complete. Processing file...');
                            importProgress.show();
                            importStatusText.text('Extracting files...');
                            importProgressFill.css('width', '33%');
                            importProgressText.text('33%');
                            importProgress.find('.progress-wrapper').attr('data-progress', '33');
                            processUpload(uploadId);
                        } else if (currentChunk < chunks - 1) {
                            currentChunk++;
                            setTimeout(uploadChunk, 100); // Add small delay between chunks
                        }
                    } else {
                        console.error('Upload failed:', response.data);
                        handleError('Upload failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Upload error for chunk', currentChunk + 1, ':', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    handleError('Upload failed. ' + (xhr.responseText || error));
                }
            });
        }

        function handleError(message) {
            if (retryCount < maxRetries) {
                retryCount++;
                console.log('Retrying chunk', currentChunk + 1, 'attempt', retryCount, 'of', maxRetries);
                uploadStatusText.text('Retrying... Attempt ' + retryCount + ' of ' + maxRetries);
                setTimeout(uploadChunk, 1000 * retryCount); // Exponential backoff
            } else {
                alert(message + '\nMax retries exceeded. Please try again.');
                // Reset the form
                uploadProgress.hide();
                importProgress.hide();
                uploadProgressFill.css('width', '0%');
                uploadProgressText.text('0%');
                uploadStatusText.text('');
            }
        }

        function processUpload(uploadId, status = 'start') {
            console.log('Processing upload', uploadId, 'with status', status);
            $.ajax({
                url: instagramImport.ajaxurl,
                type: 'POST',
                data: {
                    action: 'process_completed_upload',
                    nonce: instagramImport.nonce,
                    upload_id: uploadId,
                    status: status
                },
                success: function(response) {
                    console.log('Process response:', response);
                    if (response.success) {
                        // Update progress bar
                        importProgressFill.css('width', response.data.progress + '%');
                        importProgressText.text(response.data.progress + '%');
                        importProgress.find('.progress-wrapper').attr('data-progress', response.data.progress);
                        importStatusText.text(response.data.message);

                        // Continue processing based on status
                        if (response.data.status === 'extracting') {
                            setTimeout(function() {
                                processUpload(uploadId, 'extracting');
                            }, 1000);
                        } else if (response.data.status === 'importing') {
                            setTimeout(function() {
                                processUpload(uploadId, 'importing');
                            }, 1000);
                        } else if (response.data.status === 'complete') {
                            // Update progress UI to 100%
                            importProgressFill.css('width', '100%');
                            importProgressText.text('100%');
                            importProgress.find('.progress-wrapper').attr('data-progress', '100');
                            importStatusText.text(response.data.message);
                            
                            // Just reset the file input but keep the progress visible
                            fileInput.val('');
                        }
                    } else {
                        console.error('Processing failed:', response.data);
                        importStatusText.text('Processing failed: ' + response.data);
                        alert('Processing failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Processing error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    importStatusText.text('Processing failed');
                    alert('Processing failed: ' + (xhr.responseText || error));
                }
            });
        }

        // Start upload
        uploadChunk();
    });
}); 