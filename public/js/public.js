document.addEventListener("DOMContentLoaded", function () {
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

    try {
      // Disable submit button
      submitButton.disabled = true;

      // Get form data
      const formData = new FormData(form);
      const formId = form.id.replace("payment-form-", "");
      const formDataObj = Object.fromEntries(formData);

      // First, send form data to get payment intent
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
          initial_request: true, // Add this flag
        }).toString(),
        credentials: "same-origin",
      });

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.data.message || "Payment initialization failed");
      }

      // Now confirm the card payment with Stripe
      const { paymentIntent, error } = await stripe.confirmCardPayment(
        result.data.client_secret,
        {
          payment_method: {
            card: card,
            billing_details: {
              name:
                formDataObj["billing_first_name"] +
                " " +
                formDataObj["billing_last_name"],
              email: formDataObj["billing_email"],
            },
          },
        }
      );

      if (error) {
        throw new Error(error.message);
      }

      // If payment is successful, save the order
      if (paymentIntent.status === "succeeded") {
        const saveOrderResponse = await fetch(pfbData.ajaxUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: new URLSearchParams({
            action: "save_payment_order",
            nonce: pfbData.nonce,
            form_id: formId,
            form_data: JSON.stringify(formDataObj),
            payment_intent_id: paymentIntent.id,
            payment_status: paymentIntent.status,
          }).toString(),
          credentials: "same-origin",
        });

        const saveResult = await saveOrderResponse.json();
        if (!saveResult.success) {
          throw new Error(saveResult.data.message || "Failed to save order");
        }

        // Redirect to success page
        window.location.href = window.location.href + "?payment=success";
      }
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
