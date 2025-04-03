document.addEventListener("DOMContentLoaded", function () {
  const stripe = Stripe(pfbData.publicKey);
  const elements = stripe.elements();
  const card = elements.create("card");
  card.mount("#card-element");

  // Add new helper functions
  function showSuccessToast(message) {
    let toastContainer = document.querySelector(".pfb-toast-container");
    if (!toastContainer) {
      toastContainer = document.createElement("div");
      toastContainer.className = "pfb-toast-container";
      document.body.appendChild(toastContainer);
    }

    const toast = document.createElement("div");
    toast.className = "pfb-toast";
    toast.innerHTML = `
      <div class="pfb-toast-content">
        <svg class="pfb-toast-icon" viewBox="0 0 24 24">
          <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
        </svg>
        <span>${message}</span>
      </div>
    `;

    toastContainer.appendChild(toast);
    setTimeout(() => toast.classList.add("show"), 100);
    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 300);
    }, 5000);
  }

  function setFormLoading(form, isLoading) {
    const submitButton = form.querySelector('button[type="submit"]');
    const loadingOverlay = form.querySelector(".pfb-loading-overlay");

    if (isLoading) {
      if (!loadingOverlay) {
        const overlay = document.createElement("div");
        overlay.className = "pfb-loading-overlay";
        overlay.innerHTML = `
          <div class="pfb-spinner"></div>
          <div class="pfb-loading-text">Processing payment...</div>
        `;
        form.appendChild(overlay);
      }
      submitButton.disabled = true;
    } else {
      if (loadingOverlay) {
        loadingOverlay.remove();
      }
      submitButton.disabled = false;
    }
  }

  function resetForm(form) {
    form.reset();
    card.clear();
    const errorElement = document.getElementById("card-errors");
    if (errorElement) {
      errorElement.textContent = "";
    }
  }

  function scrollToTop(form) {
    const formTop = form.getBoundingClientRect().top + window.pageYOffset - 100;
    window.scrollTo({ top: formTop, behavior: "smooth" });
  }

  const form = document.querySelector(".payment-form");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const errorElement = document.getElementById("card-errors");
    errorElement.textContent = "";

    try {
      setFormLoading(form, true);

      const formData = new FormData(form);
      const formId = form.id.replace("payment-form-", "");
      const formDataObj = Object.fromEntries(formData);

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
          initial_request: true,
        }).toString(),
        credentials: "same-origin",
      });

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.data.message || "Payment initialization failed");
      }

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

        // Show success toast, reset form, and scroll to top instead of page reload
        showSuccessToast("Payment successful! Thank you for your purchase.");
        scrollToTop(form);
        resetForm(form);
      }
    } catch (error) {
      errorElement.textContent = error.message;
    } finally {
      setFormLoading(form, false);
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
