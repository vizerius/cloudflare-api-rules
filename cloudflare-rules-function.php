<?php
// Отдельная функция для установки Redirect Rules конкретным доменам, чтобы вставлять в свои скрипты для работы с CloudFlare API.

$domain 		= 'domain.com';
$zone_id 		= '';
$rules_token 	= '';

$rules = [
	//правило 1
	[
		'action' => 'redirect',
		'action_parameters' => [
			'from_value' => [
				'preserve_query_string' => false,
				'status_code' => 307,
				'target_url' => [
					'value' => 'https://' . $domain . '/'
				]
			]
		],
		'description' => 'Google only',
		'enabled' => true,
		'expression' => '(ip.geoip.asnum ne 15169 and ip.src.asnum ne 15169 and ip.geoip.asnum ne 20940 and ip.src.asnum ne 20940 and ip.geoip.asnum ne 15180 and ip.src.asnum ne 15180 and ip.geoip.asnum ne 36040 and ip.src.asnum ne 36040 and ip.geoip.asnum ne 15192 and ip.src.asnum ne 15192 and http.request.uri.path ne "/")',
	],
		
	//... можно еще и другие правила добавить
];

// добавить правило в Redirect Rules
cf_set_redirect_rules( $zone_id, $rules_token, $rules );

// удалить все правила в Redirect Rules (вызов без 3-го параметра)
cf_set_redirect_rules( $zone_id, $rules_token );



///////////////////////////////////////////////////////////////////////////////

function cf_set_redirect_rules( $zone_id, $rules_token, $rules = [] ) {
	// Функция возвращает true - если успешная операция, false - неудача.
	// Если массив $rules пустой, или вызов без 3-го параметра - происходит операция удаления всех правил в Redirect Rules.
	
	if ( empty( $rules ) ) {
		$new_ruleset = json_encode( [ 'name' => 'Rule Set',	'rules' => [], ] );
	} else {
		$new_ruleset = json_encode( [ 'name' => 'Rule Set',	'rules' => $rules, ] );
	}	
	
    $ch = curl_init();

    curl_setopt( $ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/rulesets/phases/http_request_dynamic_redirect/entrypoint' );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
	curl_setopt( $ch, CURLOPT_DNS_CACHE_TIMEOUT, 300 );
	curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
	curl_setopt( $ch, CURLOPT_NOSIGNAL, true );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );	
    curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Authorization: Bearer ' . $rules_token, 'Content-Type: application/json' ] );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $new_ruleset );

    $response = curl_exec( $ch );

    if ( curl_errno( $ch ) ) {
        curl_close( $ch );
        return false;
    }

    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );

    if ( 200 !== $http_code ) {
        return false;
    }

    $data = json_decode( $response, true );

    if ( ! empty( $data['success'] ) ) {
        return true;
    }

    return false;
}