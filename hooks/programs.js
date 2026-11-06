// Make sure to access Vue reactivity primitives from the global Vue object
const { ref, onMounted } = Vue;
const accessToken = "Bearer dfsdgs435435kifala.orgdsfdsfa22342222@@222";
const apis = {
  activeProgram:
    "https://kifalatrust.org/admin/kifala_api.php?action=get_active_programs",
  programs: "https://kifalatrust.org/admin/kifala_api.php?action=get_donations",
  program: "https://kifalatrust.org/admin/kifala_api.php?action=get_donation",
  contact: "https://kifalatrust.org/admin/kifala_api.php?action=submit_enquiry",
  fprogram:
    "https://kifalatrust.org/admin/kifala_api.php?action=get_featured_program",
  gallery: "https://kifalatrust.org/admin/kifala_api.php?action=get_gallery",
  newsletter:
    "https://kifalatrust.org/admin/kifala_api.php?action=subscribe_newsletter",
};

export function useSingleProgram() {
  const program = ref({});
  const loading = ref(true);
  const error = ref(null);

  const fetchProgram = async (id) => {
    loading.value = true;
    error.value = null;

    try {
      const url = new URL(apis.program);
      url.searchParams.set("program_id", id);

      const request = await fetch(url, {
        headers: {
          Authorization: accessToken,
        },
      });
      const response = await request.json();
      if (!request.ok || response.status !== "success") {
        throw new Error(response.message || "Failed to fetch program");
      }

      program.value = response.data;
    } catch (err) {
      error.value =
        err instanceof Error ? err.message : "Failed to fetch program";
    } finally {
      loading.value = false;
    }
  };

  onMounted(() => {
    // Extract the ID from the browser window URL
    const urlParams = new URLSearchParams(window.location.search);
    const programId = urlParams.get("program_id");
    if (programId) {
      fetchProgram(programId);
    } else {
      error.value = "Program ID is missing.";
      loading.value = false;
    }
  });

  return { program, loading, error, refresh: fetchProgram };
}

export function useFeaturedProgram() {
  const program = ref({});
  const loading = ref(true);
  const error = ref(null);

  const fetchProgram = async () => {
    loading.value = true;
    error.value = null;

    try {
      const request = await fetch(apis.fprogram, {
        headers: {
          Authorization: accessToken,
        },
      });
      const response = await request.json();
      if (!request.ok || response.status !== "success") {
        throw new Error(response.message || "Failed to fetch program");
      }

      program.value = response.data;
    } catch (err) {
      error.value =
        err instanceof Error ? err.message : "Failed to fetch program";
    } finally {
      loading.value = false;
    }
  };

  onMounted(() => {
    fetchProgram();
  });

  return { program, loading, error, refresh: fetchProgram };
}

export function usePrograms() {
  const programs = ref([]);
  const loading = ref(true);
  const error = ref(null);

  const fetchPrograms = async () => {
    try {
      const request = await fetch(apis.programs, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Authorization: accessToken,
        },
      });
      if (!request.ok) throw new Error("Failed to fetch programs");
      const response = await request.json();
      programs.value = response.data;
    } catch (err) {
      error.value = err.message;
    } finally {
      loading.value = false;
    }
  };

  onMounted(() => {
    fetchPrograms();
  });

  return { programs, loading, error, refresh: fetchPrograms };
}

export function useGallery() {
  const gallery = ref([]);
  const loading = ref(true);
  const error = ref(null);

  const fetchGallery = async () => {
    try {
      const request = await fetch(apis.gallery, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Authorization: accessToken,
        },
      });
      if (!request.ok) throw new Error("Failed to fetch gallery");
      const response = await request.json();
      gallery.value = response.data;
    } catch (err) {
      error.value = err.message;
    } finally {
      loading.value = false;
    }
  };

  onMounted(() => {
    fetchGallery();
  });

  return { gallery, loading, error, refresh: fetchGallery };
}

export function useNewsletterApis() {
  const newsletterEmail = ref("");
  const newsletterStatus = ref("");
  const isSubmitting = ref(false);

  async function submitNewsletter() {
    isSubmitting.value = true;
    newsletterStatus.value = "Subscribing...";

    try {
      const response = await fetch(apis.newsletter, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: accessToken,
        },
        body: JSON.stringify({
          email: newsletterEmail.value,
        }),
      });
      const result = await response.json();

      if (!response.ok || result.status !== "success") {
        throw new Error(
          result.message || "Unable to subscribe right now. Please try again.",
        );
      }

      newsletterEmail.value = "";
      newsletterStatus.value =
        result.message || "You have successfully subscribed.";
    } catch (error) {
      newsletterStatus.value =
        error instanceof Error
          ? error.message
          : "Unable to subscribe right now. Please try again.";
    } finally {
      isSubmitting.value = false;
    }
  }

  return {
    newsletterEmail,
    newsletterStatus,
    isSubmitting,
    submitNewsletter,
  };
}

export function useContactApis() {
  const createContactBody = () => ({
    name: "",
    email: "",
    phone: "",
    subject: "",
    message: "",
  });
  const contactBody = ref(createContactBody());
  const contactStatus = ref("");
  const isSubmittingContact = ref(false);

  async function submitContact() {
    isSubmittingContact.value = true;
    contactStatus.value = "Sending message...";

    try {
      const response = await fetch(apis.contact, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: accessToken,
        },
        body: JSON.stringify(contactBody.value),
      });
      const result = await response.json();

      if (!response.ok || result.status !== "success") {
        throw new Error(
          result.message || "Unable to send your message right now. Please try again.",
        );
      }

      contactBody.value = createContactBody();
      contactStatus.value =
        result.message || "Your message has been sent.";
    } catch (error) {
      contactStatus.value =
        error instanceof Error
          ? error.message
          : "Unable to send your message right now. Please try again.";
    } finally {
      isSubmittingContact.value = false;
    }
  }

  return {
    contactBody,
    contactStatus,
    isSubmittingContact,
    submitContact,
  };
}
