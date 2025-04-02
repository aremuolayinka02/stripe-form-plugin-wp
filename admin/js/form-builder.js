/**
 * Payment Form Builder - Form Builder JavaScript
 *
 * Handles the dynamic form builder interface in the WordPress admin.
 */
(function ($) {
  "use strict";

  // Initialize when document is ready
  $(document).ready(function () {

    console.log("Document ready - initializing form builder");

    // Check if we're on a payment form edit page
    if ($("#form-fields-container").length === 0) {
      console.log("Not on a payment form edit page, exiting");
      return;
    }

    const formBuilder = {
      init: function () {
        this.formContainer = $("#form-fields-container");
        this.formElement = $("#post");
        this.jsonInput = $("#form-fields-json");

        console.log("Form builder elements:");
        console.log("- Form container:", this.formContainer.length > 0);
        console.log("- Form element:", this.formElement.length > 0);
        console.log("- JSON input:", this.jsonInput.length > 0);

        // Load existing fields if available
        this.loadExistingFields();

        // Set up event listeners
        this.setupEventListeners();

        // Make fields sortable
        this.makeFieldsSortable();

        // Add a test field if no fields exist (for debugging)
        if (this.formContainer.children().length === 0) {
          console.log("Adding a test field for debugging");
          this.addField("text", { label: "Test Field", required: true });
        }
      },

      // Rest of your methods...

      handleFormSubmit: function (e) {
        console.log("Form submit event triggered");

        // Collect all form field data
        const fields = this.collectFormData();

        console.log("Collected form fields:", fields);

        // Set the JSON data in the hidden input
        const jsonData = JSON.stringify(fields);
        this.jsonInput.val(jsonData);

        console.log("Set JSON input value to:", jsonData);
        console.log("Actual input value now:", this.jsonInput.val());

        // Don't prevent default - let the form submit normally
      },
    };

    // Initialize the form builder
    formBuilder.init();

    $("#post").on("submit", function () {
      console.log("Direct form submit handler triggered");

      // Get all fields
      const fields = [];

      // Process each field row
      $("#form-fields-container .field-row").each(function () {
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

      console.log("Direct handler collected fields:", fields);

      // Set the JSON data in the hidden input
      const jsonData = JSON.stringify(fields);
      $("#form-fields-json").val(jsonData);

      console.log("Direct handler set JSON input value to:", jsonData);
      console.log("Actual input value now:", $("#form-fields-json").val());

      // Let the form submit normally
      return true;
    });
  });
})(jQuery);
