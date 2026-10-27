// Make sure to access Vue reactivity primitives from the global Vue object
const { ref, onMounted } = Vue;

export function usePrograms() {
  const programs = ref([]);
  const loading = ref(true);
  const error = ref(null);

  const fetchPrograms = async () => {
    try {
      const request = await fetch(
        "https://kifalatrust.org/dist/admin/kifala_api.php?action=get_programs",
      );
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
      const request = await fetch(
        "https://kifalatrust.org/dist/admin/kifala_api.php?action=get_gallery",
      );
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
