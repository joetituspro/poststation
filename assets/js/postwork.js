(function ($) {
  "use strict";

  class PostWork {
    constructor() {
      this.$ = $; // Store jQuery reference
      this.STATUS_CHECK_INTERVAL = 10000;
      this.MAX_RETRIES = 60;
      this.batchTimeout = null;
      this.urlUpdateTimeout = null;
      this.hasUnsavedChanges = false;
      this.initialState = {
        title: $("#postwork-title").val(),
        webhook_id: $("#webhook-id").val(),
        post_type: $("#post-type").val(),
        enabled_taxonomies: this.getEnabledTaxonomies(),
      };

      this.init();
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
      $(".postblock").each((_, block) => {
        const $block = $(block);
        const $content = $block.find(".postblock-content table");
        const taxonomy = this.getTaxonomyObject(taxName);

        if (isEnabled) {
          // Add taxonomy field with default values
          const defaultTerms = this.getDefaultTerms(taxName);
          $content.append(this.createTaxonomyField(taxonomy, defaultTerms));
        } else {
          // Remove taxonomy field
          $content.find(`[data-taxonomy="${taxName}"]`).closest("tr").remove();
        }
      });

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

        const response = await $.post(poststation.ajax_url, {
          action: "poststation_update_postwork",
          nonce: poststation.nonce,
          id: postworkId,
          title: title,
          webhook_id: webhookId,
          post_type: postType,
          enabled_taxonomies: JSON.stringify(enabledTaxonomies),
          default_terms: JSON.stringify(defaultTerms),
          prompts: JSON.stringify(prompts),
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

      $button.prop("disabled", true).text("Running...");

      // Switch to "all" filter to show all blocks
      $("#block-status-filter").val("all").trigger("change");

      try {
        for (const block of $blocks) {
          const $block = $(block);

          // Scroll to the block that's about to be processed
          this.scrollToBlock($block);

          const success = await this.processBlock(
            $block,
            postworkId,
            webhookId
          );

          if (!success) {
            // If a block fails, stop processing remaining blocks
            break;
          }

          // Wait for block to complete or fail before moving to next block
          await this.waitForBlockCompletion($block.data("id"));
        }
      } catch (error) {
        console.error("Error processing blocks:", error);
        alert("An error occurred while processing blocks.");
      } finally {
        $button.prop("disabled", false).text("Run");
      }
    }

    async waitForBlockCompletion(blockId, timeout = 300000) {
      // 5 minutes timeout
      return new Promise((resolve, reject) => {
        const startTime = Date.now();
        const checkInterval = setInterval(async () => {
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
        taxonomyFields += this.createTaxonomyField(taxonomy, defaultTerms).prop(
          "outerHTML"
        );
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
                    ${taxonomyFields}
                </table>
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

      return {
        article_url: articleUrl,
        taxonomies: JSON.stringify(taxonomies),
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
      $(".prompt-item").each((_, item) => {
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
  }

  // Initialize on document ready
  $(document).ready(() => new PostWork());
})(jQuery);
