/**
 * Payment Form Builder - Form Builder JavaScript
 */
(function ($) {
  ("use strict");

  // Main FormBuilder object
  const FormBuilder = {
    // Initialize the form builder
    init: function () {
      console.log("FormBuilder initializing...");

      // Check if we're on a form edit page
      if ($("#form-fields-container").length === 0) {
        console.log("Not on a payment form edit page");
        return false;
      }

      // Set up DOM references
      this.formContainer = $("#form-fields-container");
      this.form = $("#post");
      this.jsonInput = $("#form-fields-json");

      console.log("Form container found:", this.formContainer.length > 0);
      console.log("Form found:", this.form.length > 0);
      console.log("JSON input found:", this.jsonInput.length > 0);

      // Set up event handlers
      this.setupEventHandlers();

      // Make fields sortable
      this.makeFieldsSortable();

      // Load existing fields
      this.loadExistingFields();

      console.log("FormBuilder initialized successfully");
      return true;
    },

    // Set up event handlers
    setupEventHandlers: function () {
      // Remove any existing handlers to prevent duplicates
      $("#add-text-field").off("click");
      $("#add-email-field").off("click");
      $("#add-textarea-field").off("click");
      $("#add-two-column-field").off("click");
      $(document).off("click", ".remove-field");
      $(document).off("change", ".customer-email-radio");
      this.form.off("submit.formBuilder");

      // Add field buttons
      $("#add-text-field").on("click", () => this.addField("text"));
      $("#add-email-field").on("click", () => this.addField("email"));
      $("#add-textarea-field").on("click", () => this.addField("textarea"));
      $("#add-two-column-field").on("click", () => this.addTwoColumnField());

      // Remove field
      $(document).on("click", ".remove-field", function () {
        $(this).closest(".field-row").remove();
      });

      // Customer email selection
      $(document).on("change", ".customer-email-radio", function () {
        $(".customer-email-radio").not(this).prop("checked", false);
      });

      // Form submission
      this.form.on("submit.formBuilder", () => {
        this.handleFormSubmit();
        return true; // Allow form to submit normally
      });

      // Debug button
      $("#debug-form-data")
        .off("click")
        .on("click", () => {
          this.debugFormData();
        });
    },

    // Make fields sortable
    makeFieldsSortable: function () {
      this.formContainer.sortable({
        handle: ".field-header",
        placeholder: "field-row-placeholder",
      });
    },

    // Load existing fields
    loadExistingFields: function () {
      if (
        typeof window.existingFormFields !== "undefined" &&
        Array.isArray(window.existingFormFields) &&
        window.existingFormFields.length > 0
      ) {
        console.log("Loading existing fields:", window.existingFormFields);

        window.existingFormFields.forEach((field) => {
          if (field.type === "two-column") {
            this.addTwoColumnField(field);
          } else {
            this.addField(field.type, field);
          }
        });
      } else {
        // Add a default field if no fields exist
        console.log("No existing fields found, adding default field");
        this.addField("text", {
          label: "Name",
          required: true,
        });
      }
    },

    // Add a field
    addField: function (type, fieldData) {
      fieldData = fieldData || {};
      const label = fieldData.label || "";
      const required = fieldData.required || false;
      const isCustomerEmail = fieldData.customer_email || false;

      let html = `
                <div class="field-row" data-field-type="${type}">
                    <div class="field-header">
                        <span class="field-type">${this.capitalizeFirstLetter(
                          type
                        )}</span>
                        <button type="button" class="remove-field">Remove</button>
                    </div>
                    <div class="field-body">
                        <div class="field-setting">
                            <label>Field Label:</label>
                            <input type="text" class="field-label" value="${label}" placeholder="Enter field label">
                        </div>
                        <div class="field-setting">
                            <label>
                                <input type="checkbox" class="field-required" ${
                                  required ? "checked" : ""
                                }>
                                Required Field
                            </label>
                        </div>`;

      // Add customer email option for email fields
      if (type === "email") {
        html += `
                        <div class="field-setting">
                            <label>
                                <input type="radio" name="customer_email_field" class="customer-email-radio" value="${label}" ${
          isCustomerEmail ? "checked" : ""
        }>
                                <span style="color:#0073aa;">Customer Email</span>
                            </label>
                        </div>`;
      }

      html += `
                    </div>
                </div>`;

      this.formContainer.append(html);

      // Update radio button value when label changes for email fields
      if (type === "email") {
        const $row = this.formContainer.find(".field-row").last();
        const $label = $row.find(".field-label");
        const $radio = $row.find(".customer-email-radio");

        $label.on("change keyup", function () {
          $radio.val($(this).val());
        });
      }
    },

    // Add a two-column field
    addTwoColumnField: function (fieldData) {
      fieldData = fieldData || {};
      const leftLabel =
        fieldData.label && fieldData.label[0] ? fieldData.label[0] : "";
      const rightLabel =
        fieldData.label && fieldData.label[1] ? fieldData.label[1] : "";
      const leftRequired =
        fieldData.required && fieldData.required[0] ? true : false;
      const rightRequired =
        fieldData.required && fieldData.required[1] ? true : false;

      const html = `
                <div class="field-row two-column-field" data-field-type="two-column">
                    <div class="field-header">
                        <span class="field-type">Two Column Field</span>
                        <button type="button" class="remove-field">Remove</button>
                    </div>
                    <div class="field-body">
                        <div class="column-container">
                            <div class="column left-column">
                                <div class="field-setting">
                                    <label>Left Column Label:</label>
                                    <input type="text" class="field-label left-label" value="${leftLabel}" placeholder="Left column label">
                                </div>
                                <div class="field-setting">
                                    <label>
                                        <input type="checkbox" class="field-required left-required" ${
                                          leftRequired ? "checked" : ""
                                        }>
                                        Required Field
                                    </label>
                                </div>
                            </div>
                            <div class="column right-column">
                                <div class="field-setting">
                                    <label>Right Column Label:</label>
                                    <input type="text" class="field-label right-label" value="${rightLabel}" placeholder="Right column label">
                                </div>
                                <div class="field-setting">
                                    <label>
                                        <input type="checkbox" class="field-required right-required" ${
                                          rightRequired ? "checked" : ""
                                        }>
                                        Required Field
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;

      this.formContainer.append(html);
    },

    // Handle form submission
    handleFormSubmit: function () {
      console.log("Form submit detected");

      // Collect all fields
      const fields = this.collectFormData();

      console.log("Collected fields:", fields);

      // Set the JSON data in the hidden input
      const jsonData = JSON.stringify(fields);
      this.jsonInput.val(jsonData);

      console.log("Set form-fields-json value to:", jsonData);
      console.log("Actual input value now:", this.jsonInput.val());
    },

    // Collect form data
    collectFormData: function () {
      const fields = [];

      this.formContainer.find(".field-row").each((index, row) => {
        const $row = $(row);
        const type = $row.data("field-type");

        if (type === "two-column") {
          // Handle two-column field
          const leftLabel = $row.find(".left-label").val();
          const rightLabel = $row.find(".right-label").val();
          const leftRequired = $row.find(".left-required").prop("checked");
          const rightRequired = $row.find(".right-required").prop("checked");

          fields.push({
            type: "two-column",
            label: [leftLabel, rightLabel],
            required: [leftRequired, rightRequired],
          });
        } else {
          // Handle regular field
          const label = $row.find(".field-label").val();
          const required = $row.find(".field-required").prop("checked");

          const fieldData = {
            type: type,
            label: label,
            required: required,
          };

          // Add customer_email flag for email fields if selected
          if (type === "email") {
            const customerEmailRadio = $row.find(".customer-email-radio");
            if (customerEmailRadio.prop("checked")) {
              fieldData.customer_email = true;
            }
          }

          fields.push(fieldData);
        }
      });

      return fields;
    },

    // Debug form data
    debugFormData: function () {
      const fields = this.collectFormData();
      const jsonData = JSON.stringify(fields);

      this.jsonInput.val(jsonData);

      alert(
        `Form data collected: ${fields.length} fields. JSON set in hidden input.`
      );
      console.log("Collected fields:", fields);
      console.log("JSON data:", jsonData);
    },

    // Helper function to capitalize first letter
    capitalizeFirstLetter: function (string) {
      return string.charAt(0).toUpperCase() + string.slice(1);
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    FormBuilder.init();
  });
})(jQuery);
