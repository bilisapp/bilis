class PaymentDeclinedError extends Error {
  constructor(last4, reason) {
    super(`Card ending ${last4} was declined by the issuer (${reason})`);
    this.name = 'PaymentDeclinedError';
  }
}

function chargeCard(order, card) {
  // Pretend the gateway answered 402.
  if (card.last4 === '4242') {
    throw new PaymentDeclinedError(card.last4, 'insufficient_funds');
  }
  return { status: 'paid', order: order.id };
}

function submitPayment() {
  const order = { id: 'ord_' + Math.floor(Math.random() * 100000), total: 129.9 };
  try {
    return chargeCard(order, { last4: '4242' });
  } catch (error) {
    // A handled error: the checkout shows a message, and the error is reported.
    Sentry.withScope((scope) => {
      scope.setTag('payment.gateway', 'stripe');
      scope.setContext('order', { id: order.id, total: order.total });
      Sentry.captureException(error);
    });
    log('Payment declined — reported.');
  }
}
