/**
 * Payment Form Builder - Form Builder JavaScript
 *
 * Handles the dynamic form builder interface in the WordPress admin.
 */
(function ($) {
  "use strict";

  // Initialize when document is ready
  $(document).ready(function () {
    const formBuilder = {
      init: function () {
        this.formContainer = $("#form-fields-container");
        // The form is the WordPress post form
        this.formElement = $("#post");
        this.jsonInput = $("#form-fields-json");

        // Log initialization
        console.log("Form builder initialized");
        console.log("Form container found:", this.formContainer.length > 0);
        console.log("Form element found:", this.formElement.length > 0);
        console.log("JSON input found:", this.jsonInput.length > 0);

        // Load existing fields if available
        this.loadExistingFields();

        // Set up event listeners
        this.setupEventListeners();

        // Make fields sortable
        this.makeFieldsSortable();
      },

      setupEventListeners: function () {
        // Add field buttons
        $("#add-text-field").on("click", () => this.addField("text"));
        $("#add-email-field").on("click", () => this.addField("email"));
        $("#add-textarea-field").on("click", () => this.addField("textarea"));
        $("#add-two-column-field").on("click", () => this.addTwoColumnField());

        // Form submission - attach to the WordPress post form
        this.formElement.on("submit", (e) => this.handleFormSubmit(e));

        // Delegate events for dynamically added elements
        this.formContainer.on("click", ".remove-field", (e) =>
          this.removeField(e)
        );
        this.formContainer.on("change", ".field-label", (e) =>
          this.updateFieldId(e)
        );
        this.formContainer.on("change", ".customer-email-radio", (e) =>
          this.handleCustomerEmailSelection(e)
        );

        // Log that event listeners are set up
        console.log("Event listeners set up");
      },

      loadExistingFields: function () {
        // If we have existing fields in PHP, they should be output as JSON in a script tag
        const existingFields = window.existingFormFields || [];

        if (existingFields && existingFields.length) {
          existingFields.forEach((field) => {
            if (field.type === "two-column") {
              this.addTwoColumnField(field);
            } else {
              this.addField(field.type, field);
            }
          });

          // Log loaded fields
          console.log("Loaded existing fields:", existingFields);
        } else {
          console.log("No existing fields found");
        }
      },

      addField: function (type, fieldData = {}) {
        const fieldId = this.generateFieldId();
        const isRequired = fieldData.required || false;
        const label = fieldData.label || "";
        const isCustomerEmail = fieldData.customer_email || false;

        let fieldHtml = `
                    <div class="field-row" data-field-id="${fieldId}" data-field-type="${type}">
                        <div class="field-header">
                            <span class="field-type">${
                              type.charAt(0).toUpperCase() + type.slice(1)
                            }</span>
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
                                      isRequired ? "checked" : ""
                                    }>
                                    Required Field
                                </label>
                            </div>`;

        // Add customer email option for email fields
        if (type === "email") {
          fieldHtml += `
                            <div class="field-setting">
                                <label>
                                    <input type="radio" name="customer_email_field" class="customer-email-radio" value="${label}" ${
            isCustomerEmail ? "checked" : ""
          }>
                                    <span style="color:#0073aa;">Customer Email</span>
                                </label>
                            </div>`;
        }

        fieldHtml += `
                        </div>
                    </div>`;

        this.formContainer.append(fieldHtml);

        // Update field IDs after adding a new field
        this.updateFieldIds();

        // Log the added field
        console.log("Added field:", type, fieldData);
      },

      addTwoColumnField: function (fieldData = {}) {
        const fieldId = this.generateFieldId();
        const leftLabel =
          fieldData.label && fieldData.label[0] ? fieldData.label[0] : "";
        const rightLabel =
          fieldData.label && fieldData.label[1] ? fieldData.label[1] : "";
        const leftRequired =
          fieldData.required && fieldData.required[0] ? true : false;
        const rightRequired =
          fieldData.required && fieldData.required[1] ? true : false;

        const fieldHtml = `
                    <div class="field-row two-column-field" data-field-id="${fieldId}" data-field-type="two-column">
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

        this.formContainer.append(fieldHtml);

        // Update field IDs after adding a new field
        this.updateFieldIds();

        // Log the added two-column field
        console.log("Added two-column field:", fieldData);
      },

      removeField: function (e) {
        $(e.target).closest(".field-row").remove();

        // If we're removing a field with the customer email radio checked,
        // we need to update the customer email selection
        this.updateCustomerEmailRadios();

        // Update field IDs after removing a field
        this.updateFieldIds();

        // Log the removal
        console.log("Field removed");
      },

      updateFieldId: function (e) {
        const label = $(e.target).val();
        const fieldRow = $(e.target).closest(".field-row");

        // If this is an email field with customer email selected, update the radio value
        if (fieldRow.data("field-type") === "email") {
          const radioButton = fieldRow.find(".customer-email-radio");
          if (radioButton.prop("checked")) {
            radioButton.val(label);
          }
        }
      },

      handleCustomerEmailSelection: function (e) {
        // Ensure only one radio button is checked
        $(".customer-email-radio").not(e.target).prop("checked", false);
      },

      updateCustomerEmailRadios: function () {
        // Update all customer email radio values to match their field labels
        $('.field-row[data-field-type="email"]').each(function () {
          const label = $(this).find(".field-label").val();
          $(this).find(".customer-email-radio").val(label);
        });
      },

      updateFieldIds: function () {
        // Update all field IDs to ensure they're sequential
        this.formContainer.find(".field-row").each(function (index) {
          $(this).attr("data-field-index", index);
        });
      },

      generateFieldId: function () {
        // Generate a unique ID for the field
        return "field_" + Date.now() + "_" + Math.floor(Math.random() * 1000);
      },

      makeFieldsSortable: function () {
        this.formContainer.sortable({
          handle: ".field-header",
          placeholder: "field-row-placeholder",
          opacity: 0.7,
          stop: () => {
            // Update field IDs after sorting
            this.updateFieldIds();
          },
        });
      },

      collectFormData: function () {
        const fields = [];

        // Process each field row
        this.formContainer.find(".field-row").each(function () {
          const $row = $(this);
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

      handleFormSubmit: function (e) {
        // Don't prevent the default form submission
        // Instead, just make sure our JSON data is set

        // Collect all form field data
        const fields = this.collectFormData();

        // Log the collected data for debugging
        console.log("Collected form fields:", fields);

        // Set the JSON data in the hidden input
        this.jsonInput.val(JSON.stringify(fields));

        // Log to confirm the hidden input was populated
        console.log("Hidden input value set to:", this.jsonInput.val());
      },
    };

    // Initialize the form builder
    formBuilder.init();
  });
})(jQuery);
