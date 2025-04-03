document.addEventListener("DOMContentLoaded", function () {
  // Verify SSL for live mode
  if (!pfbData.test_mode && window.location.protocol !== "https:") {
    console.error("Stripe requires HTTPS in live mode");
    return;
  }

  // Verify required data
  if (!pfbData || !pfbData.ajaxUrl || !pfbData.publicKey) {
    console.error("Required payment form data is missing");
    return;
  }

  const stripe = Stripe(pfbData.publicKey);
  const elements = stripe.elements();
  const card = elements.create("card");
  card.mount("#card-element");

  const form = document.querySelector(".payment-form");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector('button[type="submit"]');
    const errorElement = document.getElementById("card-errors");
    errorElement.textContent = "";

    // Add debug info
    console.log("Form submission started");
    console.log("AJAX URL:", pfbData.ajaxUrl);
    console.log("Test mode:", pfbData.test_mode);

    try {
      // Disable submit button to prevent double submission
      submitButton.disabled = true;

      // Get form data
      const formData = new FormData(form);
      const formId = form.id.replace("payment-form-", "");

      // Create form data object
      const formDataObj = {};
      formData.forEach((value, key) => {
        formDataObj[key] = value;
      });

      // Make AJAX request with proper formatting and error handling
      const response = await fetch(pfbData.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "process_payment_form",
          nonce: pfbData.nonce,
          form_id: formId,
          form_data: JSON.stringify(formDataObj),
          site_url: window.location.origin,
        }).toString(),
        credentials: "same-origin",
      });

      // Log response for debugging
      console.log("Server response status:", response.status);
      const responseText = await response.text();
      console.log("Server response:", responseText);

      let result;
      try {
        result = JSON.parse(responseText);
      } catch (e) {
        throw new Error("Invalid server response: " + responseText);
      }

      if (!result.success) {
        // Enhanced error handling
        if (result.data && result.data.errors) {
          // Create a formatted error message with line breaks
          const errorMessage = result.data.errors.join("<br>");
          errorElement.innerHTML = errorMessage;
          submitButton.disabled = false;
          return;
        } else {
          throw new Error(result.data || "Payment processing failed");
        }
      }

      // Then confirm the card payment
      const { paymentIntent, error } = await stripe.confirmCardPayment(
        result.data.client_secret,
        {
          payment_method: {
            card: card,
            billing_details: {
              name: formDataObj["billing_first_name"]
                ? `${formDataObj["billing_first_name"]} ${formDataObj["billing_last_name"]}`
                : "",
              email: formDataObj["billing_email"] || "",
              address: {
                line1: formDataObj["billing_address_1"] || "",
                line2: formDataObj["billing_address_2"] || "",
                city: formDataObj["billing_city"] || "",
                state: formDataObj["billing_state"] || "",
                postal_code: formDataObj["billing_postcode"] || "",
                country: formDataObj["billing_country"] || "",
              },
            },
          },
        }
      );

      if (error) {
        throw new Error(error.message);
      }

      // Payment successful
      window.location.href = window.location.href + "?payment=success";
    } catch (error) {
      errorElement.textContent = error.message;
      submitButton.disabled = false;
    }
  });
});

jQuery(document).ready(function ($) {
  // Function to copy billing field values to shipping fields
  function copyBillingToShipping() {
    const billingFields = [
      "first_name",
      "last_name",
      "company",
      "address_1",
      "address_2",
      "city",
      "state",
      "postcode",
      "country",
      "phone",
    ];

    billingFields.forEach((field) => {
      const billingValue = $(`[name="billing_${field}"]`).val();
      $(`[name="shipping_${field}"]`).val(billingValue);
    });
  }

  // Toggle shipping fields visibility based on "Same as billing" checkbox
  $("#shipping_same_as_billing").on("change", function () {
    if ($(this).is(":checked")) {
      $(".pfb-shipping-fields").hide();
      // Disable shipping fields and copy billing values
      $('[name^="shipping_"]')
        .not('[name="shipping_same_as_billing"]')
        .prop("disabled", true);
      copyBillingToShipping();
    } else {
      $(".pfb-shipping-fields").show();
      // Enable shipping fields
      $('[name^="shipping_"]')
        .not('[name="shipping_same_as_billing"]')
        .prop("disabled", false);
    }
  });

  // Initialize the state based on the checkbox's initial state
  if ($("#shipping_same_as_billing").is(":checked")) {
    $(".pfb-shipping-fields").hide();
    $('[name^="shipping_"]')
      .not('[name="shipping_same_as_billing"]')
      .prop("disabled", true);
    copyBillingToShipping();
  } else {
    $(".pfb-shipping-fields").show();
    $('[name^="shipping_"]')
      .not('[name="shipping_same_as_billing"]')
      .prop("disabled", false);
  }

  // Copy billing values to shipping when billing fields change and shipping is same as billing
  $('[name^="billing_"]').on("change", function () {
    if ($("#shipping_same_as_billing").is(":checked")) {
      copyBillingToShipping();
    }
  });
});
