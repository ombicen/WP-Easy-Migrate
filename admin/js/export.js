/**
 * WP Easy Migrate - Export JavaScript
 *
 * Handles step-based export process with AJAX polling
 */

(function ($) {
  "use strict";

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
      this.$form = $("#wp-easy-migrate-export-form");
      this.$button = $("#start-export");
      this.$progress = $("#export-progress");
      this.$progressBar = $(".wp-easy-migrate-progress-fill");
      this.$status = $("#export-status");
      this.$result = $("#export-result");
      this.$progressText = $("#export-progress-text");
    }

    /**
     * Bind event handlers
     */
    bindEvents() {
      this.$form.on("submit", (e) => {
        e.preventDefault();
        this.startExport();
      });

      // Handle page unload during export
      $(window).on("beforeunload", () => {
        if (this.isRunning) {
          return (
            wpEasyMigrate.strings.exportInProgress ||
            "Export is in progress. Are you sure you want to leave?"
          );
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
      this.updateUI("starting");

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
        action: "wpem_export_step",
        nonce: wpEasyMigrate.nonce,
      };

      // Add form data for initial request
      if (startExport) {
        data.start_export = true;
        data.include_uploads = this.$form
          .find('[name="include_uploads"]')
          .is(":checked")
          ? 1
          : 0;
        data.include_plugins = this.$form
          .find('[name="include_plugins"]')
          .is(":checked")
          ? 1
          : 0;
        data.include_themes = this.$form
          .find('[name="include_themes"]')
          .is(":checked")
          ? 1
          : 0;
        data.include_database = this.$form
          .find('[name="include_database"]')
          .is(":checked")
          ? 1
          : 0;
        data.split_size = this.$form.find('[name="split_size"]').val() || 100;
        data.files_per_step =
          this.$form.find('[name="files_per_step"]').val() || 50;
      }

      $.ajax({
        url: wpEasyMigrate.ajaxUrl,
        type: "POST",
        data: data,
        timeout: 60000, // 60 second timeout
        success: (response) => {
          this.currentRetries = 0; // Reset retry counter on success
          this.handleResponse(response);
        },
        error: (xhr, status, error) => {
          this.handleError(xhr, status, error);
        },
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
            this.handleError(null, "export_error", status.error);
          } else {
            this.handleSuccess(response.data.message, status);
          }
        } else {
          // Continue polling for next step
          this.scheduleNextPoll();
        }
      } else {
        this.handleError(null, "response_error", response.data.message);
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

      if (this.currentRetries < this.maxRetries && status !== "export_error") {
        // Retry after delay
        console.log(
          `Export request failed, retrying (${this.currentRetries}/${this.maxRetries})...`
        );
        setTimeout(() => {
          this.makeExportRequest();
        }, this.pollDelay * this.currentRetries); // Exponential backoff
        return;
      }

      // Max retries reached or permanent error
      this.isRunning = false;
      this.clearPollInterval();

      let errorMessage =
        wpEasyMigrate.strings.error || "An error occurred during export.";

      if (error && typeof error === "string") {
        errorMessage = error;
      } else if (
        xhr &&
        xhr.responseJSON &&
        xhr.responseJSON.data &&
        xhr.responseJSON.data.message
      ) {
        errorMessage = xhr.responseJSON.data.message;
      }

      this.updateUI("error", errorMessage);
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

      let successMessage =
        message ||
        wpEasyMigrate.strings.success ||
        "Export completed successfully!";

      if (status.archive_path) {
        const fileName = status.archive_path.split("/").pop();
        successMessage += `<br><strong>${
          wpEasyMigrate.strings.file || "File"
        }:</strong> ${fileName}`;
      }

      // Add download buttons
      successMessage += this.createDownloadButtons(status);

      this.updateUI("success", successMessage);
    }

    /**
     * Create download buttons for completed export
     *
     * @param {Object} status Export status
     * @returns {string} HTML for download buttons
     */
    createDownloadButtons(status) {
      let downloadHtml =
        '<div style="margin-top: 15px; padding: 15px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px;">';
      downloadHtml +=
        '<h4 style="margin: 0 0 10px 0; color: #0073aa;">📥 Download Your Export</h4>';

      if (status.archive_parts && status.archive_parts.length > 1) {
        // Split archive - multiple parts
        downloadHtml +=
          "<p>Your export has been split into " +
          status.archive_parts.length +
          " parts:</p>";
        status.archive_parts.forEach((part, index) => {
          const partFileName = part.split("/").pop();
          downloadHtml += `<div style="margin: 5px 0;">
            <button class="button button-primary download-part" data-file="${partFileName}" style="margin-right: 10px;">
              📁 Download Part ${index + 1}
            </button>
            <span style="font-size: 12px; color: #666;">${partFileName}</span>
          </div>`;
        });

        downloadHtml +=
          '<button class="button button-secondary download-all-parts" style="margin-top: 10px;">📦 Download All Parts</button>';
        downloadHtml +=
          '<p style="margin-top: 10px; font-size: 12px; color: #666;">⚠️ <strong>Important:</strong> You need ALL parts to restore your site. Download all parts and keep them together.</p>';
      } else {
        // Single archive
        const fileName = status.archive_path
          ? status.archive_path.split("/").pop()
          : "export.zip";
        downloadHtml += `<button class="button button-primary download-single" data-file="${fileName}" style="margin-right: 10px;">
          📁 Download Export
        </button>`;
        downloadHtml += `<span style="font-size: 12px; color: #666;">${fileName}</span>`;
      }

      // Add manifest download if available
      if (status.standalone_manifest_path) {
        const manifestFileName = status.standalone_manifest_path
          .split("/")
          .pop();
        downloadHtml += `<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
          <button class="button download-manifest" data-file="${manifestFileName}" style="margin-right: 10px;">
            📄 Download Manifest
          </button>
          <span style="font-size: 12px; color: #666;">Verification file (recommended for split archives)</span>
        </div>`;
      }

      downloadHtml += "</div>";

      // Bind download button events
      setTimeout(() => {
        this.bindDownloadEvents();
      }, 100);

      return downloadHtml;
    }

    /**
     * Bind download button events
     */
    bindDownloadEvents() {
      const self = this;

      // Single file download
      $(".download-single, .download-part, .download-manifest").on(
        "click",
        function (e) {
          e.preventDefault();
          const fileName = $(this).data("file");
          self.downloadFile(fileName);
        }
      );

      // Download all parts
      $(".download-all-parts").on("click", function (e) {
        e.preventDefault();
        $(".download-part").each(function () {
          const fileName = $(this).data("file");
          setTimeout(() => {
            self.downloadFile(fileName);
          }, Math.random() * 1000); // Stagger downloads
        });
      });
    }

    /**
     * Download a specific file
     *
     * @param {string} fileName File name to download
     */
    downloadFile(fileName) {
      // Create download link
      const downloadUrl =
        wpEasyMigrate.ajaxUrl +
        "?action=wp_easy_migrate_download&file=" +
        encodeURIComponent(fileName) +
        "&nonce=" +
        wpEasyMigrate.nonce;

      // Create temporary link and click it
      const link = document.createElement("a");
      link.href = downloadUrl;
      link.download = fileName;
      link.style.display = "none";
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
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
      const step = status.step || "";

      // Update progress bar
      this.$progressBar.css("width", progress + "%");

      // Update status text
      const stepMessage = this.getStepMessage(step);
      let statusText = `${stepMessage} (${progress}%)`;

      // Add step-specific information
      if (step === "export_database" && status.database_export) {
        const dbInfo = status.database_export;
        statusText = `${stepMessage} (${
          dbInfo.current_table || "Initializing"
        }, ${progress}%)`;

        // Update database progress information
        this.updateDatabaseProgress(dbInfo);
      } else if (step === "archive_files" && status.file_archiving) {
        const fileInfo = status.file_archiving;
        const batchInfo = status.batch_processing;
        const filesPerStep = batchInfo ? batchInfo.files_per_step : 50;
        statusText = `${stepMessage} (${fileInfo.current_index}/${fileInfo.total_files} files, ${filesPerStep} per batch, ${progress}%)`;

        // Update file archiving progress information
        this.updateFileArchivingProgress(fileInfo, batchInfo);
      }

      this.$status.text(statusText);

      // Update progress text if element exists
      if (this.$progressText.length) {
        const totalSteps = 7; // Updated step count
        this.$progressText.text(
          `Step ${status.step_index + 1} of ${totalSteps}: ${stepMessage}`
        );
      }
    }

    /**
     * Update database export progress
     *
     * @param {Object} dbInfo Database export information
     */
    updateDatabaseProgress(dbInfo) {
      // Update or create database progress elements
      if (!this.$dbProgress) {
        this.$dbProgress = $('<div class="wp-easy-migrate-db-progress"></div>');
        this.$progress.append(this.$dbProgress);
      }

      let progressHtml = "";

      // Current table being processed
      if (dbInfo.current_table) {
        progressHtml += `<div class="current-table">Exporting table: ${dbInfo.current_table}</div>`;
      }

      // Table progress
      if (dbInfo.total_tables > 0) {
        progressHtml += `<div class="table-progress">Table ${
          dbInfo.current_table_index + 1
        } of ${dbInfo.total_tables}</div>`;
      }

      // Row offset if available
      if (dbInfo.table_offset > 0) {
        progressHtml += `<div class="row-progress">Rows processed: ${dbInfo.table_offset.toLocaleString()}</div>`;
      }

      this.$dbProgress.html(progressHtml);
    }

    /**
     * Update file archiving specific progress
     *
     * @param {Object} fileInfo File archiving information
     * @param {Object} batchInfo Batch processing information
     */
    updateFileArchivingProgress(fileInfo, batchInfo) {
      // Update or create additional progress elements
      if (!this.$fileProgress) {
        this.$fileProgress = $(
          '<div class="wp-easy-migrate-file-progress"></div>'
        );
        this.$progress.append(this.$fileProgress);
      }

      let progressHtml = "";

      // Batch processing info
      if (batchInfo && batchInfo.files_per_step) {
        progressHtml += `<div class="batch-info">Processing ${batchInfo.files_per_step} files per batch</div>`;
      }

      // Current file being processed
      if (fileInfo.current_file) {
        progressHtml += `<div class="current-file">Last processed: ${fileInfo.current_file}</div>`;
      }

      // Estimated size remaining
      if (fileInfo.estimated_size_remaining > 0) {
        const sizeRemaining = this.formatFileSize(
          fileInfo.estimated_size_remaining
        );
        const totalSize = this.formatFileSize(fileInfo.total_size);
        progressHtml += `<div class="size-progress">Size: ${sizeRemaining} remaining of ${totalSize}</div>`;
      }

      // Estimated time remaining
      if (fileInfo.estimated_time_remaining > 0) {
        const timeRemaining = this.formatTime(
          fileInfo.estimated_time_remaining
        );
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
      if (bytes === 0) return "0 Bytes";

      const k = 1024;
      const sizes = ["Bytes", "KB", "MB", "GB", "TB"];
      const i = Math.floor(Math.log(bytes) / Math.log(k));

      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
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
        return `${minutes}:${remainingSeconds.toString().padStart(2, "0")}`;
      } else {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return `${hours}:${minutes.toString().padStart(2, "0")}:00`;
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
        prepare_export:
          wpEasyMigrate.strings.preparingExport || "Preparing export...",
        scan_files: wpEasyMigrate.strings.scanningFiles || "Scanning files...",
        export_database:
          wpEasyMigrate.strings.exportingDatabase || "Exporting database...",
        archive_files:
          wpEasyMigrate.strings.archivingFiles || "Archiving files...",
        create_manifest:
          wpEasyMigrate.strings.creatingManifest || "Creating manifest...",
        split_archive:
          wpEasyMigrate.strings.splittingArchive || "Splitting archive...",
        finalize_export:
          wpEasyMigrate.strings.finalizingExport || "Finalizing export...",
      };

      return (
        messages[step] || wpEasyMigrate.strings.processing || "Processing..."
      );
    }

    /**
     * Update UI state
     *
     * @param {string} state UI state (starting, running, success, error)
     * @param {string} message Optional message
     */
    updateUI(state, message = "") {
      switch (state) {
        case "starting":
          this.$button
            .prop("disabled", true)
            .text(wpEasyMigrate.strings.exporting || "Exporting...");
          this.$progress.show();
          this.$result.hide();
          this.$status.text(
            wpEasyMigrate.strings.preparingExport || "Preparing export..."
          );
          this.$progressBar.css("width", "0%");
          break;

        case "success":
          this.$button
            .prop("disabled", false)
            .text(wpEasyMigrate.strings.startExport || "Start Export");
          this.$progress.hide();
          this.$result
            .removeClass("error")
            .addClass("success")
            .html(message)
            .show();
          break;

        case "error":
          this.$button
            .prop("disabled", false)
            .text(wpEasyMigrate.strings.startExport || "Start Export");
          this.$progress.hide();
          this.$result
            .removeClass("success")
            .addClass("error")
            .html(
              `<strong>${
                wpEasyMigrate.strings.exportFailed || "Export failed"
              }:</strong><br>${message}`
            )
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
      this.updateUI(
        "error",
        wpEasyMigrate.strings.exportCancelled || "Export was cancelled."
      );
    }
  }

  /**
   * Initialize when document is ready
   */
  $(document).ready(function () {
    // Only initialize on the export page
    if ($("#wp-easy-migrate-export-form").length) {
      window.wpEasyMigrateExport = new ExportManager();
    }
  });
})(jQuery);
