jQuery(document).ready(function($) {
    console.log('Instagram Import JS loaded');
    
    const form = $('#instagram-upload-form');
    const fileInput = $('#instagram_export');
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
            alert('Please select a file to upload');
            return;
        }

        const chunkSize = parseInt(instagramImport.chunk_size);
        if (!chunkSize || chunkSize <= 0) {
            alert('Invalid chunk size configuration');
            return;
        }

        console.log('Starting upload with chunk size:', chunkSize, 'bytes');
        console.log('Total file size:', file.size, 'bytes');

        uploadProgress.show();
        uploadStatusText.text('Starting upload...');
        uploadProgressFill.css('width', '0%');
        uploadProgressText.text('0%');

        const chunks = Math.ceil(file.size / chunkSize);
        let currentChunk = 0;

        // Generate a single upload ID for all chunks
        const upload_id = Date.now().toString();
        console.log('Upload ID:', upload_id);

        function uploadChunk() {
            const start = currentChunk * chunkSize;
            // Ensure we don't exceed the chunk size limit
            const chunkToSend = Math.min(chunkSize, file.size - start);
            const end = start + chunkToSend;
            const chunk = file.slice(start, end);
            
            // Debug chunk size
            console.log('Configured chunk size:', chunkSize, 'bytes');
            console.log('Actual chunk size:', chunk.size, 'bytes');
            console.log('Chunk number:', currentChunk + 1, 'of', chunks);
            console.log('File position:', start, 'to', end, 'of', file.size);
            
            const formData = new FormData();
            formData.append('action', 'upload_chunk');
            formData.append('nonce', instagramImport.nonce);
            formData.append('chunk', chunk);
            formData.append('chunk_index', currentChunk);
            formData.append('total_chunks', chunks);
            formData.append('filename', file.name);
            formData.append('upload_id', upload_id); // Use the same upload_id for all chunks

            $.ajax({
                url: instagramImport.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (!response.success) {
                        alert('Upload failed: ' + response.data);
                        return;
                    }

                    const progress = Math.round((currentChunk + 1) / chunks * 100);
                    uploadProgressFill.css('width', progress + '%');
                    uploadProgressText.text(progress + '%');
                    uploadStatusText.text('Uploading chunk ' + (currentChunk + 1) + ' of ' + chunks);

                    if (response.data.complete) {
                        uploadStatusText.text('Upload complete, processing...');
                        processUpload(response.data.upload_id);
                    } else {
                        currentChunk++;
                        if (currentChunk < chunks) {
                            uploadChunk();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    alert('Upload failed: ' + error);
                }
            });
        }

        uploadChunk();
    });

    function processUpload(uploadId) {
        importProgress.show();
        importStatusText.text('Processing upload...');
        importProgressFill.css('width', '0%');
        importProgressText.text('0%');

        function checkStatus(status = 'start') {
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
                    if (!response.success) {
                        alert('Processing failed: ' + response.data);
                        return;
                    }

                    const data = response.data;
                    importProgressFill.css('width', data.progress + '%');
                    importProgressText.text(data.progress + '%');
                    importStatusText.text(data.message);

                    if (data.status === 'complete') {         
                        // Reset form
                        form[0].reset();
                        uploadProgress.hide();
                    } else {
                        checkStatus(data.status);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Processing failed: ' + error);
                }
            });
        }

        checkStatus();
    }
}); 