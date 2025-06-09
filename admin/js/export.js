/**
 * WP Easy Migrate - Export JavaScript
 * 
 * Handles step-based export process with AJAX polling
 */

(function($) {
    'use strict';
    
    /**
     * Export Manager Class
     */
    class ExportManager {
        constructor() {
            this.isRunning = false;
            this.pollInterval = null;
            this.pollDelay = 2000; // 2 seconds between polls
            this.maxRetries = 3;
            this.currentRetries = 0;
            
            this.initElements();
            this.bindEvents();
        }
        
        /**
         * Initialize DOM elements
         */
        initElements() {
            this.$form = $('#wp-easy-migrate-export-form');
            this.$button = $('#start-export');
            this.$progress = $('#export-progress');
            this.$progressBar = $('.wp-easy-migrate-progress-fill');
            this.$status = $('#export-status');
            this.$result = $('#export-result');
            this.$progressText = $('#export-progress-text');
        }
        
        /**
         * Bind event handlers
         */
        bindEvents() {
            this.$form.on('submit', (e) => {
                e.preventDefault();
                this.startExport();
            });
            
            // Handle page unload during export
            $(window).on('beforeunload', () => {
                if (this.isRunning) {
                    return wpEasyMigrate.strings.exportInProgress || 'Export is in progress. Are you sure you want to leave?';
                }
            });
        }
        
        /**
         * Start export process
         */
        startExport() {
            if (this.isRunning) {
                return;
            }
            
            this.isRunning = true;
            this.currentRetries = 0;
            
            // Update UI
            this.updateUI('starting');
            
            // Start the export with initial request
            this.makeExportRequest(true);
        }
        
        /**
         * Make AJAX request for export step
         * 
         * @param {boolean} startExport Whether this is the initial export request
         */
        makeExportRequest(startExport = false) {
            const data = {
                action: 'wpem_export_step',
                nonce: wpEasyMigrate.nonce
            };
            
            // Add form data for initial request
            if (startExport) {
                data.start_export = true;
                data.include_uploads = this.$form.find('[name="include_uploads"]').is(':checked') ? 1 : 0;
                data.include_plugins = this.$form.find('[name="include_plugins"]').is(':checked') ? 1 : 0;
                data.include_themes = this.$form.find('[name="include_themes"]').is(':checked') ? 1 : 0;
                data.include_database = this.$form.find('[name="include_database"]').is(':checked') ? 1 : 0;
                data.split_size = this.$form.find('[name="split_size"]').val() || 100;
            }
            
            $.ajax({
                url: wpEasyMigrate.ajaxUrl,
                type: 'POST',
                data: data,
                timeout: 60000, // 60 second timeout
                success: (response) => {
                    this.currentRetries = 0; // Reset retry counter on success
                    this.handleResponse(response);
                },
                error: (xhr, status, error) => {
                    this.handleError(xhr, status, error);
                }
            });
        }
        
        /**
         * Handle AJAX response
         * 
         * @param {Object} response AJAX response
         */
        handleResponse(response) {
            if (response.success) {
                const status = response.data.status;
                
                // Update progress
                this.updateProgress(status);
                
                if (status.completed) {
                    if (status.error) {
                        this.handleError(null, 'export_error', status.error);
                    } else {
                        this.handleSuccess(response.data.message, status);
                    }
                } else {
                    // Continue polling for next step
                    this.scheduleNextPoll();
                }
            } else {
                this.handleError(null, 'response_error', response.data.message);
            }
        }
        
        /**
         * Handle AJAX error
         * 
         * @param {Object} xhr XMLHttpRequest object
         * @param {string} status Error status
         * @param {string} error Error message
         */
        handleError(xhr, status, error) {
            this.currentRetries++;
            
            if (this.currentRetries < this.maxRetries && status !== 'export_error') {
                // Retry after delay
                console.log(`Export request failed, retrying (${this.currentRetries}/${this.maxRetries})...`);
                setTimeout(() => {
                    this.makeExportRequest();
                }, this.pollDelay * this.currentRetries); // Exponential backoff
                return;
            }
            
            // Max retries reached or permanent error
            this.isRunning = false;
            this.clearPollInterval();
            
            let errorMessage = wpEasyMigrate.strings.error || 'An error occurred during export.';
            
            if (error && typeof error === 'string') {
                errorMessage = error;
            } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            }
            
            this.updateUI('error', errorMessage);
        }
        
        /**
         * Handle successful completion
         * 
         * @param {string} message Success message
         * @param {Object} status Export status
         */
        handleSuccess(message, status) {
            this.isRunning = false;
            this.clearPollInterval();
            
            let successMessage = message || wpEasyMigrate.strings.success || 'Export completed successfully!';
            
            if (status.archive_path) {
                const fileName = status.archive_path.split('/').pop();
                successMessage += `<br><strong>${wpEasyMigrate.strings.file || 'File'}:</strong> ${fileName}`;
            }
            
            this.updateUI('success', successMessage);
        }
        
        /**
         * Schedule next poll
         */
        scheduleNextPoll() {
            this.pollInterval = setTimeout(() => {
                this.makeExportRequest();
            }, this.pollDelay);
        }
        
        /**
         * Clear poll interval
         */
        clearPollInterval() {
            if (this.pollInterval) {
                clearTimeout(this.pollInterval);
                this.pollInterval = null;
            }
        }
        
        /**
         * Update progress display
         * 
         * @param {Object} status Export status
         */
        updateProgress(status) {
            const progress = status.progress || 0;
            const step = status.step || '';
            
            // Update progress bar
            this.$progressBar.css('width', progress + '%');
            
            // Update status text
            const stepMessage = this.getStepMessage(step);
            let statusText = `${stepMessage} (${progress}%)`;
            
            // Add file archiving specific information
            if (step === 'archive_files' && status.file_archiving) {
                const fileInfo = status.file_archiving;
                statusText = `${stepMessage} (${fileInfo.current_index}/${fileInfo.total_files} files, ${progress}%)`;
                
                // Update additional progress information
                this.updateFileArchivingProgress(fileInfo);
            }
            
            this.$status.text(statusText);
            
            // Update progress text if element exists
            if (this.$progressText.length) {
                const totalSteps = 7; // Updated step count
                this.$progressText.text(`Step ${status.step_index + 1} of ${totalSteps}: ${stepMessage}`);
            }
        }
        
        /**
         * Update file archiving specific progress
         * 
         * @param {Object} fileInfo File archiving information
         */
        updateFileArchivingProgress(fileInfo) {
            // Update or create additional progress elements
            if (!this.$fileProgress) {
                this.$fileProgress = $('<div class="wp-easy-migrate-file-progress"></div>');
                this.$progress.append(this.$fileProgress);
            }
            
            let progressHtml = '';
            
            // Current file being processed
            if (fileInfo.current_file) {
                progressHtml += `<div class="current-file">Processing: ${fileInfo.current_file}</div>`;
            }
            
            // Estimated size remaining
            if (fileInfo.estimated_size_remaining > 0) {
                const sizeRemaining = this.formatFileSize(fileInfo.estimated_size_remaining);
                const totalSize = this.formatFileSize(fileInfo.total_size);
                progressHtml += `<div class="size-progress">Size: ${sizeRemaining} remaining of ${totalSize}</div>`;
            }
            
            // Estimated time remaining
            if (fileInfo.estimated_time_remaining > 0) {
                const timeRemaining = this.formatTime(fileInfo.estimated_time_remaining);
                progressHtml += `<div class="time-remaining">Estimated time remaining: ${timeRemaining}</div>`;
            }
            
            this.$fileProgress.html(progressHtml);
        }
        
        /**
         * Format file size in human readable format
         * 
         * @param {number} bytes File size in bytes
         * @returns {string} Formatted file size
         */
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        /**
         * Format time in human readable format
         * 
         * @param {number} seconds Time in seconds
         * @returns {string} Formatted time
         */
        formatTime(seconds) {
            if (seconds < 60) {
                return `${Math.round(seconds)} seconds`;
            } else if (seconds < 3600) {
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = Math.round(seconds % 60);
                return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return `${hours}:${minutes.toString().padStart(2, '0')}:00`;
            }
        }
        
        /**
         * Get user-friendly step message
         * 
         * @param {string} step Step name
         * @returns {string} Step message
         */
        getStepMessage(step) {
            const messages = {
                'prepare_export': wpEasyMigrate.strings.preparingExport || 'Preparing export...',
                'scan_files': wpEasyMigrate.strings.scanningFiles || 'Scanning files...',
                'export_database': wpEasyMigrate.strings.exportingDatabase || 'Exporting database...',
                'archive_files': wpEasyMigrate.strings.archivingFiles || 'Archiving files...',
                'create_manifest': wpEasyMigrate.strings.creatingManifest || 'Creating manifest...',
                'split_archive': wpEasyMigrate.strings.splittingArchive || 'Splitting archive...',
                'finalize_export': wpEasyMigrate.strings.finalizingExport || 'Finalizing export...'
            };
            
            return messages[step] || (wpEasyMigrate.strings.processing || 'Processing...');
        }
        
        /**
         * Update UI state
         * 
         * @param {string} state UI state (starting, running, success, error)
         * @param {string} message Optional message
         */
        updateUI(state, message = '') {
            switch (state) {
                case 'starting':
                    this.$button.prop('disabled', true).text(wpEasyMigrate.strings.exporting || 'Exporting...');
                    this.$progress.show();
                    this.$result.hide();
                    this.$status.text(wpEasyMigrate.strings.preparingExport || 'Preparing export...');
                    this.$progressBar.css('width', '0%');
                    break;
                    
                case 'success':
                    this.$button.prop('disabled', false).text(wpEasyMigrate.strings.startExport || 'Start Export');
                    this.$progress.hide();
                    this.$result.removeClass('error').addClass('success').html(message).show();
                    break;
                    
                case 'error':
                    this.$button.prop('disabled', false).text(wpEasyMigrate.strings.startExport || 'Start Export');
                    this.$progress.hide();
                    this.$result.removeClass('success').addClass('error')
                              .html(`<strong>${wpEasyMigrate.strings.exportFailed || 'Export failed'}:</strong><br>${message}`)
                              .show();
                    break;
            }
        }
        
        /**
         * Stop export process
         */
        stopExport() {
            if (!this.isRunning) {
                return;
            }
            
            this.isRunning = false;
            this.clearPollInterval();
            this.updateUI('error', wpEasyMigrate.strings.exportCancelled || 'Export was cancelled.');
        }
    }
    
    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        // Only initialize on the export page
        if ($('#wp-easy-migrate-export-form').length) {
            window.wpEasyMigrateExport = new ExportManager();
        }
    });
    
})(jQuery);