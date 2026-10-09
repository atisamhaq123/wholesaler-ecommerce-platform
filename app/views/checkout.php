<div class="page-heading">
    <div>
        <div class="eyebrow">YOUR NEXT BUSINESS MOVE</div>
        <h1>Checkout #<?= $checkout['id'] ?></h1>
        <p>Payment status: <strong><?= e(str_replace('_', ' ', $checkout['status'])) ?></strong></p>
    </div><a href="/orders">Your orders →</a>
</div>
<div class="checkout-grid">
    <div>
        <div class="alert <?= $checkout['mode'] === 'demo' ? 'alert-warning' : 'alert-info' ?>">
            <?= $checkout['mode'] === 'demo' ? 'DEMO CHECKOUT — No real payment or supplier payout will occur.' : 'STRIPE TEST CHECKOUT — Use test payment methods only.' ?>
        </div><?php if (isset($_GET['returned']) && $checkout['status'] === 'pending'): ?>
            <div class="alert alert-info">Waiting for a verified payment webhook. Returning from the provider does not
                confirm payment. Refresh this page shortly.</div>
        <?php endif; ?><?php foreach ($orders as $o):
             $snapshot = json_decode($o['supplier_snapshot'], true); ?>
            <section class="panel">
                <h2><?= e($snapshot['name']) ?></h2>
                <p><?= e(ucfirst($o['fulfillment'])) ?> · <?= e($o['instructions']) ?></p>
                <div class="total-row"><span>Supplier order
                        #<?= $o['id'] ?></span><strong><?= money($o['total']) ?></strong></div><a
                    href="/order?id=<?= $o['id'] ?>">View order details →</a>
            </section><?php endforeach; ?>
    </div>
    <section class="panel checkout-summary">
        <h2>Order summary</h2>
        <?php foreach (['subtotal' => 'Merchandise', 'delivery' => 'Delivery', 'tax' => 'Tax', 'fee' => 'Buyer fee'] as $key => $label): ?>
            <div class="total-row"><span><?= $label ?></span><strong><?= money($checkout[$key]) ?></strong></div>
        <?php endforeach; ?>
        <hr>
        <div class="total-row grand-total"><span>Total</span><strong><?= money($checkout['total']) ?></strong></div>
        <small>Buyer fee <?= $checkout['fee_bps'] / 100 ?>%, applied to
            <?= $checkout['fee_basis'] === 'merchandise_plus_delivery' ? 'merchandise plus delivery' : 'merchandise' ?>. Tax
            <?= $checkout['tax_bps'] / 100 ?>%. Each supplier fee rounds half-up to the nearest minor
            unit.</small><?php if ($checkout['status'] === 'pending'): ?>
            <p>Reserved until <?= e($checkout['expires_at']) ?> UTC.</p>
            <form action="<?= $checkout['mode'] === 'demo' ? '/checkout/demo' : '/checkout/pay' ?>" method="post">
                <?= csrf() ?><input type="hidden" name="id" value="<?= $checkout['id'] ?>"><button
                    class="btn btn-primary w-100"><?= $checkout['mode'] === 'demo' ? 'Confirm demo transaction' : 'Continue to Stripe test checkout' ?>
                    →</button></form>
            <form action="/checkout/cancel" method="post"><?= csrf() ?><input type="hidden" name="id"
                    value="<?= $checkout['id'] ?>"><button class="btn btn-outline-secondary w-100 mt-3">Cancel
                    checkout</button></form><?php else: ?><a class="btn btn-primary w-100 mt-3" href="/orders">View your
                orders</a><?php endif; ?>
    </section>
</div>