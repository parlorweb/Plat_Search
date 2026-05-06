(() => {
  const searchInput = document.getElementById('searchInput');
  const nameInput = document.getElementById('nameInput');
  const searchBtn = document.getElementById('searchBtn');
  const availabilityBtn = document.getElementById('availabilityBtn');
  const reserveBtn = document.getElementById('reserveBtn');
  const statusEl = document.getElementById('status');
  const resultsBody = document.getElementById('resultsBody');

  const pending = {
    search: false,
    availability: false,
    reserve: false,
  };

  const setStatus = (message, type = 'info') => {
    statusEl.textContent = message;
    statusEl.style.color = type === 'error' ? '#b00020' : '#006400';
  };

  const normalizeName = (value) => value.trim();

  const getArrayPayload = (payload) => {
    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.results)) return payload.results;
    if (Array.isArray(payload?.items)) return payload.items;
    return [];
  };

  const getNameFromItem = (item) => {
    if (typeof item === 'string') return item;
    if (item && typeof item === 'object') {
      return item.name ?? item.value ?? JSON.stringify(item);
    }
    return String(item ?? '');
  };

  const setBusy = (key, busy, button) => {
    pending[key] = busy;
    button.disabled = busy;
  };

  const validateNonEmpty = (value, message) => {
    if (!value) {
      setStatus(message, 'error');
      return false;
    }
    return true;
  };

  const renderResults = (items) => {
    resultsBody.innerHTML = '';
    if (!items.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 1;
      td.textContent = 'No results found.';
      tr.appendChild(td);
      resultsBody.appendChild(tr);
      return;
    }

    items.forEach((item) => {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.textContent = getNameFromItem(item);
      tr.appendChild(td);
      resultsBody.appendChild(tr);
    });
  };

  const runSearch = async () => {
    if (pending.search) return;

    const query = normalizeName(searchInput.value);
    if (!validateNonEmpty(query, 'Please enter a search term.')) return;

    setBusy('search', true, searchBtn);
    try {
      const response = await fetch(`/api/search?q=${encodeURIComponent(query)}`);
      if (!response.ok) throw new Error(`Search failed (${response.status})`);
      const payload = await response.json();
      const items = getArrayPayload(payload);
      renderResults(items);
      setStatus(`Found ${items.length} result${items.length === 1 ? '' : 's'}.`);
    } catch (error) {
      setStatus(error.message || 'Search request failed.', 'error');
    } finally {
      setBusy('search', false, searchBtn);
    }
  };

  const checkAvailability = async () => {
    if (pending.availability) return;

    const name = normalizeName(nameInput.value);
    if (!validateNonEmpty(name, 'Please enter a name.')) return;

    setBusy('availability', true, availabilityBtn);
    try {
      const response = await fetch(`/api/availability?name=${encodeURIComponent(name)}`);
      if (!response.ok) throw new Error(`Availability check failed (${response.status})`);
      const payload = await response.json();
      const available = Boolean(payload?.available);

      if (available) {
        setStatus('Name is available.');
      } else {
        setStatus('Name already exists.');
      }
    } catch (error) {
      setStatus(error.message || 'Availability request failed.', 'error');
    } finally {
      setBusy('availability', false, availabilityBtn);
    }
  };

  const reserveName = async () => {
    if (pending.reserve) return;

    const name = normalizeName(nameInput.value);
    if (!validateNonEmpty(name, 'Please enter a name.')) return;

    setBusy('reserve', true, reserveBtn);
    try {
      const response = await fetch('/api/reserve', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ name }),
      });

      if (!response.ok) {
        if (response.status === 409) {
          setStatus('Name already exists.');
          return;
        }
        throw new Error(`Reservation failed (${response.status})`);
      }

      setStatus('Reserved successfully.');
      const activeQuery = normalizeName(searchInput.value) || name;
      searchInput.value = activeQuery;
      await runSearch();
    } catch (error) {
      setStatus(error.message || 'Reservation request failed.', 'error');
    } finally {
      setBusy('reserve', false, reserveBtn);
    }
  };

  searchBtn.addEventListener('click', runSearch);
  availabilityBtn.addEventListener('click', checkAvailability);
  reserveBtn.addEventListener('click', reserveName);

  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') runSearch();
  });

  nameInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') checkAvailability();
  });
})();
