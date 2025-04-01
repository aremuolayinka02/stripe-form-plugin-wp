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


// Orders page AJAX search
jQuery(document).ready(function($) {
    var searchTimer;
    
    // Handle search input with debounce
    $('.orders-filters input[name="s"]').on('input', function() {
        clearTimeout(searchTimer);
        var searchInput = $(this);
        
        searchTimer = setTimeout(function() {
            if (searchInput.val().length >= 3 || searchInput.val().length === 0) {
                $('.orders-filters form').submit();
            }
        }, 500);
    });
});