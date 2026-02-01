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
        instructions: $("#postwork-instructions").val(),
        image_config: this.getImageConfig(),
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

      // Validate blocks and update state based on validation results
      const validationErrors = this.validateBlocks();
      if (validationErrors.length > 0) {
        this.hasUnsavedChanges = true;
      }

      this.updatePostWorkState();
      this.updateStatusCounts();
      this.sortBlocks();

      // Check initial blocks state and show message if needed
      const $blocks = $(".postblock");
      this.updateNoBlocksMessage($blocks.length === 0);

      // Check for stale processing blocks on page load
      this.checkStaleProcessingBlocks();

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
      $("#clear-completed-blocks").on("click", () =>
        this.handleClearCompletedBlocks()
      );
      $("#run-postwork").on("click", () => this.handleRunPostWork());

      // Post Work Field Changes
      $("#postwork-title").on("input", () => this.handleFieldChange());
      $("#postwork-instructions").on("input", () => this.handleFieldChange());
      $("#webhook-id").on("change", () => this.handleFieldChange());
      $("#post-type").on("change", () => this.handleFieldChange());
      $("#tone-of-voice").on("change", () => {
        const $select = $("#tone-of-voice");
        const $text = $select.siblings(".tone-of-voice-text");
        $text.text($select.find("option:selected").text());
        this.handleFieldChange();
      });
      $("#point-of-view").on("change", () => {
        const $select = $("#point-of-view");
        const $text = $select.siblings(".point-of-view-text");
        $text.text($select.find("option:selected").text());
        this.handleFieldChange();
      });
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
      $(document).on("input", ".keyword", (e) => {
        this.handleKeywordChange(e);
        this.handleFieldChange();
      });
      $(document).on("change", ".tone-of-voice", () =>
        this.handleFieldChange()
      );
      $(document).on("change", ".point-of-view", () =>
        this.handleFieldChange()
      );
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

      // Post Fields Panel
      $("#post-fields-trigger").on("click", () =>
        this.openSidePanel("post-fields-panel")
      );
      $(".add-post-field-button").on("click", () => this.addNewPostField());

      // Image Config Panel
      $("#image-config-trigger").on("click", () =>
        this.openSidePanel("image-config-panel")
      );
      this.initImageConfigHandlers();

      // Custom field actions
      $(".post-fields-container").on("click", ".post-field-delete", (e) =>
        this.deletePostField(e)
      );

      $(document).on("input", ".post-field-key-input", (e) => {
        const isValid = this.validatePostFieldKey(e);
        if (isValid) {
          this.updateBlocksPostFields();
        }
        this.handleFieldChange();
      });

      $(document).on("input", ".post-field-value", (e) => {
        const $input = $(e.target);
        const $item = $input.closest(".post-field-item");
        const key = $item.find(".post-field-key-input").val();

        if (key === "slug") {
          const value = $input.val();
          const slug = value
            .toLowerCase()
            .replace(/\s+/g, "-")
            .replace(/[^\w-]+/g, "");
          $input.val(slug);
        }

        this.updateBlocksPostFields();
        this.handleFieldChange();
      });

      // Block post field value changes
      $(document).on("input", ".post-field-value-input", (e) => {
        const $input = $(e.target);
        if ($input.data("key") === "slug") {
          const value = $input.val();
          const slug = value
            .toLowerCase()
            .replace(/\s+/g, "-")
            .replace(/[^\w-]+/g, "");
          $input.val(slug);
        }
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

      // Import Blocks Modal
      $("#import-blocks-trigger").on("click", () =>
        this.showImportBlocksModal()
      );
      $(".import-blocks-modal .modal-close").on("click", () =>
        this.hideImportBlocksModal()
      );
      $(".copy-sample-json").on("click", () => this.copyImportSample());
      $("#process-import-blocks").on("click", () => this.handleImportBlocks());

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

      // Collapse post work options if active
      const $options = $(".postwork-header");
      if ($options.hasClass("active")) {
        $(".toggle-options").trigger("click");
      }

      // Collapse all blocks
      $(".postblock-content").slideUp();

      try {
        // Validate all blocks first
        const validationErrors = this.validateBlocks();
        if (validationErrors.length > 0) {
          throw new Error("Please fix the validation errors before saving.");
        }

        await this.saveAllBlocks();

        const postworkId = $("#postwork-id").val();
        const title = $("#postwork-title").val();
        const instructions = $("#postwork-instructions").val();
        const webhookId = $("#webhook-id").val();
        const postType = $("#post-type").val();
        const enabledTaxonomies = this.getEnabledTaxonomies();
        const defaultTerms = this.getAllDefaultTerms();
        const postFields = this.getAllPostFields();
        const postStatus = $("#post-status").val();
        const defaultAuthorId = $("#default-author-id").val();
        const toneOfVoice = $("#tone-of-voice").val();
        const pointOfView = $("#point-of-view").val();

        const imageConfig = this.getImageConfig();

        const response = await $.post(poststation.ajax_url, {
          action: "poststation_update_postwork",
          nonce: poststation.nonce,
          id: postworkId,
          title: title,
          instructions: instructions,
          webhook_id: webhookId,
          post_type: postType,
          post_status: postStatus,
          default_author_id: defaultAuthorId,
          tone_of_voice: toneOfVoice,
          point_of_view: pointOfView,
          enabled_taxonomies: JSON.stringify(enabledTaxonomies),
          default_terms: JSON.stringify(defaultTerms),
          post_fields: JSON.stringify(postFields),
          image_config: JSON.stringify(imageConfig),
        });

        if (!response.success) {
          throw new Error(response.data || "Failed to save post work");
        }

        this.hasUnsavedChanges = false;
        this.updatePostWorkState();
        this.initialState = {
          title: title,
          instructions: instructions,
          webhook_id: webhookId,
          post_type: postType,
          tone_of_voice: toneOfVoice,
          point_of_view: pointOfView,
          enabled_taxonomies: enabledTaxonomies,
          default_terms: defaultTerms,
          post_fields: postFields,
          image_config: imageConfig,
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
        const $fieldInput = $urlInput.closest(".field-input");
        const $errorMessage = $fieldInput.find(".error-message");

        if ($urlInput.val() && !this.isValidUrl($urlInput.val())) {
          blockErrors.push({
            field: "article_url",
            message: "Please enter a valid URL",
          });
          $fieldInput.addClass("has-error");
          $errorMessage.addClass("active").text("Please enter a valid URL");
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
          this.sortBlocks();

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
        this.sortBlocks();

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

            // If completed, add edit and preview links
            if (blockStatus.status === "completed" && blockStatus.post_id) {
              this.addPostLinks(
                $block,
                blockStatus.post_id,
                blockStatus.post_url
              );
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
        .attr("title", errorMessage);

      if (status === "processing") {
        $badge.html(
          '<span class="dashicons dashicons-update rotating"></span> processing'
        );
      } else {
        $badge.text(status);
      }

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
        this.sortBlocks();
      }
    }

    sortBlocks() {
      const $container = $("#postblocks");
      const $blocks = $container.children(".postblock").get();
      const statusOrder = ["processing", "pending", "failed", "completed"];

      $blocks.sort((a, b) => {
        const statusA = $(a).attr("data-status") || "pending";
        const statusB = $(b).attr("data-status") || "pending";

        const orderA = statusOrder.indexOf(statusA);
        const orderB = statusOrder.indexOf(statusB);

        if (orderA !== orderB) {
          return orderA - orderB;
        }

        // Secondary sort by ID descending (most recent first)
        const idA = parseInt($(a).data("id"));
        const idB = parseInt($(b).data("id"));
        return idB - idA;
      });

      $.each($blocks, (index, block) => {
        $container.append(block);
      });

      // Re-apply filter to ensure correct visibility
      $("#block-status-filter").trigger("change");
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

    async checkStaleProcessingBlocks() {
      // Find all blocks with "processing" status
      const $processingBlocks = $(".postblock[data-status='processing']");

      if ($processingBlocks.length === 0) {
        return;
      }

      // First, check for blocks with error messages in their badge title
      // These are likely failed blocks that weren't properly updated
      $processingBlocks.each((_, block) => {
        const $block = $(block);
        const $badge = $block.find(".block-status-badge");
        const errorMessage = $badge.attr("title");

        // If block has processing status but has an error message, it's likely failed
        if (errorMessage && errorMessage.trim() !== "") {
          // Update to failed status immediately
          this.updateBlockStatusUI($block, "failed", errorMessage);
          this.updateBlockStatus($block.data("id"), "failed", errorMessage);
        }
      });

      // Get remaining processing block IDs (after filtering out those with errors)
      const $remainingProcessingBlocks = $(
        ".postblock[data-status='processing']"
      );
      if ($remainingProcessingBlocks.length === 0) {
        return;
      }

      const blockIds = $remainingProcessingBlocks
        .map((_, block) => $(block).data("id"))
        .get();

      if (blockIds.length === 0) {
        return;
      }

      try {
        // Check actual status from API
        const response = await fetch(
          `${
            poststation.rest_url
          }poststation/v1/check-status?block_ids=${blockIds.join(",")}`,
          {
            method: "GET",
            headers: { "Content-Type": "application/json" },
          }
        );

        if (!response.ok) {
          return;
        }

        const statuses = await response.json();

        // Update each block's UI if status doesn't match
        $remainingProcessingBlocks.each((_, block) => {
          const $block = $(block);
          const blockId = $block.data("id");
          const blockStatus = statuses[blockId];

          if (blockStatus && blockStatus.status !== "processing") {
            // Block is not actually processing, update UI
            this.updateBlockStatusUI(
              $block,
              blockStatus.status,
              blockStatus.error_message || ""
            );

            // If completed, add post links
            if (blockStatus.status === "completed" && blockStatus.post_id) {
              this.addPostLinks(
                $block,
                blockStatus.post_id,
                blockStatus.post_url
              );
            }
          } else if (!blockStatus) {
            // If API doesn't return status, assume it's not processing and reset to pending
            this.updateBlockStatusUI($block, "pending", "");
            this.updateBlockStatus($block.data("id"), "pending", null);
          }
        });
      } catch (error) {
        console.error("Error checking stale processing blocks:", error);
      }
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
        const $blockUrl = $block.find(".block-url");

        if (url) {
          try {
            const parsedUrl = new URL(url);
            $blockUrl
              .text(parsedUrl.hostname + parsedUrl.pathname)
              .attr("title", url);
          } catch (e) {
            $blockUrl.text("").attr("title", "");
          }
        } else {
          // If URL is empty, try to show keyword instead
          const keyword = $block.find(".keyword").val();
          if (keyword) {
            $blockUrl.text(keyword).attr("title", keyword);
          } else {
            $blockUrl.text("Empty block").attr("title", "");
          }
        }
      }, 300);
    }

    handleKeywordChange(e) {
      const $input = $(e.currentTarget);
      const $block = $input.closest(".postblock");
      const url = $block.find(".article-url").val();

      // Only update header if URL is empty
      if (!url) {
        const keyword = $input.val();
        const $blockUrl = $block.find(".block-url");
        if (keyword) {
          $blockUrl.text(keyword).attr("title", keyword);
        } else {
          $blockUrl.text("Empty block").attr("title", "");
        }
      }
    }

    handlePostTypeChange(e) {
      const $select = $(e.currentTarget);
      $select.closest(".postblock").find(".block-type").text($select.val());
    }

    // Additional helper methods...
    createBlockElement(blockId) {
      const enabledTaxonomies = this.getEnabledTaxonomies();
      const currentTone = $("#tone-of-voice").val() || "seo_optimized";
      const currentPov = $("#point-of-view").val() || "third_person";
      let taxonomyFields = "";

      // Get all taxonomies from WordPress data
      const taxonomies = poststation.taxonomies || {};

      const toneOptions = [
        {
          value: "seo_optimized",
          label: "SEO Optimized (Confident, Knowledgeable, Neutral, and Clear)",
        },
        { value: "excited", label: "Excited" },
        { value: "professional", label: "Professional" },
        { value: "friendly", label: "Friendly" },
        { value: "formal", label: "Formal" },
        { value: "casual", label: "Casual" },
        { value: "humorous", label: "Humorous" },
        { value: "conversational", label: "Conversational" },
      ];

      const povOptions = [
        {
          value: "first_person_singular",
          label: "First Person Singular (I, me, my, mine)",
        },
        {
          value: "first_person_plural",
          label: "First Person Plural (we, us, our, ours)",
        },
        { value: "second_person", label: "Second Person (you, your, yours)" },
        { value: "third_person", label: "Third Person (he, she, it, they)" },
      ];

      let toneHtml = `<select class="regular-text tone-of-voice">`;
      toneOptions.forEach((opt) => {
        toneHtml += `<option value="${opt.value}" ${
          opt.value === currentTone ? "selected" : ""
        }>${opt.label}</option>`;
      });
      toneHtml += `</select>`;

      let povHtml = `<select class="regular-text point-of-view">`;
      povOptions.forEach((opt) => {
        povHtml += `<option value="${opt.value}" ${
          opt.value === currentPov ? "selected" : ""
        }>${opt.label}</option>`;
      });
      povHtml += `</select>`;

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

      // Get post fields
      const postFields = this.getAllPostFields();
      let postFieldsHtml = "";
      Object.entries(postFields).forEach(([key, field]) => {
        const value = typeof field === "object" ? field.value : field;
        const inputField =
          key === "slug"
            ? `<input type="text" class="regular-text post-field-value-input" 
                        data-key="${key}"
                        value="${value}"
                        placeholder="Default slug">`
            : `<textarea class="post-field-value-input" 
                        data-key="${key}"
                        placeholder="Default value">${value}</textarea>`;

        postFieldsHtml += `
            <div class="form-field">
                <label>${key}</label>
                <div class="field-input">
                    <div class="field-label">Default Value</div>
                    ${inputField}
                    <div class="field-label">AI Prompt</div>
                    <textarea class="post-field-prompt-input" 
                        data-key="${key}"
                        placeholder="Enter the AI prompt for generating this field's content">${
                          field.prompt || ""
                        }</textarea>
                    <div class="field-options">
                        <div class="field-type">
                            <div class="field-label">Data Type</div>
                            <select class="post-field-type-input" data-key="${key}">
                                <option value="string" ${
                                  field.type === "string" ? "selected" : ""
                                }>String</option>
                                <option value="number" ${
                                  field.type === "number" ? "selected" : ""
                                }>Number</option>
                                <option value="boolean" ${
                                  field.type === "boolean" ? "selected" : ""
                                }>Boolean</option>
                                <option value="array" ${
                                  field.type === "array" ? "selected" : ""
                                }>Array</option>
                                <option value="object" ${
                                  field.type === "object" ? "selected" : ""
                                }>Object</option>
                            </select>
                        </div>
                        <div class="field-required">
                            <label>
                                <input type="checkbox" 
                                       class="post-field-required-input" 
                                       data-key="${key}"
                                       ${field.required ? "checked" : ""}>
                                Required Field
                            </label>
                        </div>
                    </div>
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
                                    <input type="url" 
                                           class="regular-text article-url" 
                                           placeholder="Enter article URL (optional)">
                                    <span class="error-message"></span>
                                </div>
                            </div>
                            <div class="form-field">
                                <label>Keyword</label>
                                <div class="field-input">
                                    <input type="text" 
                                           class="regular-text keyword" 
                                           placeholder="Enter main keyword (optional)">
                                </div>
                            </div>
                            <div class="form-field">
                                <label>Featured Image Title</label>
                                <div class="field-input">
                                    <input type="text" class="regular-text feature-image-title" 
                                        name="feature_image_title"
                                        value="{{title}}" 
                                        placeholder="{{title}}">
                                    <p class="description">
                                        Title used for the generated featured image. Default is {{title}}.
                                    </p>
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
                            <div class="form-field">
                                <label>Tone of Voice</label>
                                <div class="field-input">
                                    ${toneHtml}
                                </div>
                            </div>
                            <div class="form-field">
                                <label>Point of View</label>
                                <div class="field-input">
                                    ${povHtml}
                                </div>
                            </div>
                        </div>

                        <div class="form-section taxonomies-section">
                            <h3 class="section-title">Taxonomies</h3>
                            ${taxonomyFields}
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="postblock-column">
                        <div class="form-section post-fields-section">
                            <h3 class="section-title">Post Fields</h3>
                            ${postFieldsHtml}
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

    initImageConfigHandlers() {
      const $panel = $(".image-config-panel");

      // Toggle image config fields visibility
      $panel.on("change", "#image-config-enabled", (e) => {
        const isEnabled = $(e.target).prop("checked");
        if (isEnabled) {
          $(".image-config-fields").slideDown();
        } else {
          $(".image-config-fields").slideUp();
        }
        this.handleFieldChange();
      });

      // Image config field changes
      $panel.on(
        "input change",
        "#image-template-id, #image-category-text, #image-main-text, #image-category-color, #image-title-color",
        () => {
          this.handleFieldChange();
        }
      );

      // Background image select
      $panel.on("click", ".add-bg-image", (e) => {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(e.currentTarget);
        const $container = $btn.closest(".bg-images-list");

        if (typeof wp === "undefined" || !wp.media) {
          alert(
            "WordPress media library is not available. Please refresh the page."
          );
          return;
        }

        const mediaFrame = wp.media({
          title: "Select Background Images",
          button: { text: "Add to list" },
          multiple: true,
        });

        mediaFrame.on("select", () => {
          const selection = mediaFrame.state().get("selection");

          selection.each((attachment) => {
            const data = attachment.toJSON();

            // Re-calculate count for each image in selection to respect vacancy
            const currentCount = $container.find(".bg-image-item").length;
            if (currentCount >= 15) {
              return false; // Break the each loop
            }

            const html = `
              <div class="bg-image-item">
                <div class="bg-image-preview">
                  <img src="${data.url}" alt="">
                </div>
                <div class="bg-image-actions">
                  <input type="hidden" class="bg-image-url" value="${data.url}">
                  <button type="button" class="button remove-bg-image-item" title="Remove">
                    <span class="dashicons dashicons-no"></span>
                  </button>
                </div>
              </div>
            `;

            $(html).insertBefore($btn);
          });

          // Check if limit reached to hide button
          if ($container.find(".bg-image-item").length >= 15) {
            $btn.css("display", "none");
          } else {
            $btn.css("display", "flex");
          }

          this.handleFieldChange();
        });

        mediaFrame.open();
      });

      // Background image remove
      $panel.on("click", ".remove-bg-image-item", (e) => {
        e.preventDefault();
        e.stopPropagation();

        const $removeBtn = $(e.currentTarget);
        const $item = $removeBtn.closest(".bg-image-item");
        const $container = $item.closest(".bg-images-list");
        const $addBtn = $container.find(".add-bg-image");

        $item.remove();

        if ($container.find(".bg-image-item").length < 15) {
          $addBtn.css("display", "flex");
        }

        this.handleFieldChange();
      });

      // Color hex input sync
      $panel.on("input", ".color-hex-input", function () {
        const $input = $(this);
        const value = $input.val();
        if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
          $input.prev("input[type='color']").val(value);
        }
      });

      $panel.on("input", "input[type='color']", function () {
        $(this).next(".color-hex-input").val($(this).val());
      });
    }

    getImageConfig() {
      const bgImageUrls = [];
      $(".bg-image-url").each((_, input) => {
        bgImageUrls.push($(input).val());
      });

      return {
        enabled: $("#image-config-enabled").prop("checked"),
        templateId: $("#image-template-id").val(),
        categoryText: $("#image-category-text").val(),
        mainText: $("#image-main-text").val(),
        bgImageUrls: bgImageUrls,
        categoryColor: $("#image-category-color").val(),
        titleColor: $("#image-title-color").val(),
      };
    }

    getBlockData($block) {
      const articleUrl = $block.find(".article-url").val();
      const keyword = $block.find(".keyword").val();
      const toneOfVoice = $block.find(".tone-of-voice").val();
      const pointOfView = $block.find(".point-of-view").val();

      // Collect taxonomy data
      const taxonomies = {};
      $block.find(".taxonomy-field").each((_, field) => {
        const $field = $(field);
        const taxonomy = $field.data("taxonomy");
        const terms = $field
          .val()
          .split(",")
          .map((term) => term.trim())
          .filter(Boolean);
        if (terms.length > 0) {
          taxonomies[taxonomy] = terms;
        }
      });

      // Collect post fields data with all attributes
      const postFields = {};
      $block.find(".form-field").each((_, formField) => {
        const $formField = $(formField);
        const $valueInput = $formField.find(".post-field-value-input");

        if ($valueInput.length) {
          const metaKey = $valueInput.data("key");
          const value = $valueInput.val().trim();
          const prompt = $formField
            .find(".post-field-prompt-input")
            .val()
            .trim();
          const type = $formField.find(".post-field-type-input").val();
          const required = $formField
            .find(".post-field-required-input")
            .prop("checked");

          postFields[metaKey] = {
            value: value,
            prompt: prompt,
            type: type,
            required: required,
          };
        }
      });

      const featureImageId = $block.find(".feature-image-id").val();
      const featureImageTitle = $block.find(".feature-image-title").val();

      return {
        article_url: articleUrl,
        keyword: keyword,
        tone_of_voice: toneOfVoice,
        point_of_view: pointOfView,
        taxonomies: JSON.stringify(taxonomies),
        post_fields: JSON.stringify(postFields),
        feature_image_id: featureImageId,
        feature_image_title: featureImageTitle,
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

    addPostLinks($block, postId, postUrl) {
      const $headerInfo = $block.find(".postblock-header-info");
      const $existingEditLink = $headerInfo.find(".block-edit-link");
      const $existingPreviewLink = $headerInfo.find(".block-preview-link");

      if ($existingEditLink.length === 0) {
        const editUrl = `${poststation.admin_url}post.php?post=${postId}&action=edit`;
        const $editLink = $(`
          <a href="${editUrl}" 
             class="block-edit-link" 
             target="_blank" 
             title="Edit Post">
            <span class="dashicons dashicons-edit"></span>
          </a>
        `);
        $headerInfo.append($editLink);
      }

      if ($existingPreviewLink.length === 0 && postUrl) {
        const $previewLink = $(`
          <a href="${postUrl}" 
             class="block-preview-link" 
             target="_blank" 
             title="Preview Post">
            <span class="dashicons dashicons-visibility"></span>
          </a>
        `);
        $headerInfo.append($previewLink);
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
        $(`#block-status-filter option[value="${status}"]`)
          .find(".status-count")
          .text(count);
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

    addNewPostField() {
      const $container = $(".post-fields-container");
      const timestamp = new Date().getTime();

      const $newField = $(`
          <div class="post-field-item">
              <div class="post-field-header">
                  <div class="post-field-key">
                      <input type="text" 
                             class="regular-text post-field-key-input" 
                             placeholder="Meta Key">
                      <div class="error-message"></div>
                  </div>
                  <div class="post-field-actions">
                      <span class="post-field-delete dashicons dashicons-trash" 
                            title="Delete Field"></span>
                  </div>
              </div>
              <div class="post-field-content">
                  <div class="field-label">Default Value</div>
                  <textarea class="post-field-value" 
                            placeholder="Enter the default value for this field"></textarea>
                  <div class="field-label">AI Prompt</div>
                  <textarea class="post-field-prompt" 
                            placeholder="Enter the AI prompt for generating this field's content"></textarea>
                  <div class="field-options">
                      <div class="field-type">
                          <div class="field-label">Data Type</div>
                          <select class="post-field-type">
                              <option value="string">String</option>
                              <option value="number">Number</option>
                              <option value="boolean">Boolean</option>
                              <option value="array">Array</option>
                              <option value="object">Object</option>
                          </select>
                      </div>
                      <div class="field-required">
                          <label>
                              <input type="checkbox" class="post-field-required">
                              Required Field
                          </label>
                      </div>
                  </div>
              </div>
          </div>
      `);

      $container.append($newField);
      $newField.find(".post-field-key-input").focus();
      this.handleFieldChange();

      // Update blocks when a new post field is added
      this.updateBlocksPostFields();
    }

    deletePostField(e) {
      e.preventDefault();
      e.stopPropagation();

      const $field = $(e.currentTarget).closest(".post-field-item");
      const metaKey = $field.find(".post-field-key-input").val().trim();

      if (
        !confirm(
          `Are you sure you want to delete the post field "${metaKey}"? This will remove it from all blocks.`
        )
      ) {
        return;
      }

      // Remove the field from all blocks
      $(`.post-field-value-input[data-key="${metaKey}"]`)
        .closest(".form-field")
        .fadeOut(300, function () {
          $(this).remove();
        });

      // Remove the field from the post fields panel
      $field.fadeOut(300, () => {
        $field.remove(); // Actually remove the element from DOM
        this.handleFieldChange();
        this.updateBlocksPostFields();
      });
    }

    validatePostFieldKey(e) {
      const $input = $(e.currentTarget);
      const $keyWrapper = $input.closest(".post-field-key");
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
        $(".post-field-key-input")
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

    getAllPostFields() {
      const postFields = {};
      $(".post-fields-container .post-field-item").each((_, item) => {
        const $item = $(item);
        const key = $item.find(".post-field-key-input").val().trim();
        const value = $item.find(".post-field-value").val().trim();
        const prompt = $item.find(".post-field-prompt").val().trim();
        const type = $item.find(".post-field-type").val();
        const required = $item.find(".post-field-required").prop("checked");

        if (key) {
          postFields[key] = {
            value: value,
            prompt: prompt,
            type: type,
            required: required,
          };
        }
      });
      return postFields;
    }

    updateBlocksPostFields() {
      const postFields = this.getAllPostFields();

      $(".postblock").each((_, block) => {
        const $block = $(block);
        const $postFieldsSection = $block.find(".form-section").eq(2);

        // Store existing values
        const existingValues = {};
        const existingPrompts = {};
        const existingTypes = {};
        const existingRequired = {};

        $block.find(".post-field-value-input").each((_, input) => {
          const $input = $(input);
          const key = $input.data("key");
          existingValues[key] = $input.val();
          existingPrompts[key] = $input
            .siblings(".post-field-prompt-input")
            .val();
          existingTypes[key] = $input.siblings(".post-field-type-input").val();
          existingRequired[key] = $input
            .siblings(".post-field-required-input")
            .prop("checked");
        });

        // Remove existing post fields
        $postFieldsSection.find(".form-field").remove();

        // Add updated post fields
        Object.entries(postFields).forEach(([key, field]) => {
          const value =
            existingValues[key] !== undefined
              ? existingValues[key]
              : field.value;
          const prompt =
            existingPrompts[key] !== undefined
              ? existingPrompts[key]
              : field.prompt;
          const type =
            existingTypes[key] !== undefined ? existingTypes[key] : field.type;
          const required =
            existingRequired[key] !== undefined
              ? existingRequired[key]
              : field.required;

          const inputField =
            key === "slug"
              ? `<input type="text" class="regular-text post-field-value-input" 
                            data-key="${key}"
                            value="${value}"
                            placeholder="Default slug">`
              : `<textarea class="regular-text post-field-value-input" 
                            data-key="${key}"
                            placeholder="Custom field value">${value}</textarea>`;

          const newField = `
                <div class="form-field">
                    <label>${key}</label>
                    <div class="field-input">
                        ${inputField}
                        <div class="prompt-label">AI Prompt</div>
                        <textarea class="post-field-prompt-input" 
                            data-key="${key}"
                            placeholder="Enter the AI prompt for generating this field's content">${prompt}</textarea>
                        <div class="field-options">
                            <div class="field-type">
                                <div class="field-label">Data Type</div>
                                <select class="post-field-type-input" data-key="${key}">
                                    <option value="string" ${
                                      type === "string" ? "selected" : ""
                                    }>String</option>
                                    <option value="number" ${
                                      type === "number" ? "selected" : ""
                                    }>Number</option>
                                    <option value="boolean" ${
                                      type === "boolean" ? "selected" : ""
                                    }>Boolean</option>
                                    <option value="array" ${
                                      type === "array" ? "selected" : ""
                                    }>Array</option>
                                    <option value="object" ${
                                      type === "object" ? "selected" : ""
                                    }>Object</option>
                                </select>
                            </div>
                            <div class="field-required">
                                <label>
                                    <input type="checkbox" 
                                           class="post-field-required-input" 
                                           data-key="${key}"
                                           ${required ? "checked" : ""}>
                                    Required Field
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            `;
          $postFieldsSection.append(newField);
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
      const postFields = this.getAllPostFields();

      // Create example format
      const format = {
        block_id: 123, // Required
        title: "Example Post Title", // Required (AI-generated if not provided in block)
        content: "<p>Optional post content goes here...</p>", // Optional
        post_fields: {}, // Optional
        taxonomies: {}, // Optional
        thumbnail_url: "https://example.com/image.jpg", // Optional
      };

      // Add example taxonomy terms for enabled taxonomies
      Object.keys(enabledTaxonomies).forEach((taxonomy) => {
        if (enabledTaxonomies[taxonomy]) {
          format.taxonomies[taxonomy] = ["Example Term 1", "Example Term 2"];
        }
      });

      // Add example post fields excluding title and content
      Object.keys(postFields).forEach((key) => {
        if (key !== "title" && key !== "content") {
          format.post_fields[key] = "Example value for " + key;
        }
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

    showImportBlocksModal() {
      const $modal = $("#import-blocks-modal");
      const $code = $("#import-sample-code");

      // Generate sample JSON
      const sample = this.generateImportSample();
      $code.text(JSON.stringify(sample, null, 2));

      $modal.addClass("active");
      $("body").css("overflow", "hidden");
    }

    hideImportBlocksModal() {
      const $modal = $("#import-blocks-modal");
      $modal.removeClass("active");
      $("body").css("overflow", "");
      $("#import-blocks-json").val("");
    }

    generateImportSample() {
      const sampleBlock = {
        article_url: "https://example.com/source-article",
        topic: "SEO Keyword",
        slug: "custom-slug-format",
        feature_image_title: "Generated Image Title",
      };

      return [sampleBlock];
    }

    copyImportSample() {
      const code = $("#import-sample-code").text();
      navigator.clipboard.writeText(code).then(() => {
        const $button = $(".copy-sample-json");
        const originalHtml = $button.html();

        $button.text("Copied!");
        setTimeout(() => {
          $button.html(originalHtml);
        }, 2000);
      });
    }

    async handleImportBlocks() {
      const $jsonInput = $("#import-blocks-json");
      const jsonStr = $jsonInput.val().trim();

      if (!jsonStr) {
        alert("Please paste some JSON first.");
        return;
      }

      let blocks;
      try {
        blocks = JSON.parse(jsonStr);
        if (!Array.isArray(blocks)) {
          throw new Error("JSON must be an array of block objects.");
        }
      } catch (e) {
        alert("Invalid JSON format: " + e.message);
        return;
      }

      const $button = $("#process-import-blocks");
      const originalHtml = $button.html();

      $button
        .prop("disabled", true)
        .html(
          '<span class="dashicons dashicons-update spin"></span> Importing...'
        );

      try {
        // First save all current blocks to ensure state is consistent
        await this.handleSavePostWork();

        const response = await $.post(poststation.ajax_url, {
          action: "poststation_import_blocks",
          nonce: poststation.nonce,
          postwork_id: $("#postwork-id").val(),
          blocks_json: JSON.stringify(blocks),
        });

        if (response.success) {
          alert(response.data.message);
          window.location.reload();
        } else {
          throw new Error(response.data);
        }
      } catch (error) {
        console.error("Import failed:", error);
        alert("Import failed: " + error.message);
        $button.prop("disabled", false).html(originalHtml);
      }
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

    async handleClearCompletedBlocks() {
      if (
        !confirm(
          "Are you sure you want to delete all completed blocks? This action cannot be undone."
        )
      ) {
        return;
      }

      const $button = $("#clear-completed-blocks");
      const originalHtml = $button.html();

      $button
        .prop("disabled", true)
        .html('<span class="dashicons dashicons-update spin"></span> Clearing...');

      try {
        const response = await $.post(poststation.ajax_url, {
          action: "poststation_clear_completed_blocks",
          nonce: poststation.nonce,
          postwork_id: $("#postwork-id").val(),
        });

        if (response.success) {
          // Remove completed blocks from UI
          $(".postblock[data-status='completed']").fadeOut(400, function () {
            $(this).remove();
            // Update counts and no blocks message
            const $blocks = $(".postblock");
            postwork.updateStatusCounts();
            postwork.updateNoBlocksMessage($blocks.length === 0);
          });
        } else {
          throw new Error(response.data);
        }
      } catch (error) {
        console.error("Failed to clear completed blocks:", error);
        alert("Failed to clear completed blocks: " + error.message);
      } finally {
        $button.prop("disabled", false).html(originalHtml);
      }
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
