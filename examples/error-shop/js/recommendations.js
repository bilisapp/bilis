async function fetchRecommendations() {
  const response = await fetch('reco.json');
  const text = await response.text();
  return JSON.parse(text);           // reco.json is truncated: SyntaxError
}

async function loadRecommendations() {
  try {
    return await fetchRecommendations();
  } catch (cause) {
    throw new Error('Failed to load product recommendations', { cause });
  }
}

async function applyCoupon(code) {
  const response = await fetch('cart.json');
  const cart = await response.json();
  if (!cart.coupons.includes(code)) {
    throw new TypeError(`Coupon ${code} is not valid for this cart`);
  }
}

function estimateShipping(weightKg) {
  const zones = new Array(weightKg * -1);   // negative length: RangeError
  return zones.length;
}
