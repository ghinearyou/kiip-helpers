<?php
function larkBot()
{
  if (get_option('order_notification_module') !== 'yes') {
    return;
  }

  $input1 = get_option('onm_input_bot_url', '');
  $input2 = get_option('onm_input_web_name', '');
  $input3 = get_option('onm_input_web_url', '');
  
  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => $input1,
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
															"text": ' . $input2 . ',
															"href": ' . $input3 . '
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