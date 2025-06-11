/**
 * WP Easy Migrate Import JavaScript
 */

(function ($) {
  "use strict";

  /**
   * Import Manager Class
   */
  class ImportManager {
    constructor() {
      this.$form = $("#wp-easy-migrate-import-form");
      this.$submitBtn = this.$form.find('input[type="submit"]');
      this.$progress = $("#wp-easy-migrate-import-progress");
      this.$status = $("#wp-easy-migrate-import-status");
      this.$logs = $("#wp-easy-migrate-logs");

      this.isRunning = false;
      this.pollInterval = null;
      this.maxRetries = 3;
      this.currentRetries = 0;
      this.retryDelay = 2000;

      this.init();
    }

    /**
     * Initialize the import manager
     */
    init() {
      this.bindEvents();
      this.updateUI("ready", "Ready to import");
      this.initializeSteps();
    }

    /**
     * Initialize all steps as waiting
     */
    initializeSteps() {
      $(".import-step")
        .removeClass("waiting running completed failed")
        .addClass("waiting");
    }

    /**
     * Bind event handlers
     */
    bindEvents() {
      this.$form.on("submit", (e) => {
        e.preventDefault();
        this.startImport();
      });

      // Prevent page navigation during import
      $(window).on("beforeunload", (e) => {
        if (this.isRunning) {
          const message =
            wpEasyMigrate.strings.importInProgress ||
            "Import is in progress. Are you sure you want to leave?";
          e.returnValue = message;
          return message;
        }
      });
    }

    /**
     * Start the import process
     */
    startImport() {
      if (this.isRunning) {
        return;
      }

      // Validate file upload
      const fileInput = this.$form.find('input[type="file"]')[0];
      if (!fileInput.files.length) {
        alert("Please select a file to import.");
        return;
      }

      this.isRunning = true;
      this.currentRetries = 0;
      this.updateUI("running", "Starting import...");

      // Show progress container and initialize steps
      this.$progress.show();
      this.initializeSteps();

      this.makeImportRequest(true);
    }

    /**
     * Make AJAX request for import step
     *
     * @param {boolean} startImport Whether this is the initial import request
     */
    makeImportRequest(startImport = false) {
      const formData = new FormData();
      formData.append("action", "wpem_import_step");
      formData.append("nonce", wpEasyMigrate.nonce);

      // Add file data for initial request
      if (startImport) {
        formData.append("start_import", "1");
        const fileInput = this.$form.find('input[type="file"]')[0];
        if (fileInput.files.length) {
          formData.append("import_file", fileInput.files[0]);
        }
      }

      $.ajax({
        url: wpEasyMigrate.ajaxUrl,
        type: "POST",
        data: formData,
        processData: false,
        contentType: false,
        timeout: 120000, // 2 minute timeout for file upload
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
     * @param {Object} response Server response
     */
    handleResponse(response) {
      if (response.success) {
        const status = response.data.status;
        const message = response.data.message;

        this.updateImportStatus(status, message);

        if (status.completed) {
          this.handleSuccess(message, status);
        } else {
          // Continue with next step
          this.scheduleNextRequest();
        }
      } else {
        this.handleError(null, null, response.data.message);
      }
    }

    /**
     * Handle successful completion
     *
     * @param {string} message Success message
     * @param {Object} status Import status
     */
    handleSuccess(message, status) {
      this.isRunning = false;
      this.clearPollInterval();

      // Mark all steps as completed
      $(".import-step")
        .removeClass("waiting running failed")
        .addClass("completed");

      // Restore original descriptions
      $(".import-step").each((index, element) => {
        const $step = $(element);
        this.updateStepStatus($step, "completed");
      });

      let successMessage =
        message ||
        wpEasyMigrate.strings.success ||
        "Import completed successfully!";

      // Add imported files info
      if (status.files_imported && status.files_imported.length > 0) {
        successMessage += `<br><strong>Imported:</strong> ${status.files_imported.join(
          ", "
        )}`;
      }

      this.updateUI("success", successMessage);
      this.loadLogs(); // Refresh logs on completion
    }

    /**
     * Handle errors
     *
     * @param {Object} xhr XMLHttpRequest object
     * @param {string} status Status text
     * @param {string} error Error message
     */
    handleError(xhr, status, error) {
      if (this.currentRetries < this.maxRetries && status !== "abort") {
        this.currentRetries++;
        this.updateUI(
          "warning",
          `Connection error. Retrying (${this.currentRetries}/${this.maxRetries})...`
        );

        setTimeout(() => {
          this.makeImportRequest(false);
        }, this.retryDelay);
        return;
      }

      this.isRunning = false;
      this.clearPollInterval();

      // Mark current running step as failed
      $(".import-step.running").removeClass("running").addClass("failed");
      $(".import-step.failed .step-description").text("Operation failed");

      let errorMessage = error;
      if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
        errorMessage = xhr.responseJSON.data.message || xhr.responseJSON.data;
      }

      this.updateUI(
        "error",
        `Import failed: ${errorMessage || "Unknown error occurred"}`
      );
      this.loadLogs(); // Show logs on error
    }

    /**
     * Schedule next request
     */
    scheduleNextRequest() {
      this.clearPollInterval();
      this.pollInterval = setTimeout(() => {
        this.makeImportRequest(false);
      }, 1000); // 1 second delay between steps
    }

    /**
     * Clear polling interval
     */
    clearPollInterval() {
      if (this.pollInterval) {
        clearTimeout(this.pollInterval);
        this.pollInterval = null;
      }
    }

    /**
     * Update import status display
     *
     * @param {Object} status Import status
     * @param {string} message Status message
     */
    updateImportStatus(status, message) {
      // Update step checklist
      this.updateStepChecklist(status);

      // Update overall status message
      this.updateUI("running", message);
    }

    /**
     * Update step checklist based on current status
     *
     * @param {Object} status Import status object
     */
    updateStepChecklist(status) {
      const currentStep = status.step;
      const isError = status.error || status.failed;

      // Get all steps in order
      const steps = [
        "upload_file",
        "extract_archive",
        "validate_manifest",
        "backup_current_site",
        "import_database",
        "import_files",
        "update_urls",
        "cleanup",
      ];

      const currentStepIndex = steps.indexOf(currentStep);

      steps.forEach((step, index) => {
        const $step = $(`.import-step[data-step="${step}"]`);

        if (index < currentStepIndex) {
          // Previous steps are completed
          this.updateStepStatus($step, "completed");
        } else if (index === currentStepIndex) {
          // Current step
          if (isError) {
            this.updateStepStatus(
              $step,
              "failed",
              status.current_operation || "Operation failed"
            );
          } else {
            this.updateStepStatus(
              $step,
              "running",
              status.current_operation || this.getStepMessage(step)
            );
          }
        } else {
          // Future steps are waiting
          this.updateStepStatus($step, "waiting");
        }
      });
    }

    /**
     * Update individual step status
     *
     * @param {jQuery} $step Step element
     * @param {string} status Status (waiting, running, completed, failed)
     * @param {string} description Optional description override
     */
    updateStepStatus($step, status, description = null) {
      $step.removeClass("waiting running completed failed").addClass(status);

      if (description) {
        $step.find(".step-description").text(description);
      } else {
        // Restore original description if no override
        const step = $step.data("step");
        const originalDescriptions = {
          upload_file: "Processing uploaded archive file",
          extract_archive: "Extracting files from archive",
          validate_manifest: "Checking import compatibility",
          backup_current_site: "Creating safety backup",
          import_database: "Restoring database content",
          import_files: "Restoring media and files",
          update_urls: "Updating site URLs",
          cleanup: "Cleaning up temporary files",
        };
        $step
          .find(".step-description")
          .text(originalDescriptions[step] || "Processing...");
      }
    }

    /**
     * Update UI state
     *
     * @param {string} state UI state (ready, running, success, error, warning)
     * @param {string} message Status message
     */
    updateUI(state, message) {
      // Update button state
      this.$submitBtn.prop("disabled", state === "running");

      if (state === "running") {
        this.$submitBtn.val(wpEasyMigrate.strings.importing || "Importing...");
      } else {
        this.$submitBtn.val("Start Import");
      }

      // Update status message
      this.$status
        .removeClass("notice-info notice-success notice-error notice-warning")
        .addClass(`notice-${this.getNoticeClass(state)}`)
        .html(`<p>${message}</p>`)
        .show();
    }

    /**
     * Get notice class for state
     *
     * @param {string} state UI state
     * @returns {string} Notice class
     */
    getNoticeClass(state) {
      const classes = {
        ready: "info",
        running: "info",
        success: "success",
        error: "error",
        warning: "warning",
      };
      return classes[state] || "info";
    }

    /**
     * Get user-friendly step message
     *
     * @param {string} step Step name
     * @returns {string} Step message
     */
    getStepMessage(step) {
      const messages = {
        upload_file: "Uploading file...",
        extract_archive: "Extracting archive...",
        validate_manifest: "Validating manifest...",
        backup_current_site: "Creating backup...",
        import_database: "Importing database...",
        import_files: "Importing files...",
        update_urls: "Updating URLs...",
        cleanup: "Cleaning up...",
      };

      return messages[step] || "Processing...";
    }

    /**
     * Load recent logs
     */
    loadLogs() {
      if (!this.$logs.length) {
        return;
      }

      $.ajax({
        url: wpEasyMigrate.ajaxUrl,
        type: "POST",
        data: {
          action: "wp_easy_migrate_get_logs",
          nonce: wpEasyMigrate.nonce,
        },
        success: (response) => {
          if (response.success && response.data.logs) {
            this.displayLogs(response.data.logs);
          }
        },
      });
    }

    /**
     * Display logs
     *
     * @param {Array} logs Array of log entries
     */
    displayLogs(logs) {
      if (!logs.length) {
        this.$logs.html("<p>No logs available.</p>");
        return;
      }

      let logsHtml = '<div class="wp-easy-migrate-log-entries">';
      logs.forEach((log) => {
        const timestamp = new Date(log.timestamp).toLocaleString();
        logsHtml += `
                    <div class="log-entry log-${log.level}">
                        <span class="log-timestamp">${timestamp}</span>
                        <span class="log-level">[${log.level.toUpperCase()}]</span>
                        <span class="log-message">${log.message}</span>
                    </div>
                `;
      });
      logsHtml += "</div>";

      this.$logs.html(logsHtml);
    }

    /**
     * Stop import (for future use)
     */
    stopImport() {
      if (!this.isRunning) {
        return;
      }

      this.isRunning = false;
      this.clearPollInterval();
      this.updateUI("warning", "Import cancelled by user");
    }
  }

  // Initialize when document is ready
  $(document).ready(function () {
    if ($("#wp-easy-migrate-import-form").length) {
      new ImportManager();
    }
  });
})(jQuery);
