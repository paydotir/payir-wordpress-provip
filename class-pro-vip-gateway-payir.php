<?php

if (!defined('ABSPATH')) {

	die('This file cannot be accessed directly');
}

if (!function_exists('init_payir_gateway_pv_class')) {

	add_action('plugins_loaded', 'init_payir_gateway_pv_class');

	function init_payir_gateway_pv_class()
	{
		add_filter('pro_vip_currencies_list', 'currencies_check');

		function currencies_check($list)
		{
			if (!in_array('IRT', $list)) {

				$list['IRT'] = array(

					'name'   => 'تومان ایران',
					'symbol' => 'تومان',
				);
			}

			if (!in_array('IRR', $list)) {

				$list['IRR'] = array(

					'name'   => 'ریال ایران',
					'symbol' => 'ریال',
				);
			}

			return $list;
		}

		if (class_exists('Pro_VIP_Payment_Gateway') && !class_exists('Pro_VIP_Payir_Gateway')) {

			class Pro_VIP_Payir_Gateway extends Pro_VIP_Payment_Gateway
			{
				public

					$id            = 'Payir',
					$settings      = array(),
					$frontendLabel = 'درگاه پرداخت و کیف پول الکترونیک Pay.ir',
					$adminLabel    = 'درگاه پرداخت و کیف پول الکترونیک Pay.ir';

				public function __construct()
				{
					parent::__construct();
				}

				public function beforePayment(Pro_VIP_Payment $payment)
				{
					if (extension_loaded('curl')) {

						$api_key  = $this->settings['api_key'];
						$order_id = $payment->paymentId;
						$callback = add_query_arg('order', $order_id, $this->getReturnUrl());
						$amount   = intval($payment->price);

						if (pvGetOption('currency') == 'IRT') {

							$amount = $amount * 10;
						}

						$params = array(

							'api'          => preg_replace('/\s+/', '', $api_key),
							'amount'       => $amount,
							'redirect'     => urlencode($callback),
							'factorNumber' => $order_id
						);

						$result = $this->common('https://pay.ir/payment/send', $params);

						if ($result && isset($result->status) && $result->status == 1) {

							$payment->key = $order_id;

							$payment->user = get_current_user_id();
							$payment->save();

							$message     = 'شماره تراکنش ' . $result->transId;
							$gateway_url = 'https://pay.ir/payment/gateway/' . $result->transId;

							pvAddNotice($message, 'error');

							wp_redirect($gateway_url);
							exit;

						} else {

							$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
							$message = isset($result->errorMessage) ? $result->errorMessage : $message;

							pvAddNotice($message, 'error');

							$payment->status = 'trash';
							$payment->save();

							wp_die($message);
							exit;
						}

					} else {

						$message = 'تابع cURL در سرور فعال نمی باشد';

						pvAddNotice($message, 'error');

						$payment->status = 'trash';
						$payment->save();

						wp_die($message);
						exit;
					}
                }

				public function afterPayment()
				{
					if (isset($_GET['order'])) {

						$order_id = sanitize_text_field($_GET['order']);

					} else {

						$order_id = NULL;
					}

					if ($order_id) {

                        $payment = new Pro_VIP_Payment($order_id);

						if (isset($_POST['status']) && isset($_POST['transId']) && isset($_POST['factorNumber'])) {

							$status        = sanitize_text_field($_POST['status']);
							$trans_id      = sanitize_text_field($_POST['transId']);
							$factor_number = sanitize_text_field($_POST['factorNumber']);
							$message       = sanitize_text_field($_POST['message']);

							if (isset($status) && $status == 1) {

								$api_key = $this->settings['api_key'];

								$params = array (

									'api'     => preg_replace('/\s+/', '', $api_key),
									'transId' => $trans_id
								);

								$result = $this->common('https://pay.ir/payment/verify', $params);

								if ($result && isset($result->status) && $result->status == 1) {

									$card_number = isset($_POST['cardNumber']) ? sanitize_text_field($_POST['cardNumber']) : 'Null';

									$amount  = intval($payment->price);

									if (pvGetOption('currency') == 'IRT') {

										$amount = $amount * 10;
									}

									if ($amount == $result->amount) {

										$message = 'تراکنش شماره ' . $trans_id . ' با موفقیت انجام شد';

										pvAddNotice($message, 'success');

										$payment->status = 'publish';
										$payment->save();

										$this->paymentComplete($payment);

									} else {

										$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';

										pvAddNotice($message, 'error');

										$payment->status = 'trash';
										$payment->save();

										$this->paymentFailed($payment);
									}

								} else {

									$message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
									$message = isset($result->errorMessage) ? $result->errorMessage : $message;

									pvAddNotice($message, 'error');

									$payment->status = 'trash';
									$payment->save();

									$this->paymentFailed($payment);
								}

							} else {

								$message = $message ? $message : 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';

								pvAddNotice($message, 'error');

								$payment->status = 'trash';
								$payment->save();

								$this->paymentFailed($payment);
							}

						} else {

							$message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';

							pvAddNotice($message, 'error');

							$payment->status = 'trash';
							$payment->save();

							$this->paymentFailed($payment);
						}

					} else {

						$message = 'شماره سفارش ارسال شده غیر معتبر است';

						pvAddNotice($message, 'error');
					}
				}

				public function adminSettings(PV_Framework_Form_Builder $form)
				{
					$form->textfield('api_key')->label('کلید API');
				}

				private static function common($url, $params)
				{
					$ch = curl_init();

					curl_setopt($ch, CURLOPT_URL, $url);
					// curl_setopt($ch, CURLOPT_POST, TRUE);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
					curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

					$response = curl_exec($ch);
					$error    = curl_errno($ch);

					curl_close($ch);

					$output = $error ? FALSE : json_decode($response);

					return $output;
				}
			}

			Pro_VIP_Payment_Gateway::registerGateway('Pro_VIP_Payir_Gateway');
		}
	}
}
