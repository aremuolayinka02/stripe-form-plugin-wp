jQuery(document).ready(function ($) {
  // Add new field
  $(".add-field").on("click", function () {
    const fieldType = $(this).data("type");
    const fieldCount = $(".field-row").length;
    const currentIndex = fieldCount * 2; // Maintain consistent indexing with two-column fields

    let html = `
  <div class="field-row" data-type="${fieldType}">
  <input type="hidden" name="field_type[]" value="${fieldType}">
  <input type="text" name="field_label[]" placeholder="Field Label">
  <label>
  <input type="checkbox" name="field_required[]" value="${currentIndex}">
  Required
  </label>`;

    // Add customer email option ONLY for email fields
    if (fieldType === "email") {
      html += `
    <label class="customer-email-option">
    <input type="radio" name="customer_email_field" value="">
    <span style="color:#0073aa;">Customer Email</span>
    </label>`;
    }

    // Add remove button to all fields
    html += `<button type="button" class="remove-field">Remove</button>
  </div>`;

    // Append the new field to the container
    $(".form-fields-container").append(html);
  });

  // Add two-column field
  $(".add-two-column").on("click", function () {
    const fieldCount = $(".field-row").length;
    const currentIndex = fieldCount * 2; // Each field takes up 2 indices

    const html = `
  <div class="field-row two-column-row" data-type="two-column">
  <input type="hidden" name="field_type[]" value="two-column">
  <div class="two-column-container">
  <div class="column">
  <input type="text" name="field_label[]" placeholder="Left Column Label" data-column="0">
  <label>
  <input type="checkbox" name="field_required[]" value="${currentIndex}" data-column="0">
  Required
  </label>
  </div>
  <div class="column">
  <input type="text" name="field_label[]" placeholder="Right Column Label" data-column="1">
  <label>
  <input type="checkbox" name="field_required[]" value="${
    currentIndex + 1
  }" data-column="1">
  Required
  </label>
  </div>
  </div>
  <button type="button" class="remove-field">Remove</button>
  </div>`;

    $(".form-fields-container").append(html);
  });

  // Remove field
  $(document).on("click", ".remove-field", function () {
    $(this).closest(".field-row").remove();
  });

  // Update email field value when label changes
  $(document).on(
    "change",
    '.field-row[data-type="email"] input[name="field_label[]"]',
    function () {
      const $row = $(this).closest(".field-row");
      const $customerEmailRadio = $row.find(
        'input[name="customer_email_field"]'
      );
      $customerEmailRadio.val($(this).val());
    }
  );

  // Make fields sortable
  $(".form-fields-container").sortable({
    items: ".field-row",
    handle: ".field-row",
    cursor: "move",
  });

  // Helper function to create a slug from a string (like WordPress sanitize_title)
  function sanitizeTitle(text) {
    if (!text) return "";
    return text
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "");
  }

  // Update field IDs when labels change
  $(document).on("change", 'input[name="field_label[]"]', function () {
    const fieldRow = $(this).closest(".field-row");
    const fieldType = fieldRow.data("type");

    if (fieldType === "email") {
      const fieldId = sanitizeTitle($(this).val());
      fieldRow.find('input[name="customer_email_field"]').val(fieldId);
    }
  });

  // Form submission handling
  $("form#post").on("submit", function () {
    // Ensure all required checkboxes have proper values
    $('.field-row input[type="checkbox"][name="field_required[]"]').each(
      function () {
        if (!$(this).is(":checked")) {
          $(this).prop("checked", false);
        }
      }
    );
    return true;
  });

  // Add debug button handler
  $("#debug-form-data").on("click", () => {
    const fields = this.collectFormData();
    console.log("Debug - Collected fields:", fields);

    const jsonData = JSON.stringify(fields);
    this.jsonInput.val(jsonData);

    console.log("Debug - Set JSON input value to:", jsonData);
    console.log("Debug - Actual input value now:", this.jsonInput.val());

    // Show debug info
    $("#debug-fields-count").text(fields.length);
    $("#debug-json-data").text(jsonData);
    $(".form-builder-debug").show();

    alert(
      "Form data collected and set in hidden input. Check console for details."
    );
  });

  // Orders page AJAX search
  var searchTimer;

  // Handle search input with debounce
  $('.orders-filters input[name="s"]').on("input", function () {
    clearTimeout(searchTimer);
    var searchInput = $(this);

    searchTimer = setTimeout(function () {
      if (searchInput.val().length >= 3 || searchInput.val().length === 0) {
        $(".orders-filters form").submit();
      }
    }, 500);
  });
});


