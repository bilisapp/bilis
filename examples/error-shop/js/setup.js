// Which project, user, release and environment this page load pretends to be.
// Everything comes from the query string so a scenario is a link:
//   ?dsn=…&user=cus_1aQ7&release=storefront@1.4.0&env=staging&region=us-east
const params = new URLSearchParams(location.search);
const user = params.get('user') || 'cus_9fK2';
const release = params.get('release') || 'storefront@1.5.0';
const environment = params.get('env') || 'production';

// The DSN is never committed: ?dsn= wins, then the last one used in this browser.
let dsn = params.get('dsn') || localStorage.getItem('error-shop.dsn') || '';

document.getElementById('dsn').value = dsn;
document.getElementById('dsn-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const value = document.getElementById('dsn').value.trim();
  localStorage.setItem('error-shop.dsn', value);
  params.set('dsn', value);
  location.search = params.toString();
});

function log(line) {
  console.log(line);
  document.getElementById('log').textContent += line + '\n';
}

if (dsn) {
  localStorage.setItem('error-shop.dsn', dsn);

  Sentry.init({
    dsn,
    release,
    environment,
    sampleRate: 1.0,
    // Report an error's `cause` chain, so chained errors arrive with their causes.
    integrations: [Sentry.linkedErrorsIntegration({ depth: 5 })],
  });

  Sentry.setUser({ id: user, email: `${user}@example.com` });
  // Bilis reads the service name from this tag.
  Sentry.setTag('service', 'storefront-web');
  Sentry.setTag('region', params.get('region') || 'eu-west');

  document.querySelectorAll('button[id]').forEach((button) => { button.disabled = false; });
  document.getElementById('who').textContent = `${user} · ${release} · ${environment} → ${new URL(dsn).host}`;
} else {
  document.getElementById('who').textContent = 'Paste a project DSN above to start.';
}
