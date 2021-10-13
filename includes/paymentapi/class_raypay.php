<?php
if (!isset($GLOBALS['vbulletin']->db)) exit;

class vB_PaidSubscriptionMethod_raypay extends vB_PaidSubscriptionMethod
{
    /**
     * @var bool
     */
    var $supports_recurring = false;

    /**
     * @var bool
     */
    var $display_feedback = true;

    /**
     * @return bool
     */
    function verify_payment()
    {
        $orderid = $_GET['order_id'];
        $payment_method =  $_GET['method'];


        if (!empty($orderid) && !empty($payment_method) && $payment_method == "raypay") {
            $this->paymentinfo = $this->registry->db->query_first("SELECT paymentinfo.*, user.username FROM " . TABLE_PREFIX . "paymentinfo AS paymentinfo INNER JOIN " . TABLE_PREFIX . "user AS user USING (userid) WHERE hash = '" . $orderid . "'");

            if (!empty($this->paymentinfo)) {
                $sub = $this->registry->db->query_first("SELECT * FROM " . TABLE_PREFIX . "subscription WHERE subscriptionid = " . $this->paymentinfo['subscriptionid']);

                $cost = unserialize($sub['cost']);
                $amount = floor($cost[0][cost][usd] * $this->settings['currency_rate']);
                    $url = 'https://api.raypay.ir/raypay/api/v1/payment/verify';
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_POST));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',));

                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_status != 200) {
                        $this->error = sprintf('خطا هنگام بررسی وضعیت تراکنش. کد خطا: %s', $http_status);
                        return false;
                    }

                    $verify_status = empty($result->Data->Status) ? NULL : $result->Data->Status;
                    $verify_invoice_id = empty($result->Data->InvoiceID) ? NULL : $result->Data->InvoiceID;
                    $this->transaction_id = $verify_invoice_id;

                    if (empty($verify_status) || empty($verify_invoice_id)  || $verify_status != 1) {
                        $this->error = 'پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.';
                        $response['result'] = 'پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.';
                        return false;
                    } else {
                        $this->paymentinfo['currency'] = 'usd';
                        $this->paymentinfo['amount'] = $amount;
                        $this->type = 1;
                        return true;
                    }
            } else {
                $msg = 'اطلاعات سفارش یدا نشد.';
                $this->error_code = $msg;
                $this->error = $msg;
                return false;
            }
        } else {
            $msg = 'خطا در بازگشت از درگاه';
            $this->error_code = $msg;
            $this->error = $msg;
            return false;
        }
    }

    /**
     * @param $hash
     * @param $cost
     * @param $currency
     * @param $subinfo
     * @param $userinfo
     * @param $timeinfo
     * @return array|bool|string
     */
    function generate_form_html($hash, $cost, $currency, $subinfo, $userinfo, $timeinfo)
    {
        global $vbphrase, $vbulletin, $show;

        $response['state'] = false;
        $response['result'] = "";

        $user_id = $this->settings['user_id'];
        $marketing_id = $this->settings['marketing_id'];
        $sandbox = $this->settings['sandbox'] == 1 ? true : false;

        $amount = floor($cost * $this->settings['currency_rate']);

        $desc = "خرید اشتراک در سامانه ویبولتین توسط" . $userinfo['username'];
        $callback = vB::$vbulletin->options['bburl'] . '/payment_gateway.php?method=raypay&order_id=' .$hash ;
        $invoice_id             = round(microtime(true) * 1000);

        if (empty($amount)) {
            echo 'واحد پول انتخاب شده پشتیبانی نمی شود.';
            return false;
        }

        $data = array(
            'factorNumber' => strval($hash),
            'amount' => strval($amount),
            'userID' => $user_id,
            'marketingID' => $marketing_id,
            'invoiceID'    => strval($invoice_id),
            'desc' => $desc,
            'redirectUrl' => $callback,
            'fullName' => $userinfo['username'],
            'enableSandBox' => $sandbox
        );

        $url = 'https://api.raypay.ir/raypay/api/v1/payment/pay';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 200 || empty($result)) {
            echo 'ERR: '. sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status);
            return false;
        } else {
            $token = $result->Data;
            $link='https://my.raypay.ir/ipg?token=' . $token;
            header('Location: ' . $link);
            exit();
        }
    }

    /**
     * @return bool
     */
    function test()
    {
        if (!empty($this->settings['user_id']) AND !empty($this->settings['marketing_id']) AND !empty($this->settings['currency_rate'])) {
            return true;
        }
        return false;
    }
}
