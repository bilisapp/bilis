document.getElementById('view-cart').addEventListener('click', () => {
  log('Rendering cart…');
  renderCart();   // unhandled: the SDK's global handler reports it
});

document.getElementById('pay').addEventListener('click', () => {
  log('Submitting payment…');
  submitPayment();
});

document.getElementById('recommend').addEventListener('click', async () => {
  log('Loading recommendations…');
  try {
    await loadRecommendations();
  } catch (error) {
    Sentry.captureException(error);
    log('Recommendations failed — reported with its cause.');
  }
});

document.getElementById('coupon').addEventListener('click', () => {
  log('Applying coupon SPRING25…');
  applyCoupon('SPRING25');   // not awaited: an unhandled rejection
});

document.getElementById('shipping').addEventListener('click', () => {
  log('Estimating shipping…');
  setTimeout(() => estimateShipping(3), 0);
});
