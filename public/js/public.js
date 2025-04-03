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
        throw new Error(result.data || "Payment processing failed");
      }

      // Then confirm the card payment
      const { paymentIntent, error } = await stripe.confirmCardPayment(
        result.data.client_secret,
        {
          payment_method: {
            card: card,
            billing_details: {
              name: formDataObj["Name"] || "",
              email: formDataObj["Email Address"] || "",
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
      const errorElement = document.getElementById("card-errors");
      errorElement.textContent = error.message;
      submitButton.disabled = false;
    }
  });
});

jQuery(document).ready(function ($) {
  // Toggle shipping fields visibility based on "Same as billing" checkbox
  $("#shipping_same_as_billing").on("change", function () {
    if ($(this).is(":checked")) {
      $(".pfb-shipping-fields").hide();
    } else {
      $(".pfb-shipping-fields").show();
    }
  });

  // Initialize the state based on the checkbox's initial state
  if ($("#shipping_same_as_billing").is(":checked")) {
    $(".pfb-shipping-fields").hide();
  } else {
    $(".pfb-shipping-fields").show();
  }
});
