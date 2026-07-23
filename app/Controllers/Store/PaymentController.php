<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Libraries\Money;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * PaymentController — PayU web checkout for a placed-but-unpaid order.
 * pay() builds the PayU hash and renders an auto-submitting redirect form.
 * PayU then POSTs back to payuSuccess() or payuFailure() — no CSRF on those
 * routes because the POST originates from PayU's redirect, not our forms.
 * Hash verification via PayUClient::verifyResponse() is the security check.
 */
final class PaymentController extends BaseStoreController
{
    public function pay(string $orderNo): string|RedirectResponse
    {
        if (! $this->ownsOrder($orderNo)) {
            return redirect()->to('store')->with('error', 'Order not found.');
        }
        $repo    = service('storeOrderRepository');
        $summary = $repo->paymentSummary($orderNo);
        if ($summary === null) {
            return redirect()->to('store')->with('error', 'Order not found.');
        }
        if (($summary['payment_status'] ?? '') === 'paid') {
            return redirect()->to('store/order/' . $orderNo)->with('success', 'This order is already paid.');
        }
        $payu = service('payuClient');
        if (! $payu->configured()) {
            return redirect()->to('store/order/' . $orderNo)->with('error', 'Online payment is currently unavailable.');
        }

        $amount      = number_format((float) $summary['grand_total'], 2, '.', '');
        $txnid       = $orderNo;
        $productinfo = 'Order ' . $orderNo;
        $firstname   = trim((string) ($summary['customer_name'] ?? '')) ?: 'Customer';
        $email       = trim((string) ($summary['customer_email'] ?? ''));
        $phone       = trim((string) ($summary['customer_phone'] ?? ''));
        $hash        = $payu->paymentHash(compact('txnid', 'amount', 'productinfo', 'firstname', 'email'));

        $repo->setGatewayRef($orderNo, $txnid);

        return view('store/pay', [
            'title'       => 'Pay for ' . $orderNo,
            'orderNo'     => $orderNo,
            'amount'      => $amount,
            'payuUrl'     => $payu->isTest() ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment',
            'payuKey'     => $payu->key(),
            'txnid'       => $txnid,
            'productinfo' => $productinfo,
            'firstname'   => $firstname,
            'email'       => $email,
            'phone'       => $phone,
            'hash'        => $hash,
            'surl'        => site_url('store/pay/payu/success'),
            'furl'        => site_url('store/pay/payu/failure'),
        ]);
    }

    /** PayU redirects the user here on successful payment — verify hash, mark paid. */
    public function payuSuccess(): RedirectResponse
    {
        $post    = $this->request->getPost();
        $orderNo = (string) ($post['txnid'] ?? '');
        $payu    = service('payuClient');

        if ($orderNo === '' || ! $this->ownsOrder($orderNo)) {
            return redirect()->to('store')->with('error', 'Order not found.');
        }
        $status = strtolower((string) ($post['status'] ?? ''));
        if ($status !== 'success' || ! $payu->verifyResponse($post)) {
            return redirect()->to('store/order/' . $orderNo)->with('error', 'Payment could not be verified. If money was deducted it will be refunded within 5–7 days.');
        }

        // The reverse hash proves PayU sent this, not that it settles THIS order in
        // full. Re-check the amount against the order before capturing: a forward
        // hash signed for a smaller amount (see the oracle closed in
        // Api\V1\CustomerApiController::payuHash) would otherwise arrive here as a
        // genuinely-valid short payment and mark the order paid.
        $repo    = service('storeOrderRepository');
        $summary = $repo->paymentSummary($orderNo);
        try {
            $paid = Money::of((string) ($post['amount'] ?? ''));
            $owed = Money::of((string) ($summary['grand_total'] ?? '0'));
        } catch (\InvalidArgumentException $e) {
            $paid = $owed = null;
        }
        if ($paid === null || $owed === null || ! $paid->equals($owed)) {
            log_message('critical', 'PayU amount mismatch on order ' . $orderNo . ' — payment not captured.');

            return redirect()->to('store/order/' . $orderNo)->with('error', 'Payment could not be verified. If money was deducted it will be refunded within 5–7 days.');
        }

        $repo->markPaid($orderNo, (string) ($post['mihpayid'] ?? $orderNo));

        return redirect()->to('store/order/' . $orderNo)->with('success', 'Payment successful — your order is confirmed!');
    }

    /** PayU redirects the user here on payment failure or cancellation. */
    public function payuFailure(): RedirectResponse
    {
        $orderNo = (string) ($this->request->getPost('txnid') ?? '');
        $target  = $orderNo !== '' ? 'store/order/' . $orderNo : 'store';

        return redirect()->to($target)->with('error', 'Payment was not completed. You can try paying again from My Orders.');
    }
}
