// ------------------------------------------------------------------
// Timbratura geolocalizzata
// ------------------------------------------------------------------
const timbraturaForm = document.getElementById('timbratura-form');
const geoStatus = document.getElementById('geo-status');
const geoLat = document.getElementById('geo-lat');
const geoLng = document.getElementById('geo-lng');
const timbraturaSubmit = document.getElementById('timbratura-submit');

function initializeTimbratura() {
  if (!timbraturaForm || !geoStatus || !geoLat || !geoLng || !timbraturaSubmit) {
    return;
  }

  const bounds = readTimbraturaBounds();

  const setStatus = (message, { allowed = false, error = false } = {}) => {
    geoStatus.textContent = message;
    geoStatus.classList.toggle('ok', allowed);
    geoStatus.classList.toggle('error', error);
    timbraturaSubmit.disabled = !allowed;
  };

  const handleSuccess = ({ coords }) => {
    const latitude = coords.latitude;
    const longitude = coords.longitude;

    geoLat.value = String(latitude);
    geoLng.value = String(longitude);

    if (isWithinAllowedArea(latitude, longitude, bounds)) {
      setStatus('Posizione verificata. Timbratura abilitata.', { allowed: true });
      return;
    }

    setStatus('Sei fuori dall area autorizzata per la timbratura.', { error: true });
  };

  const handleError = () => {
    geoLat.value = '';
    geoLng.value = '';
    setStatus('Impossibile verificare la posizione. Consenti la geolocalizzazione per timbrare.', { error: true });
  };

  if (!navigator.geolocation) {
    handleError();
    return;
  }

  setStatus('Verifica posizione in corso...');

  const geolocationOptions = {
    enableHighAccuracy: true,
    timeout: 10000,
    maximumAge: 0,
  };

  navigator.geolocation.getCurrentPosition(handleSuccess, handleError, geolocationOptions);
  navigator.geolocation.watchPosition(handleSuccess, handleError, geolocationOptions);
}

function readTimbraturaBounds() {
  return {
    latMin: Number(timbraturaForm.dataset.latMin),
    latMax: Number(timbraturaForm.dataset.latMax),
    lngMin: Number(timbraturaForm.dataset.lngMin),
    lngMax: Number(timbraturaForm.dataset.lngMax),
  };
}

function isWithinAllowedArea(latitude, longitude, bounds) {
  return latitude >= bounds.latMin
    && latitude <= bounds.latMax
    && longitude >= bounds.lngMin
    && longitude <= bounds.lngMax;
}

// ------------------------------------------------------------------
// Login form
// ------------------------------------------------------------------
function togglePassword() {
  const passwordInput = document.getElementById('password');
  const toggleButton = document.querySelector('.toggle-pw');

  if (!passwordInput || !toggleButton) {
    return;
  }

  const isHidden = passwordInput.type === 'password';

  passwordInput.type = isHidden ? 'text' : 'password';
  toggleButton.textContent = isHidden ? 'nascondi' : 'mostra';
}

initializeTimbratura();
