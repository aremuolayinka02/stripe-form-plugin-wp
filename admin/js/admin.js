jQuery(document).ready(function ($) {
  // Add new field
  $(".add-field").on("click", function () {
    const fieldType = $(this).data("type");
    let template = `
            <div class="field-row">
                <input type="hidden" name="field_type[]" value="${fieldType}">
                <input type="text" name="field_label[]" placeholder="Field Label">
                <label>
                    <input type="checkbox" name="field_required[]" value="1">
                    Required
                </label>
            `;

    // Add customer email option for email fields
    if (fieldType === "email") {
      template += `
                <label>
                    <input type="radio" name="customer_email_field" value="field-${
                      $(".field-row").length
                    }">
                    Customer Email
                </label>
            `;
    }

    template += `<button type="button" class="remove-field">Remove</button>
            </div>
        `;

    $(".form-fields-container").append(template);

    // Update field IDs when labels change
    $('.field-row:last-child input[name="field_label[]"]').on(
      "change",
      function () {
        const fieldId = "field-" + sanitizeTitle($(this).val());
        $(this)
          .closest(".field-row")
          .find('input[name="customer_email_field"]')
          .val(fieldId);
      }
    );
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
