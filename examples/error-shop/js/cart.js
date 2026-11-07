// Cart summary: assumes every cart has a totals object. Guest carts do not.
function lineItemCount(cart) {
  return cart.lineItems.reduce((sum, item) => sum + item.quantity, 0);
}

function cartSummary(cart) {
  const items = lineItemCount(cart);
  const total = cart.totals.grand.amount;   // throws for a guest cart
  return `${items} items, ${total} EUR`;
}

function renderCart() {
  const guestCart = { lineItems: [{ sku: 'TEE-01', quantity: 2 }] };
  return cartSummary(guestCart);
}
