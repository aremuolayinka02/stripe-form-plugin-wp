// Add to admin.js
jQuery(document).ready(function ($) {
  // Add new field
  $(".add-field").on("click", function () {
    const fieldType = $(this).data("type");

    // Create base HTML for all field types
    let html = `
      <div class="field-row" data-type="${fieldType}">
        <input type="hidden" name="field_type[]" value="${fieldType}">
        <input type="text" name="field_label[]" placeholder="Field Label">
        <label>
          <input type="checkbox" name="field_required[]" value="1">
          Required
        </label>`;

    // Add customer email option ONLY for email fields
    if (fieldType === "email") {
      html += `
        <label style="display:inline-block; margin:0 10px; background-color:#e7f5fe; padding:3px 8px; border:1px solid #0073aa; border-radius:3px;">
          <input type="radio" name="customer_email_field" value="">
          <span style="color:#0073aa;">Customer Email</span>
        </label>`;
    }

    // Add remove button to all fields
    html += `<button type="button" class="remove-field">Remove</button>
      </div>`;

    // Append the new field to the container
    $(".form-fields-container").append(html);

    // Set up the change handler for the label field
    const $newField = $(".field-row:last-child");
    $newField.find('input[name="field_label[]"]').on("change", function () {
      if (fieldType === "email") {
        const fieldId = sanitizeTitle($(this).val());
        $newField.find('input[name="customer_email_field"]').val(fieldId);
      }
    });
  });

  // Add to admin.js
  $(".add-two-column").on("click", function () {
    const html = `
      <div class="field-row two-column-row" data-type="two-column">
        <div class="two-column-container">
          <div class="column">
            <input type="hidden" name="field_type[]" value="text">
            <input type="text" name="field_label[]" placeholder="Left Column Label">
            <label>
              <input type="checkbox" name="field_required[]" value="1">
              Required
            </label>
          </div>
          <div class="column">
            <input type="hidden" name="field_type[]" value="text">
            <input type="text" name="field_label[]" placeholder="Right Column Label">
            <label>
              <input type="checkbox" name="field_required[]" value="1">
              Required
            </label>
          </div>
        </div>
        <button type="button" class="remove-field">Remove</button>
      </div>
    `;

    $(".form-fields-container").append(html);
  });

  // Remove field
  $(document).on("click", ".remove-field", function () {
    $(this).closest(".field-row").remove();
  });

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
    const fieldType = fieldRow.find('input[name="field_type[]"]').val();

    if (fieldType === "email") {
      const fieldId = sanitizeTitle($(this).val());
      fieldRow.find('input[name="customer_email_field"]').val(fieldId);
    }
  });
});

// Orders page AJAX search
jQuery(document).ready(function ($) {
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
