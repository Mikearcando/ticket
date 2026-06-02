const filters = document.querySelector('.filters');
if (filters) {
  const key = 'ticketFilters';
  filters.addEventListener('change', () => {
    const data = new FormData(filters);
    sessionStorage.setItem(key, new URLSearchParams(data).toString());
  });
}
