<?php
function larkBot()
{
  if (get_option('order_notification_module') !== 'yes') {
    return;
  }

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://open.larksuite.com/open-apis/bot/v2/hook/6985acde-fd63-426b-b9a6-75616f055ce3',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => '{
			"msg_type": "post",
			"content": {
					"post": {
							"zh_cn": {
									"content": [
											[
													{
								"tag": "text",
								"text": "New Order at "
							},{
															"tag": "a",
															"text": "KiiP",
															"href": "https://www.kiipkiip.com/"
							}
											]
									]
							}
					}
			}
		}',
    CURLOPT_HTTPHEADER => array(
      'Content-Type: application/json'
    ),
  ));

  curl_exec($curl);
  curl_close($curl);
}
add_action('woocommerce_payment_complete', 'larkBot');
?>