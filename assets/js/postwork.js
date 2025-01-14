(function ($) {
  "use strict";

  class PostWork {
    constructor() {
      this.$ = $; // Store jQuery reference
      this.STATUS_CHECK_INTERVAL = 10000; // 10 seconds
      this.MAX_RETRIES = 60; // 1 minute
      this.batchTimeout = null;
      this.urlUpdateTimeout = null;
      this.hasUnsavedChanges = false;
      this.initialState = {
        title: $("#postwork-title").val(),
        webhook_id: $("#webhook-id").val(),
        post_type: $("#post-type").val(),
        enabled_taxonomies: this.getEnabledTaxonomies(),
      };

      // Add new properties
      this.$apiFormatModal = $(".api-format-modal");
      this.$apiFormatCode = $(".api-format-code");

      this.isRunning = false;
      this.shouldStop = false;

      this.init();
      this.initExportImport();
    }

    init() {
      this.initEventListeners();
      this.initStickyControls();
      this.initSelect2();
      this.updatePostWorkState();
      this.updateStatusCounts();

      // Check initial blocks state and show message if needed
      const $blocks = $(".postblock");
      this.updateNoBlocksMessage($blocks.length === 0);

      // Trigger initial filter
      $("#block-status-filter").trigger("change");
    }

    updateNoBlocksMessage(show, status = "all") {
      let $noBlocksMessage = $("#no-blocks-message");

      if ($noBlocksMessage.length === 0) {
        $noBlocksMessage = $(`
          <div id="no-blocks-message" class="no-blocks-message">
            <p>${
              status === "all"
                ? "No blocks found."
                : `No ${status} blocks found.`
            }</p>
          </div>
        `);
        $("#postblocks").append($noBlocksMessage);
      } else {
        $noBlocksMessage.html(
          `<p>${
            status === "all" ? "No blocks found." : `No ${status} blocks found.`
          }</p>`
        );
      }

      $noBlocksMessage.toggle(show);
    }

    initEventListeners() {
      // Post Work Actions
      $("#add-new-postwork").on("click", () => this.handleAddNewPostWork());
      $(".delete-postwork").on("click", (e) => this.handleDeletePostWork(e));
      $("#save-postwork").on("click", () => this.handleSavePostWork());
      $("#add-postblock").on("click", () => this.handleAddPostBlock());
      $("#run-postwork").on("click", () => this.handleRunPostWork());

      // Post Work Field Changes
      $("#postwork-title").on("input", () => this.handleFieldChange());
      $("#webhook-id").on("change", () => this.handleFieldChange());
      $("#post-type").on("change", () => this.handleFieldChange());
      $(".taxonomy-checkbox").on("change", (e) => this.handleTaxonomyChange(e));

      // Block Filter
      $("#block-status-filter").on("change", (e) => this.handleStatusFilter(e));

      // Block Actions (using event delegation)
      $(document).on("click", ".duplicate-postblock", (e) =>
        this.handleDuplicateBlock(e)
      );
      $(document).on("click", ".delete-postblock", (e) =>
        this.handleDeleteBlock(e)
      );
      $(document).on("click", ".run-block", (e) =>
        this.handleRunSingleBlock(e)
      );
      $(document).on("click", ".postblock-header", (e) =>
        this.handleBlockHeaderClick(e)
      );

      // Input Changes
      $(document).on("input", ".article-url", (e) => {
        this.handleArticleUrlChange(e);
        this.handleFieldChange();
      });
      $(document).on("input", ".taxonomy-field", () =>
        this.handleFieldChange()
      );

      // Select2 changes
      $(document).on("change", ".default-terms-select", () => {
        this.handleFieldChange();
      });

      // Header interactions
      $("#title-display").on("click", () => this.handleTitleEdit());
      $("#postwork-title").on("blur", () => this.handleTitleSave());
      $("#postwork-title").on("keypress", (e) => {
        if (e.which === 13) {
          this.handleTitleSave();
        }
      });

      $(".postwork-header-value").on("click", function (e) {
        const $value = $(this);
        const $select = $value.find("select");
        if ($select.length) {
          e.stopPropagation();
          $select.show().focus();
          $value.addClass("editing");
        }
      });

      $(".postwork-header-value select").on("blur change", function () {
        const $select = $(this);
        const $value = $select.closest(".postwork-header-value");
        const $text = $value.find("span").first();
        $text.text($select.find("option:selected").text());
        $select.hide();
        $value.removeClass("editing");
      });

      // Side panel handling
      $(".side-panel-close, .side-panel-overlay").on("click", (e) => {
        const $panel = $(e.target).closest(".side-panel");
        if ($panel.length) {
          this.closeSidePanel($panel.attr("class").split(" ")[1]);
        } else {
          // Clicked on overlay
          $(".side-panel.active").each((_, panel) => {
            this.closeSidePanel($(panel).attr("class").split(" ")[1]);
          });
        }
      });

      $(".side-panel").on("click", (e) => e.stopPropagation());

      // Panel triggers
      $("#taxonomy-trigger").on("click", () =>
        this.openSidePanel("taxonomy-panel")
      );
      $("#prompts-trigger").on("click", () =>
        this.openSidePanel("prompts-panel")
      );
      $(".add-prompt-button").on("click", () => this.addNewPrompt());

      // Prompt actions
      const self = this;
      $(".prompts-panel .side-panel-content").on(
        "click",
        ".prompt-delete",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.deletePrompt(e);
        }
      );

      $(document).on("input", ".prompt-title-input, .prompt-textarea", () =>
        this.handleFieldChange()
      );
      $(document).on("input", ".prompt-title-input:not([readonly])", (e) =>
        this.handlePromptTitleChange(e)
      );

      // Initialize click handlers for existing prompt delete buttons
      $(".prompt-delete").each(function () {
        $(this).on("click", function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.deletePrompt(e);
        });
      });

      // Block prompts
      $(document).on("click", ".add-block-prompt", (e) =>
        this.addBlockPrompt(e)
      );

      $(document).on("click", ".block-prompt-item .prompt-delete", (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.deleteBlockPrompt(e);
      });

      $(document).on(
        "input",
        ".block-prompt-item .prompt-title-input, .block-prompt-item .prompt-textarea",
        () => {
          this.handleFieldChange();
        }
      );

      // Block prompts accordion
      $(document).on("click", ".block-prompts-header", (e) => {
        const $section = $(e.currentTarget).closest(".block-prompts-section");
        const $content = $section.find(".block-prompts-content");

        $section.toggleClass("collapsed");

        if ($section.hasClass("collapsed")) {
          $content.slideUp(300);
        } else {
          $content.slideDown(300);
        }

        // Update prompt count
        const promptCount = $section.find(".block-prompt-item").length;
        $section.find(".block-prompts-count").text(`(${promptCount})`);
      });

      // Update prompt count when adding/removing prompts
      $(document).on(
        "prompt:added prompt:removed",
        ".block-prompts-section",
        (e) => {
          const $section = $(e.currentTarget);
          const promptCount = $section.find(".block-prompt-item").length;
          $section.find(".block-prompts-count").text(`(${promptCount})`);
        }
      );

      // Custom Fields Panel
      $("#custom-fields-trigger").on("click", () =>
        this.openSidePanel("custom-fields-panel")
      );
      $(".add-custom-field-button").on("click", () => this.addNewCustomField());

      // Custom field actions
      $(".custom-fields-container").on("click", ".custom-field-delete", (e) =>
        this.deleteCustomField(e)
      );

      $(document).on("input", ".custom-field-key-input", (e) => {
        const isValid = this.validateCustomFieldKey(e);
        if (isValid) {
          this.updateBlocksCustomFields();
        }
        this.handleFieldChange();
      });

      $(document).on("input", ".custom-field-value", () => {
        this.updateBlocksCustomFields();
        this.handleFieldChange();
      });

      // Block custom field value changes
      $(document).on("input", ".custom-field-value-input", () => {
        this.handleFieldChange();
      });

      // Feature Image Upload
      $(document).on("click", ".upload-feature-image", (e) =>
        this.handleFeatureImageUpload(e)
      );
      $(document).on("click", ".remove-feature-image", (e) =>
        this.handleFeatureImageRemove(e)
      );

      // Status and Author dropdowns
      $("#post-status").on("change", () => {
        const $select = $("#post-status");
        const $text = $select.siblings(".status-text");
        $text.text($select.find("option:selected").text());
        this.handleFieldChange();
      });

      $("#default-author-id").on("change", () => {
        const $select = $("#default-author-id");
        const $text = $select.siblings(".author-text");
        $text.text($select.find("option:selected").text());
        this.handleFieldChange();
      });

      // Options toggle
      $(".toggle-options").on("click", (e) => {
        const $button = $(e.currentTarget);
        const $options = $(".postwork-header");
        const isShowing = $options.hasClass("active");

        $button.toggleClass("active");
        $options.toggleClass("active");

        // Update button text
        $button
          .find(".button-text")
          .text(isShowing ? "Show Options" : "Hide Options");

        // Show/hide the container
        if (!isShowing) {
          $options.css("display", "block");
        } else {
          setTimeout(() => {
            $options.css("display", "none");
          }, 300); // Match the CSS transition duration
        }
      });

      // Add new event listeners
      $(".show-api-format").on("click", () => this.showApiFormat());
      $(".modal-close").on("click", (e) => {
        e.stopPropagation(); // Prevent event from bubbling up
        this.hideApiFormat();
      });
      $(".api-format-modal").on("click", (e) => {
        if (e.target === e.currentTarget) {
          this.hideApiFormat();
        }
      });
      $(".copy-format").on("click", () => this.copyApiFormat());

      // Add kill button handler
      $(document).on("click", "#kill-postwork", () => {
        if (confirm("Are you sure you want to stop the operation?")) {
          this.shouldStop = true;
          $("#kill-postwork").prop("disabled", true).text("Stopping...");
        }
      });
    }

    handleFieldChange() {
      this.hasUnsavedChanges = true;
      this.updatePostWorkState();
    }

    handleTaxonomyChange(e) {
      const $checkbox = $(e.currentTarget);
      const taxName = $checkbox.val();
      const isEnabled = $checkbox.prop("checked");
      const $defaults = $checkbox
        .closest(".taxonomy-setting-row")
        .find(".taxonomy-defaults");

      // Show/hide default terms select
      $defaults.toggleClass("active", isEnabled);

      // Update all blocks with the new taxonomy field
      this.updateBlocksWithTaxonomy(taxName, isEnabled);
      this.handleFieldChange();
    }

    getTaxonomyObject(taxName) {
      const taxonomies = poststation.taxonomies || {};
      return taxonomies[taxName] || { name: taxName, label: taxName };
    }

    getDefaultTerms(taxName) {
      const $select = $(`.default-terms-select[data-taxonomy="${taxName}"]`);
      return $select.val() || [];
    }

    createTaxonomyField(taxonomy, defaultValues = []) {
      return $(`
        <tr>
          <th scope="row">
            <label>${taxonomy.label}</label>
          </th>
          <td>
            <input type="text" 
                   class="regular-text taxonomy-field" 
                   data-taxonomy="${taxonomy.name}"
                   value="${defaultValues.join(", ")}"
                   placeholder="Comma-separated ${taxonomy.label.toLowerCase()}">
          </td>
        </tr>
      `);
    }

    updatePostWorkState() {
      const $state = $(".postwork-state");
      const $stateMessage = $state.find(".state-message");
      const $stateIcon = $state.find(".dashicons");

      if (this.hasUnsavedChanges) {
        $state.removeClass("ready").addClass("saving-required");
        $stateMessage.text("Saving Required");
        $stateIcon
          .removeClass("dashicons-yes-alt")
          .addClass("dashicons-warning");
      } else {
        $state.removeClass("saving-required").addClass("ready");
        $stateMessage.text("Ready to Run");
        $stateIcon
          .removeClass("dashicons-warning")
          .addClass("dashicons-yes-alt");
      }
    }

    async handleSavePostWork() {
      const $button = $("#save-postwork");
      $button.prop("disabled", true);
      $(".loading-overlay").addClass("active");

      try {
        // Validate all blocks first
        const validationErrors = this.validateBlocks();
        if (validationErrors.length > 0) {
          throw new Error("Please fix the validation errors before saving.");
        }

        await this.saveAllBlocks();

        const postworkId = $("#postwork-id").val();
        const title = $("#postwork-title").val();
        const webhookId = $("#webhook-id").val();
        const postType = $("#post-type").val();
        const enabledTaxonomies = this.getEnabledTaxonomies();
        const defaultTerms = this.getAllDefaultTerms();
        const prompts = this.getAllPrompts();
        const customFields = this.getAllCustomFields();
        const postStatus = $("#post-status").val();
        const defaultAuthorId = $("#default-author-id").val();

        const response = await $.post(poststation.ajax_url, {
          action: "poststation_update_postwork",
          nonce: poststation.nonce,
          id: postworkId,
          title: title,
          webhook_id: webhookId,
          post_type: postType,
          post_status: postStatus,
          default_author_id: defaultAuthorId,
          enabled_taxonomies: JSON.stringify(enabledTaxonomies),
          default_terms: JSON.stringify(defaultTerms),
          prompts: JSON.stringify(prompts),
          custom_fields: JSON.stringify(customFields),
        });

        if (!response.success) {
          throw new Error(response.data || "Failed to save post work");
        }

        this.hasUnsavedChanges = false;
        this.updatePostWorkState();
        this.initialState = {
          title: title,
          webhook_id: webhookId,
          post_type: postType,
          enabled_taxonomies: enabledTaxonomies,
          default_terms: defaultTerms,
          prompts: prompts,
          custom_fields: customFields,
        };
      } catch (error) {
        console.error("Error saving post work:", error);
        alert(error.message);
      } finally {
        $button.prop("disabled", false);
        $(".loading-overlay").removeClass("active");
      }
    }

    validateBlocks() {
      const errors = [];
      $(".postblock").each((_, block) => {
        const $block = $(block);
        const blockId = $block.data("id");
        const blockErrors = [];

        // Reset previous errors
        $block.find(".error-message").removeClass("active").empty();
        $block.find(".has-error").removeClass("has-error");
        $block.find(".block-error-count").removeClass("active");
        $block.find(".error-count-text").empty();

        // Validate Article URL
        const $urlInput = $block.find(".article-url");
        const $urlCell = $urlInput.closest("td");
        const $urlError = $urlCell.find(".error-message");

        if (!$urlInput.val()) {
          blockErrors.push({
            field: "article_url",
            message: "Article URL is required",
          });
          $urlCell.addClass("has-error");
          $urlError.addClass("active").text("Article URL is required");
        } else if (!this.isValidUrl($urlInput.val())) {
          blockErrors.push({
            field: "article_url",
            message: "Please enter a valid URL",
          });
          $urlCell.addClass("has-error");
          $urlError.addClass("active").text("Please enter a valid URL");
        }

        // If block has errors, update the error count display
        if (blockErrors.length > 0) {
          errors.push(...blockErrors.map((err) => ({ ...err, blockId })));
          const $errorCount = $block.find(".block-error-count");
          const $errorText = $errorCount.find(".error-count-text");
          $errorCount.addClass("active");
          $errorText.text(
            `${blockErrors.length} ${
              blockErrors.length === 1 ? "error" : "errors"
            } to fix`
          );
        }
      });

      return errors;
    }

    isValidUrl(string) {
      try {
        new URL(string);
        return true;
      } catch (_) {
        return false;
      }
    }

    initStickyControls() {
      const $postworkActions = $(".postwork-actions");
      if (!$postworkActions.length) return;

      $postworkActions.after(
        '<div class="postwork-actions-placeholder"></div>'
      );
      const $placeholder = $(".postwork-actions-placeholder");
      const initialOffset = $postworkActions.offset().top - 32;

      $(window).on("scroll", () => {
        const scrollTop = $(window).scrollTop();
        if (scrollTop > initialOffset) {
          $postworkActions.addClass("sticky");
          $placeholder.addClass("active");
        } else {
          $postworkActions.removeClass("sticky");
          $placeholder.removeClass("active");
        }
      });

      $(window).on("resize", () => {
        if ($postworkActions.hasClass("sticky")) {
          const adminBarHeight = $("#wpadminbar").height();
          $postworkActions.css("top", adminBarHeight + "px");
        }
      });
    }

    // Post Work Handlers
    async handleAddNewPostWork() {
      const title = prompt("Enter post work title:");
      if (!title) return;

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_create_postwork",
          nonce: poststation.nonce,
          title: title,
        });

        if (response.success) {
          window.location.href = response.data.redirect_url;
        } else {
          alert(response.data);
        }
      } catch (error) {
        alert("Failed to create post work.");
      }
    }

    async handleDeletePostWork(e) {
      if (!confirm("Are you sure you want to delete this post work?")) return;

      const $button = $(e.currentTarget);
      const postworkId = $button.data("id");

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_delete_postwork",
          nonce: poststation.nonce,
          id: postworkId,
        });

        if (response.success) {
          $button.closest("tr").remove();
        } else {
          alert(response.data);
        }
      } catch (error) {
        alert("Failed to delete post work.");
      }
    }

    // Block Handlers
    async handleAddPostBlock() {
      const $button = $("#add-postblock");
      const postworkId = $("#postwork-id").val();

      $button.prop("disabled", true);

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_create_postblock",
          nonce: poststation.nonce,
          postwork_id: postworkId,
        });

        if (response.success) {
          const blockId = response.data.id;
          const $block = this.createBlockElement(blockId);
          $("#postblocks").append($block);

          // Switch filter to pending
          $("#block-status-filter").val("pending").trigger("change");

          setTimeout(() => {
            this.setBlockLoading($block, false);
            $block.find(".postblock-content").show();
            $block.find(".article-url").focus();
            this.scrollToBlock($block);
            this.updateStatusCounts();
          }, 500);

          // Remove no blocks message if it exists
          this.updateNoBlocksMessage(false);
          this.handleFieldChange();
        } else {
          alert(response.data);
        }
      } catch (error) {
        alert("Failed to create post block.");
      } finally {
        $button.prop("disabled", false);
      }
    }

    async handleDuplicateBlock(e) {
      e.stopPropagation();
      const $button = $(e.currentTarget);
      const $block = $button.closest(".postblock");
      const postworkId = $("#postwork-id").val();

      if (!postworkId) {
        alert("Invalid post work ID");
        return;
      }

      // Get block data before disabling UI
      const blockData = this.getBlockData($block);
      if (!blockData) {
        alert("Cannot duplicate block: Invalid block data");
        return;
      }

      // Disable UI and show loading
      $button.prop("disabled", true);
      this.setBlockLoading($block, true);

      try {
        // Create new block
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_create_postblock",
          nonce: poststation.nonce,
          postwork_id: postworkId,
        });

        if (!response.success) {
          throw new Error(response.data || "Failed to create new block");
        }

        const blockId = response.data.id;

        // Create and append new block element
        const $newBlock = $block.clone(true);
        this.updateBlockAfterDuplicate($newBlock, blockId);
        $("#postblocks").append($newBlock);

        // Update block data
        const updateResponse = await $.post(poststation.ajax_url, {
          action: "poststation_update_blocks",
          nonce: poststation.nonce,
          blocks: JSON.stringify([
            {
              id: blockId,
              ...blockData,
            },
          ]),
        });

        if (!updateResponse.success) {
          throw new Error(updateResponse.data || "Failed to update block data");
        }

        // Update UI
        this.setBlockLoading($newBlock, false);
        this.scrollToBlock($newBlock);
        this.updateStatusCounts();
        $("#block-status-filter").trigger("change");
        this.handleFieldChange();

        // Remove no blocks message if it exists
        this.updateNoBlocksMessage(false);
      } catch (error) {
        console.error("Block duplication error:", error);
        alert(error.message || "Failed to duplicate post block");
      } finally {
        // Reset UI state
        $button.prop("disabled", false);
        this.setBlockLoading($block, false);
      }
    }

    async handleDeleteBlock(e) {
      if (!confirm("Are you sure you want to delete this post block?")) return;

      const $button = $(e.currentTarget);
      const $block = $button.closest(".postblock");
      const blockId = $block.data("id");

      $button.prop("disabled", true);
      this.setBlockLoading($block, true);

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_delete_postblock",
          nonce: poststation.nonce,
          id: blockId,
        });

        if (response.success) {
          $block.fadeOut(400, () => {
            $block.remove();
            this.updateStatusCounts();
            const $remainingBlocks = $(".postblock");
            this.updateNoBlocksMessage($remainingBlocks.length === 0);
            $("#block-status-filter").trigger("change");
          });
        } else {
          alert(response.data);
          this.setBlockLoading($block, false);
        }
      } catch (error) {
        alert("Failed to delete post block.");
        this.setBlockLoading($block, false);
      } finally {
        $button.prop("disabled", false);
      }
    }

    async handleRunSingleBlock(e) {
      if (this.hasUnsavedChanges) {
        alert("Please save your changes before running the block.");
        return;
      }

      e.stopPropagation();
      const $button = $(e.currentTarget);
      const $block = $button.closest(".postblock");
      const postworkId = $("#postwork-id").val();
      const webhookId = $("#webhook-id").val();

      if (!webhookId) {
        alert("Please select a webhook first.");
        return;
      }

      $button.prop("disabled", true);
      await this.processBlock($block, postworkId, webhookId);
      $button.prop("disabled", false);
    }

    async handleRunPostWork() {
      if (this.hasUnsavedChanges) {
        alert("Please save your changes before running the post work.");
        return;
      }

      const $button = $("#run-postwork");
      const postworkId = $("#postwork-id").val();
      const webhookId = $("#webhook-id").val();

      if (!webhookId) {
        alert("Please select a webhook first.");
        return;
      }

      const $blocks = $(".postblock").filter(function () {
        const status = $(this).attr("data-status");
        return status !== "completed"; // Skip completed blocks
      });

      if (!$blocks.length) {
        alert("No blocks to process.");
        return;
      }

      // Update button state and add kill button
      $button.prop("disabled", true).text("Running...");
      const $killButton = $(`
        <button type="button" class="button button-secondary" id="kill-postwork">
          <span class="dashicons dashicons-dismiss"></span>
          Stop
        </button>
      `);
      $button.after($killButton);

      // Reset stop flag
      this.shouldStop = false;
      this.isRunning = true;

      // Switch to "all" filter to show all blocks
      $("#block-status-filter").val("all").trigger("change");

      try {
        for (const block of $blocks) {
          // Check if operation should be stopped
          if (this.shouldStop) {
            throw new Error("Operation stopped by user");
          }

          const $block = $(block);

          // Scroll to the block that's about to be processed
          this.scrollToBlock($block);

          try {
            const success = await this.processBlock(
              $block,
              postworkId,
              webhookId
            );
            if (!success) {
              console.error(`Block ${$block.data("id")} failed to process`);
              // Continue with next block instead of breaking
              continue;
            }

            // Wait for block to complete or fail before moving to next block
            await this.waitForBlockCompletion($block.data("id"));
          } catch (blockError) {
            console.error(
              `Error processing block ${$block.data("id")}:`,
              blockError
            );
            // Continue with next block instead of breaking
            continue;
          }
        }
      } catch (error) {
        console.error("Error processing blocks:", error);
        if (error.message !== "Operation stopped by user") {
          alert("An error occurred while processing blocks.");
        }
      } finally {
        this.isRunning = false;
        $button.prop("disabled", false).text("Run");
        $killButton.remove();
      }
    }

    async waitForBlockCompletion(blockId, timeout = 100000) {
      return new Promise((resolve, reject) => {
        const startTime = Date.now();
        const checkInterval = setInterval(async () => {
          // Check if operation was killed
          if (this.shouldStop) {
            clearInterval(checkInterval);
            const $block = $(`.postblock[data-id="${blockId}"]`);
            this.setBlockError($block, "Operation stopped by user");
            reject(new Error("Operation stopped by user"));
            return;
          }

          try {
            const response = await fetch(
              `${poststation.rest_url}poststation/v1/check-status?block_ids=${blockId}`,
              {
                method: "GET",
                headers: { "Content-Type": "application/json" },
              }
            );

            if (!response.ok) {
              throw new Error("Failed to check block status");
            }

            const statuses = await response.json();
            const blockStatus = statuses[blockId];
            const $block = $(`.postblock[data-id="${blockId}"]`);

            // Update UI based on status
            this.updateBlockStatusUI(
              $block,
              blockStatus.status,
              blockStatus.error_message || ""
            );

            // If completed, add edit link
            if (blockStatus.status === "completed" && blockStatus.post_id) {
              this.addEditLink($block, blockStatus.post_id);
              clearInterval(checkInterval);
              resolve(true);
            } else if (blockStatus.status === "failed") {
              clearInterval(checkInterval);
              resolve(false);
            } else if (Date.now() - startTime > timeout) {
              clearInterval(checkInterval);
              this.setBlockError($block, "Operation timed out");
              reject(new Error("Block processing timed out"));
            }
          } catch (error) {
            clearInterval(checkInterval);
            const $block = $(`.postblock[data-id="${blockId}"]`);
            this.setBlockError($block, error.message);
            reject(error);
          }
        }, this.STATUS_CHECK_INTERVAL);
      });
    }

    // Helper Methods
    setBlockLoading($block, loading) {
      $block.toggleClass("loading", loading);
    }

    scrollToBlock($block) {
      $("html, body").animate(
        {
          scrollTop: $block.offset().top - 50,
        },
        500
      );
    }

    updateBlockStatusUI($block, status, errorMessage = "") {
      const oldStatus = $block.attr("data-status");
      $block.attr("data-status", status);
      const $badge = $block.find(".block-status-badge");

      // Remove all status classes and add the new one
      $badge
        .removeClass("pending processing completed failed")
        .addClass(status)
        .text(status)
        .attr("title", errorMessage);

      // Show/hide run button based on status
      const $runButton = $block.find(".run-block");
      if (status === "failed") {
        if ($runButton.length === 0) {
          const $actions = $block.find(".postblock-header-actions");
          $actions.prepend(`
            <button type="button" class="button-link run-block" title="Run this block">
              <span class="dashicons dashicons-controls-play"></span>
            </button>
          `);
        }
      } else {
        $runButton.remove();
      }

      // Update counts if status changed
      if (oldStatus !== status) {
        this.updateStatusCounts();
        $("#block-status-filter").trigger("change");
      }
    }

    async updateBlockStatus(blockId, status, errorMessage = null) {
      const data = {
        action: "poststation_update_postblock",
        nonce: poststation.nonce,
        id: blockId,
        status: status,
      };

      if (errorMessage) {
        data.error_message = errorMessage;
      }

      await $.post(poststation.ajax_url, data);
    }

    setBlockError($block, errorMessage) {
      this.updateBlockStatusUI($block, "failed", errorMessage);
      this.updateBlockStatus($block.data("id"), "failed", errorMessage);
    }

    handleBlockHeaderClick(e) {
      if ($(e.target).closest(".postblock-header-actions").length) return;
      $(e.currentTarget).next(".postblock-content").slideToggle();
    }

    handleArticleUrlChange(e) {
      clearTimeout(this.urlUpdateTimeout);
      const $input = $(e.currentTarget);
      const $block = $input.closest(".postblock");

      this.urlUpdateTimeout = setTimeout(() => {
        const url = $input.val();
        try {
          const parsedUrl = new URL(url);
          $block
            .find(".block-url")
            .text(parsedUrl.hostname + parsedUrl.pathname)
            .attr("title", url);
        } catch (e) {
          $block.find(".block-url").text("").attr("title", "");
        }
      }, 300);
    }

    handlePostTypeChange(e) {
      const $select = $(e.currentTarget);
      $select.closest(".postblock").find(".block-type").text($select.val());
    }

    // Additional helper methods...
    createBlockElement(blockId) {
      const enabledTaxonomies = this.getEnabledTaxonomies();
      let taxonomyFields = "";

      // Get all taxonomies from WordPress data
      const taxonomies = poststation.taxonomies || {};

      // Create fields for enabled taxonomies
      Object.entries(enabledTaxonomies).forEach(([taxName, enabled]) => {
        if (!enabled) return;
        const taxonomy = taxonomies[taxName] || {
          name: taxName,
          label: taxName,
        };
        const defaultTerms = this.getDefaultTerms(taxName);
        taxonomyFields += `
            <div class="form-field">
                <label>${taxonomy.label}</label>
                <div class="field-input">
                    <input type="text" 
                           class="regular-text taxonomy-field" 
                           data-taxonomy="${taxonomy.name}"
                           value="${defaultTerms.join(", ")}"
                           placeholder="Comma-separated ${taxonomy.label.toLowerCase()}">
                </div>
            </div>
        `;
      });

      // Get global prompts for the new block
      const prompts = this.getAllPrompts();
      let promptsHtml = "";
      Object.entries(prompts).forEach(([key, prompt]) => {
        promptsHtml += `
            <div class="block-prompt-item" data-key="${key}">
                <div class="prompt-header">
                    <span class="prompt-title">${prompt.title}</span>
                </div>
                <div class="prompt-content">
                    <textarea class="prompt-textarea" 
                        placeholder="Enter your prompt content here...">${prompt.content}</textarea>
                </div>
            </div>
        `;
      });

      // Get custom fields
      const customFields = this.getAllCustomFields();
      let customFieldsHtml = "";
      Object.entries(customFields).forEach(([key, value]) => {
        customFieldsHtml += `
            <div class="form-field">
                <label>${key}</label>
                <div class="field-input">
                    <input type="text" 
                           class="regular-text custom-field-value-input" 
                           data-meta-key="${key}"
                           value="${value}"
                           placeholder="Custom field value">
                </div>
            </div>
        `;
      });

      return $(`
        <div class="postblock loading" data-id="${blockId}" data-status="pending">
            <div class="postblock-header">
                <div class="postblock-header-info">
                    <span class="block-id">#${blockId}</span>
                    <span class="block-url"></span>
                    <span class="block-error-count">
                        <span class="dashicons dashicons-warning"></span>
                        <span class="error-count-text"></span>
                    </span>
                </div>
                <div class="postblock-header-actions">
                    <span class="block-status-badge pending">pending</span>
                    <button type="button" class="button-link duplicate-postblock" title="Duplicate">
                        <span class="dashicons dashicons-admin-page"></span>
                    </button>
                    <button type="button" class="button-link delete-postblock" title="Delete">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>
            <div class="postblock-content" style="display: none;">
                <div class="postblock-form">
                    <!-- Left Column -->
                    <div class="postblock-column">
                        <div class="form-section">
                            <h3 class="section-title">Basic Information</h3>
                            <div class="form-field">
                                <label>Article URL</label>
                                <div class="field-input">
                                    <input type="url" class="regular-text article-url" required>
                                    <div class="error-message"></div>
                                </div>
                            </div>

                            <div class="form-field">
                                <label>Post Title</label>
                                <div class="field-input">
                                    <input type="text" class="regular-text post-title" placeholder="Enter post title">
                                </div>
                            </div>

                            <div class="form-field">
                                <label>Featured Image</label>
                                <div class="field-input feature-image-field">
                                    <div class="feature-image-preview" style="display: none;">
                                        <img src="" alt="">
                                        <button type="button" class="button remove-feature-image">
                                            <span class="dashicons dashicons-no"></span>
                                            Remove Image
                                        </button>
                                    </div>
                                    <div class="feature-image-upload">
                                        <input type="hidden" class="feature-image-id" name="feature_image_id" value="">
                                        <button type="button" class="button upload-feature-image">
                                            <span class="dashicons dashicons-upload"></span>
                                            Upload Image
                                        </button>
                                        <p class="description">Upload or select an image to use as the featured image.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3 class="section-title">Taxonomies</h3>
                            ${taxonomyFields}
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="postblock-column">
                        <div class="form-section">
                            <h3 class="section-title">Custom Fields</h3>
                            ${customFieldsHtml}
                        </div>

                        <div class="form-section">
                            <h3 class="section-title">AI Prompts</h3>
                            <div class="block-prompts-section collapsed">
                                <div class="block-prompts-header">
                                    <div class="block-prompts-title">
                                        <span class="dashicons dashicons-arrow-down block-prompts-toggle"></span>
                                        AI Prompts
                                        <span class="block-prompts-count">(${
                                          Object.keys(prompts).length
                                        })</span>
                                    </div>
                                </div>
                                <div class="block-prompts-content" style="display: none;">
                                    <div class="block-prompts-container">
                                        ${promptsHtml}
                                    </div>
                                    <p class="description">Block-specific prompts will override global prompts.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `);
    }

    getTaxonomyFields() {
      const enabledTaxonomies = this.getEnabledTaxonomies();
      return enabledTaxonomies
        .map(
          (tax) => `
        <tr>
          <th scope="row">
            <label>${tax.label}</label>
          </th>
          <td>
            <input type="text" 
                   class="regular-text taxonomy-field" 
                   data-taxonomy="${tax.name}"
                   placeholder="Comma-separated ${tax.label.toLowerCase()}">
          </td>
        </tr>
      `
        )
        .join("");
    }

    getEnabledTaxonomies() {
      const enabledTaxonomies = {};
      $(".taxonomy-checkbox").each((_, checkbox) => {
        const $checkbox = $(checkbox);
        const taxName = $checkbox.val();
        enabledTaxonomies[taxName] = $checkbox.prop("checked");
      });
      return enabledTaxonomies;
    }

    getBlockData($block) {
      const articleUrl = $block.find(".article-url").val();
      if (!articleUrl || !this.isValidUrl(articleUrl)) {
        return null;
      }

      const postTitle = $block.find(".post-title").val();

      // Collect taxonomy data
      const taxonomies = {};
      $block.find(".taxonomy-field").each((_, field) => {
        const $field = $(field);
        const taxName = $field.data("taxonomy");
        const values = $field
          .val()
          .split(",")
          .map((v) => v.trim())
          .filter(Boolean);
        if (values.length > 0) {
          taxonomies[taxName] = values;
        }
      });

      // Collect prompts data
      const prompts = {};
      $block.find(".block-prompt-item").each((_, item) => {
        const $item = $(item);
        const key = $item.data("key");
        const title = $item.find(".prompt-title").text().trim();
        const content = $item.find(".prompt-textarea").val().trim();

        prompts[key] = {
          title: title,
          content: content,
        };
      });

      // Collect custom fields data
      const customFields = {};
      $block.find(".custom-field-value-input").each((_, field) => {
        const $field = $(field);
        const metaKey = $field.data("meta-key");
        const value = $field.val().trim();
        customFields[metaKey] = value;
      });

      const featureImageId = $block.find(".feature-image-id").val();

      return {
        article_url: articleUrl,
        post_title: postTitle,
        taxonomies: JSON.stringify(taxonomies),
        prompts: JSON.stringify(prompts),
        custom_fields: JSON.stringify(customFields),
        feature_image_id: featureImageId,
      };
    }

    updateBlockAfterDuplicate($block, newId) {
      $block.attr("data-id", newId);
      $block.find(".block-id").text("#" + newId);

      // Reset status to pending
      $block.attr("data-status", "pending");
      const $badge = $block.find(".block-status-badge");
      $badge
        .removeClass("completed processing failed")
        .addClass("pending")
        .text("pending");

      // Remove edit link if exists
      $block.find(".block-edit-link").remove();

      // Remove run button if exists
      $block.find(".run-block").remove();
    }

    async updateBlockData(blockId, data) {
      return $.post(poststation.ajax_url, {
        action: "poststation_update_postblock",
        nonce: poststation.nonce,
        id: blockId,
        ...data,
      });
    }

    async saveAllBlocks() {
      const blocks = [];
      $(".postblock").each((_, block) => {
        const $block = $(block);
        // Skip completed blocks
        if ($block.attr("data-status") === "completed") {
          return;
        }
        const blockData = this.getBlockData($block);
        if (blockData) {
          blocks.push({
            id: $block.data("id"),
            ...blockData,
          });
        }
      });

      if (blocks.length === 0) return;

      // Send all blocks in a single request
      return $.post(poststation.ajax_url, {
        action: "poststation_update_blocks",
        nonce: poststation.nonce,
        blocks: JSON.stringify(blocks),
      });
    }

    async processBlock($block, postworkId, webhookId) {
      const blockId = $block.data("id");

      this.setBlockLoading($block, true);
      this.updateBlockStatusUI($block, "processing");

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_run_postwork",
          nonce: poststation.nonce,
          id: postworkId,
          block_id: blockId,
          webhook_id: webhookId,
        });

        if (!response.success) {
          throw new Error(response.data || "Failed to process block");
        }

        // Block has been sent for processing
        return true;
      } catch (error) {
        // Keep the block in failed state instead of reverting to pending
        this.setBlockError($block, error.message);
        return false;
      } finally {
        this.setBlockLoading($block, false);
      }
    }

    addEditLink($block, postId) {
      const $headerInfo = $block.find(".postblock-header-info");
      const $existingLink = $headerInfo.find(".block-edit-link");

      if ($existingLink.length === 0) {
        const editUrl = `${poststation.admin_url}post.php?post=${postId}&action=edit`;
        const $link = $(`
          <a href="${editUrl}" 
             class="block-edit-link" 
             target="_blank" 
             title="Edit Post">
            <span class="dashicons dashicons-edit"></span>
          </a>
        `);
        $headerInfo.append($link);
      }
    }

    handleStatusFilter(e) {
      const status = $(e.target).val();
      const $blocks = $(".postblock");
      let visibleBlocks = 0;

      if (status === "all") {
        $blocks.show();
        visibleBlocks = $blocks.length;
      } else {
        $blocks.each((_, block) => {
          const $block = $(block);
          const blockStatus = $block.attr("data-status") || "pending";
          const isVisible = blockStatus === status;
          $block.toggle(isVisible);
          if (isVisible) visibleBlocks++;
        });
      }

      this.updateNoBlocksMessage(visibleBlocks === 0, status);
    }

    initSelect2() {
      $(".default-terms-select")
        .select2({
          width: "100%",
          closeOnSelect: false,
          dropdownParent: $(".taxonomy-panel"),
          templateSelection: function (data) {
            if (!data.id) return data.text;
            return $("<span>").text(data.text).attr("title", data.text);
          },
        })
        .on("change", () => {
          this.handleFieldChange();
        });
    }

    getAllDefaultTerms() {
      const defaultTerms = {};
      $(".default-terms-select").each((_, select) => {
        const $select = $(select);
        const taxName = $select.data("taxonomy");
        const terms = $select.val() || [];
        if (terms.length > 0) {
          defaultTerms[taxName] = terms;
        }
      });
      return defaultTerms;
    }

    updateStatusCounts() {
      const counts = {
        all: 0,
        pending: 0,
        processing: 0,
        completed: 0,
        failed: 0,
      };

      // Count blocks by status
      $(".postblock").each((_, block) => {
        const status = $(block).attr("data-status") || "pending";
        counts[status]++;
        counts.all++;
      });
      // Update the counts in the filter options
      Object.entries(counts).forEach(([status, count]) => {
        // Find the span within the option that matches the status
        $(`#block-status-filter option[value="${status}"]`)
          .contents()
          .filter(function () {
            return this.nodeType === 3; // Text node
          })
          .replaceWith(
            `${
              status === "all"
                ? "All Blocks"
                : status.charAt(0).toUpperCase() + status.slice(1)
            } (${count})`
          );
      });
    }

    handleTitleEdit() {
      const $display = $("#title-display");
      const $input = $("#postwork-title");
      const $text = $display.find(".title-text");

      $text.hide();
      $input.show().focus();
      $display.addClass("editing");
    }

    handleTitleSave() {
      const $display = $("#title-display");
      const $input = $("#postwork-title");
      const $text = $display.find(".title-text");

      $text.text($input.val()).show();
      $input.hide();
      $display.removeClass("editing");
      this.handleFieldChange();
    }

    openSidePanel(panelClass) {
      $(`.side-panel.${panelClass}, .side-panel-overlay`).addClass("active");
      $("body").css("overflow", "hidden");
    }

    closeSidePanel(panelClass) {
      $(`.side-panel.${panelClass}, .side-panel-overlay`).removeClass("active");
      $("body").css("overflow", "");
    }

    addNewPrompt() {
      const $promptsContent = $(".prompts-panel .side-panel-content");
      const $lastPrompt = $promptsContent.find(".prompt-item").last();

      // Generate a unique key based on the title
      const timestamp = new Date().getTime();
      const defaultKey = `custom_prompt_${timestamp}`;

      const $newPrompt = $(`
          <div class="prompt-item" data-key="${defaultKey}">
              <div class="prompt-header">
                  <input type="text" class="regular-text prompt-title-input" 
                         placeholder="${wp.i18n.__(
                           "Prompt Title",
                           "poststation"
                         )}">
                  <div class="prompt-actions">
                      <span class="prompt-delete dashicons dashicons-trash" 
                            title="${wp.i18n.__(
                              "Delete Prompt",
                              "poststation"
                            )}"></span>
                  </div>
              </div>
              <div class="prompt-content">
                  <textarea class="prompt-textarea" 
                            placeholder="${wp.i18n.__(
                              "Enter your prompt content here...",
                              "poststation"
                            )}"></textarea>
              </div>
          </div>
      `);

      // Add after the last prompt
      if ($lastPrompt.length) {
        $lastPrompt.after($newPrompt);
      } else {
        $promptsContent.append($newPrompt);
      }

      // Add click handler directly to the new delete button
      $newPrompt.find(".prompt-delete").on("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.deletePrompt(e);
      });

      $newPrompt.find(".prompt-title-input").focus();
      this.handleFieldChange();
    }

    deletePrompt(e) {
      const $prompt = $(e.currentTarget).closest(".prompt-item");
      const key = $prompt.data("key");

      // Don't allow deletion of default prompts
      if (["post_title", "post_content", "thumbnail"].includes(key)) {
        return;
      }

      // Add confirmation
      if (!confirm("Are you sure you want to delete this prompt?")) {
        return;
      }

      $prompt.fadeOut(300, () => {
        $prompt.remove();
        this.handleFieldChange();
      });
    }

    getAllPrompts() {
      const prompts = {};
      $(".prompts-panel .prompt-item").each((_, item) => {
        const $item = $(item);
        const key = $item.data("key");
        const title = $item.find(".prompt-title-input").val().trim();
        const content = $item.find(".prompt-textarea").val().trim();

        if (title && content) {
          prompts[key] = {
            title: title,
            content: content,
          };
        }
      });

      // Update all blocks with the new prompts
      this.updateBlocksPrompts(prompts);

      return prompts;
    }

    // Add this method to handle title input changes
    handlePromptTitleChange(e) {
      const $input = $(e.currentTarget);
      const $prompt = $input.closest(".prompt-item");
      const oldKey = $prompt.data("key");

      // Don't allow changing default prompt titles
      if (["post_title", "post_content", "thumbnail"].includes(oldKey)) {
        return;
      }

      const newKey = $input
        .val()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_");
      if (newKey) {
        $prompt.attr("data-key", newKey);
      }
      this.handleFieldChange();
    }

    createBlockPrompts() {
      // Get global prompts as default
      const globalPrompts = this.getAllPrompts();
      const promptCount = Object.keys(globalPrompts).length;

      return `
          <tr>
              <th scope="row">
                  <label>Prompts</label>
              </th>
              <td>
                  <div class="block-prompts-section">
                      <div class="block-prompts-header">
                          <div class="block-prompts-title">
                              <span class="dashicons dashicons-arrow-down block-prompts-toggle"></span>
                              AI Prompts
                              <span class="block-prompts-count">(${promptCount})</span>
                          </div>
                      </div>
                      <div class="block-prompts-content">
                          <div class="block-prompts-container">
                              ${this.renderBlockPrompts(globalPrompts)}
                          </div>
                          <button type="button" class="button add-block-prompt">
                              <span class="dashicons dashicons-plus-alt2"></span>
                              Add New Prompt
                          </button>
                          <p class="description">Block-specific prompts will override global prompts.</p>
                      </div>
                  </div>
              </td>
          </tr>
      `;
    }

    renderBlockPrompts(prompts) {
      return Object.entries(prompts)
        .map(
          ([key, prompt]) => `
          <div class="block-prompt-item" data-key="${key}">
              <div class="prompt-header">
                  <span class="prompt-title">${prompt.title}</span>
              </div>
              <div class="prompt-content">
                  <textarea class="prompt-textarea" 
                            placeholder="Enter your prompt content here...">${prompt.content}</textarea>
              </div>
          </div>
      `
        )
        .join("");
    }

    // Update the createNewBlock method
    createNewBlock() {
      const taxonomyFields = this.getTaxonomyFields();
      const promptFields = this.createBlockPrompts();

      return $(`
          <div class="postblock" data-status="pending">
              <!-- ... existing header code ... -->
              <div class="postblock-content" style="display: none;">
                  <table class="form-table">
                      <tr>
                          <th scope="row">
                              <label>Article URL</label>
                          </th>
                          <td>
                              <input type="url" class="regular-text article-url" required>
                              <div class="error-message"></div>
                          </td>
                      </tr>
                      <tr>
                          <th scope="row">
                              <label>Featured Image</label>
                          </th>
                          <td class="feature-image-field">
                              <div class="feature-image-preview" style="display: none;">
                                  <img src="" alt="">
                                  <button type="button" class="button remove-feature-image">
                                      <span class="dashicons dashicons-no"></span>
                                      Remove Image
                                  </button>
                              </div>
                              <div class="feature-image-upload">
                                  <input type="hidden" class="feature-image-id" name="feature_image_id" value="">
                                  <button type="button" class="button upload-feature-image">
                                      <span class="dashicons dashicons-upload"></span>
                                      Upload Image
                                  </button>
                                  <p class="description">Upload or select an image to use as the featured image.</p>
                              </div>
                          </td>
                      </tr>
                      ${taxonomyFields}
                      ${promptFields}
                  </table>
              </div>
          </div>
      `);
    }

    addBlockPrompt(e) {
      const $button = $(e.currentTarget);
      const $container = $button.siblings(".block-prompts-container");
      const key = "custom_" + Date.now();

      const $newPrompt = $(`
          <div class="block-prompt-item" data-key="${key}">
              <div class="prompt-header">
                  <input type="text" class="regular-text prompt-title-input" 
                         placeholder="Prompt Title">
                  <div class="prompt-actions">
                      <span class="prompt-delete dashicons dashicons-trash" 
                            title="Delete Prompt"></span>
                  </div>
              </div>
              <div class="prompt-content">
                  <textarea class="prompt-textarea" 
                            placeholder="Enter your prompt content here..."></textarea>
              </div>
          </div>
      `);

      $container.append($newPrompt);

      // Scroll to the new prompt
      $container.animate(
        {
          scrollTop: $container[0].scrollHeight,
        },
        300
      );

      // Focus on the title input
      $newPrompt.find(".prompt-title-input").focus();

      this.handleFieldChange();

      // Trigger prompt added event for counter update
      $newPrompt.closest(".block-prompts-section").trigger("prompt:added");
    }

    deleteBlockPrompt(e) {
      e.preventDefault();
      e.stopPropagation();

      const $prompt = $(e.currentTarget).closest(".block-prompt-item");
      const $section = $prompt.closest(".block-prompts-section");
      const key = $prompt.data("key");

      // Don't allow deletion of core prompts
      if (["post_title", "post_content"].includes(key)) {
        return;
      }

      if (!confirm("Are you sure you want to delete this prompt?")) {
        return;
      }

      // Use fadeOut with a callback to ensure the element is properly removed
      $prompt.fadeOut(
        300,
        function () {
          $(this).remove();

          // Update the prompt count
          const promptCount = $section.find(".block-prompt-item").length;
          $section.find(".block-prompts-count").text(`(${promptCount})`);

          // Trigger state changes
          $section.trigger("prompt:removed");
          this.handleFieldChange();
        }.bind(this)
      ); // Bind 'this' to access the class methods
    }

    addNewCustomField() {
      const $container = $(".custom-fields-container");
      const timestamp = new Date().getTime();

      const $newField = $(`
          <div class="custom-field-item">
              <div class="custom-field-header">
                  <div class="custom-field-key">
                      <input type="text" 
                             class="regular-text custom-field-key-input" 
                             placeholder="Meta Key">
                      <div class="error-message"></div>
                  </div>
                  <div class="custom-field-actions">
                      <span class="custom-field-delete dashicons dashicons-trash" 
                            title="Delete Field"></span>
                  </div>
              </div>
              <div class="custom-field-content">
                  <textarea class="custom-field-value" 
                            placeholder="Default Value"></textarea>
              </div>
          </div>
      `);

      $container.append($newField);
      $newField.find(".custom-field-key-input").focus();
      this.handleFieldChange();

      // Update blocks when a new custom field is added
      this.updateBlocksCustomFields();
    }

    deleteCustomField(e) {
      e.preventDefault();
      e.stopPropagation();

      const $field = $(e.currentTarget).closest(".custom-field-item");
      const metaKey = $field.find(".custom-field-key-input").val().trim();

      if (
        !confirm(
          `Are you sure you want to delete the custom field "${metaKey}"? This will remove it from all blocks.`
        )
      ) {
        return;
      }

      // Remove the field from all blocks
      $(`.custom-field-value-input[data-meta-key="${metaKey}"]`)
        .closest("tr")
        .fadeOut(300, function () {
          $(this).remove();
        });

      // Remove the field from the custom fields panel
      $field.fadeOut(
        300,
        function () {
          $(this).remove();
          this.handleFieldChange();
          this.updateBlocksCustomFields();
        }.bind(this)
      ); // Bind 'this' to access the class methods
    }

    validateCustomFieldKey(e) {
      const $input = $(e.currentTarget);
      const $keyWrapper = $input.closest(".custom-field-key");
      const $errorMessage = $keyWrapper.find(".error-message");
      const value = $input.val().trim();

      // Reset error state
      $keyWrapper.removeClass("has-error");
      $errorMessage.empty();

      // Check for empty value
      if (!value) {
        $keyWrapper.addClass("has-error");
        $errorMessage.text("Meta key is required");
        return false;
      }

      // Check for valid format (letters, numbers, and underscores/hyphens only)
      if (!/^[a-z0-9_-]+$/i.test(value)) {
        $keyWrapper.addClass("has-error");
        $errorMessage.text(
          "Only letters, numbers, underscores, and hyphens are allowed"
        );
        return false;
      }

      // Check for uniqueness
      const isDuplicate =
        $(".custom-field-key-input")
          .not($input)
          .filter(function () {
            return $(this).val().trim() === value;
          }).length > 0;

      if (isDuplicate) {
        $keyWrapper.addClass("has-error");
        $errorMessage.text("This meta key already exists");
        return false;
      }

      return true;
    }

    getAllCustomFields() {
      const customFields = {};
      $(".custom-field-item").each((_, item) => {
        const $item = $(item);
        const key = $item.find(".custom-field-key-input").val().trim();
        const value = $item.find(".custom-field-value").val().trim();

        if (key && !$item.find(".custom-field-key").hasClass("has-error")) {
          customFields[key] = value;
        }
      });
      return customFields;
    }

    updateBlocksCustomFields() {
      const customFields = this.getAllCustomFields();

      $(".postblock").each((_, block) => {
        const $block = $(block);
        const $customFieldsSection = $block.find(".form-section").eq(2); // Custom Fields section

        // Store existing values
        const existingValues = {};
        $block.find(".custom-field-value-input").each((_, input) => {
          const $input = $(input);
          existingValues[$input.data("meta-key")] = $input.val();
        });

        // Remove existing custom fields
        $customFieldsSection.find(".form-field").remove();

        // Add updated custom fields
        Object.entries(customFields).forEach(([key, defaultValue]) => {
          // Use existing value if available, otherwise use default
          const value =
            existingValues[key] !== undefined
              ? existingValues[key]
              : defaultValue;

          const newField = `
                <div class="form-field">
                    <label>${key}</label>
                    <div class="field-input">
                        <input type="text" 
                               class="regular-text custom-field-value-input" 
                               data-meta-key="${key}"
                               value="${value}"
                               placeholder="Custom field value">
                    </div>
                </div>
            `;
          $customFieldsSection.append(newField);
        });
      });
    }

    handleFeatureImageUpload(e) {
      e.preventDefault();
      const $button = $(e.currentTarget);
      const $block = $button.closest(".postblock");

      // Create a new media frame each time
      const mediaFrame = wp.media({
        title: "Select or Upload Featured Image",
        button: {
          text: "Use this image",
        },
        multiple: false,
      });

      // Handle selection
      mediaFrame.on("select", () => {
        const attachment = mediaFrame.state().get("selection").first().toJSON();
        this.setFeatureImage($block, attachment);
        this.handleFieldChange();
      });

      mediaFrame.open();
    }

    handleFeatureImageRemove(e) {
      e.preventDefault();
      const $block = $(e.currentTarget).closest(".postblock");
      this.removeFeatureImage($block);
      this.handleFieldChange();
    }

    setFeatureImage($block, attachment) {
      const $preview = $block.find(".feature-image-preview");
      const $upload = $block.find(".feature-image-upload");
      const $input = $block.find(".feature-image-id");
      const $img = $preview.find("img");

      $img.attr(
        "src",
        attachment.sizes.thumbnail
          ? attachment.sizes.thumbnail.url
          : attachment.url
      );
      $input.val(attachment.id);
      $preview.show();
      $upload.hide();
    }

    removeFeatureImage($block) {
      const $preview = $block.find(".feature-image-preview");
      const $upload = $block.find(".feature-image-upload");
      const $input = $block.find(".feature-image-id");
      const $img = $preview.find("img");

      $img.attr("src", "");
      $input.val("");
      $preview.hide();
      $upload.show();
    }

    updateBlocksWithTaxonomy(taxName, isEnabled) {
      $(".postblock").each((_, block) => {
        const $block = $(block);
        const $taxonomySection = $block.find(".form-section").eq(1); // Taxonomies section
        const taxonomy = this.getTaxonomyObject(taxName);

        if (isEnabled) {
          // Add taxonomy field with default values
          const defaultTerms = this.getDefaultTerms(taxName);
          const newField = `
                <div class="form-field">
                    <label>${taxonomy.label}</label>
                    <div class="field-input">
                        <input type="text" 
                               class="regular-text taxonomy-field" 
                               data-taxonomy="${taxonomy.name}"
                               value="${defaultTerms.join(", ")}"
                               placeholder="Comma-separated ${taxonomy.label.toLowerCase()}">
                    </div>
                </div>
            `;
          $taxonomySection.append(newField);
        } else {
          // Remove taxonomy field
          $block
            .find(`[data-taxonomy="${taxName}"]`)
            .closest(".form-field")
            .remove();
        }
      });
    }

    updateBlocksPrompts(prompts) {
      $(".postblock").each((_, block) => {
        const $block = $(block);
        const $promptsContainer = $block.find(".block-prompts-container");

        // Clear existing prompts
        $promptsContainer.empty();

        // Add updated prompts
        Object.entries(prompts).forEach(([key, prompt]) => {
          const promptHtml = `
                <div class="block-prompt-item" data-key="${key}">
                    <div class="prompt-header">
                        <span class="prompt-title">${prompt.title}</span>
                    </div>
                    <div class="prompt-content">
                        <textarea class="prompt-textarea" 
                            placeholder="Enter your prompt content here...">${prompt.content}</textarea>
                    </div>
                </div>
            `;
          $promptsContainer.append(promptHtml);
        });

        // Update prompt count
        const promptCount = Object.keys(prompts).length;
        $block.find(".block-prompts-count").text(`(${promptCount})`);
      });
    }

    showApiFormat() {
      // Generate the format based on current configuration
      const format = this.generateApiFormat();
      this.$apiFormatCode.text(JSON.stringify(format, null, 2));
      this.$apiFormatModal.addClass("active");
      $("body").css("overflow", "hidden");
    }

    hideApiFormat() {
      this.$apiFormatModal.removeClass("active");
      $("body").css("overflow", "");
    }

    generateApiFormat() {
      const postType = $("#post-type").val();
      const enabledTaxonomies = this.getEnabledTaxonomies();
      const customFields = this.getAllCustomFields();

      // Create example format
      const format = {
        block_id: 123, // Required
        title: "Example Post Title", // Required (AI-generated if not provided in block)
        content: "<p>Optional post content goes here...</p>", // Optional
        thumbnail_url: "https://example.com/image.jpg", // Optional
        taxonomies: {}, // Optional
        custom_fields: {}, // Optional
      };

      // Add example taxonomy terms for enabled taxonomies
      Object.keys(enabledTaxonomies).forEach((taxonomy) => {
        if (enabledTaxonomies[taxonomy]) {
          format.taxonomies[taxonomy] = ["Example Term 1", "Example Term 2"];
        }
      });

      // Add example custom fields
      Object.keys(customFields).forEach((key) => {
        format.custom_fields[key] = "Example value for " + key;
      });

      return format;
    }

    copyApiFormat() {
      const code = this.$apiFormatCode.text();
      navigator.clipboard.writeText(code).then(() => {
        const $button = $(".copy-format");
        const originalText = $button.find(".button-text").text();

        $button.find(".button-text").text("Copied!");
        setTimeout(() => {
          $button.find(".button-text").text(originalText);
        }, 2000);
      });
    }

    initExportImport() {
      // Export handler
      $(document).on("click", ".export-postwork", (e) =>
        this.handleExportPostWork(e)
      );

      // Import handlers
      $("#import-postwork").on("click", () => $("#import-file").click());
      $("#import-file").on("change", (e) => this.handleImportPostWork(e));
    }

    async handleExportPostWork(e) {
      e.preventDefault();
      const $button = $(e.currentTarget);
      const postworkId = $button.data("id");

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_export_postwork",
          nonce: poststation.nonce,
          id: postworkId,
          include_blocks: true,
          exclude_statuses: ["completed", "failed"],
        });

        if (!response.success) {
          throw new Error(response.data);
        }

        // Create and download file
        const blob = new Blob([JSON.stringify(response.data, null, 2)], {
          type: "application/json",
        });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        const postworkTitle = response.data.postwork.title
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, "-");

        a.href = url;
        a.download = `postwork-${postworkTitle}-${
          new Date().toISOString().split("T")[0]
        }.json`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
      } catch (error) {
        alert(error.message || "Failed to export post work");
      }
    }

    async handleImportPostWork(e) {
      const file = e.target.files[0];
      if (!file) return;

      const reader = new FileReader();
      reader.onload = async (event) => {
        try {
          const importData = JSON.parse(event.target.result);

          const response = await $.post(poststation.ajax_url, {
            action: "poststation_import_postwork",
            nonce: poststation.nonce,
            import_data: JSON.stringify(importData),
          });

          if (!response.success) {
            throw new Error(response.data);
          }

          // Redirect to the new postwork
          window.location.href = response.data.redirect_url;
        } catch (error) {
          alert(error.message || "Failed to import post work");
        }
      };

      reader.readAsText(file);
      e.target.value = ""; // Reset file input
    }
  }

  // Initialize on document ready
  $(document).ready(() => new PostWork());
})(jQuery);
