jQuery(document).ready(function ($) {
  // Add new field
  $(".add-field").on("click", function () {
    const fieldType = $(this).data("type");
    const template = `
            <div class="field-row">
                <input type="hidden" name="field_type[]" value="${fieldType}">
                <input type="text" name="field_label[]" placeholder="Field Label">
                <label>
                    <input type="checkbox" name="field_required[]" value="1">
                    Required
                </label>
                <button type="button" class="remove-field">Remove</button>
            </div>
        `;
    $(".form-fields-container").append(template);
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
});
