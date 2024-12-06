<?php

opcache_invalidate( __FILE__, 0 );

set_time_limit(0);
error_reporting( E_ERROR | E_PARSE );
//error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
ini_set( 'memory_limit', '1024M' );
header( 'X-Accel-Buffering: no' );

global $_;

$_ = [];

$_['cf_accounts_file'] 	= __DIR__ . '/cf_accounts.txt'; // Формат account@email;global_key;rules_token
$_['domains_file'] 		= __DIR__ . '/domains.txt'; // список доменов, которым надо установить правила

$_['threads_num']   	= 30; // максимальное количество потоков, до 40 без прокси

$_['cf_accounts_arr'] 	= file( $_['cf_accounts_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$_['domains_arr'] 		= file( $_['domains_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

$_['export_dir'] 	= __DIR__ . '/export';
$_['todo_dir'] 		= __DIR__ . '/todo';

function rules( $domain ) {
    global $_;
	
	return [
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
}






/////////////////////////////////////////////////////////////////////////////////////////////

echo '<pre>';

$_['start_time_total'] = microtime ( true );

$_['domains_success_total'] = 0;

$_['action'] = get_action();

if ( empty( $_['action'] ) ) {
	echo "[+] Добавление с самого начала | Accounts: " . count( $_['cf_accounts_arr'] ) . " | Domains: " . count( $_['domains_arr'] ) . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	empty_dir( $_['export_dir'] );
	empty_dir( $_['todo_dir'] );
	
	export_domains();
	create_todo();
	read_todo();
	
	set_cloudflare_rules();	
}

if ( 'next' == $_['action'] ) {	
	echo "[+] Продолжение добавления | Accounts: " . count( $_['cf_accounts_arr'] ) . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	read_todo();
	
	set_cloudflare_rules();
}

if ( 'del' == $_['action'] ) {	
	echo "[+] Удаление с самого начала | Accounts: " . count( $_['cf_accounts_arr'] ) . " | Domains: " . count( $_['domains_arr'] ) . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	empty_dir( $_['export_dir'] );
	empty_dir( $_['todo_dir'] );
	
	export_domains();
	create_todo();
	read_todo();
	
	set_cloudflare_rules();	
}

if ( 'delnext' == $_['action'] ) {
	
	echo "[+] Продолжение удаления | Accounts: " . count( $_['cf_accounts_arr'] ) . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	read_todo();
	
	set_cloudflare_rules();
}

$time2 = ( microtime ( true ) - $_['start_time_total'] );

echo "\n------------------------------------------------------\nFinished | Успешных доменов: " . $_['domains_success_total'] . " / " . count( $_['domains_arr'] ) . " | Time: " . time_to_human( $time2 ) . "\n------------------------------------------------------\n";
ob_flush(); flush();
	
exit;

















/////////////////////////////////////////////////////////////////////////////////////////////

function export_domains() {
	global $_;
	
	echo "\n------------------------------------------------------\n[+] Экспорт всех доменов из аккаунтов в /export" . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	$_['start_time_tmp'] = microtime ( true );
	$_['exported_domains'] = 0;
	
	$accounts_parts = array_chunk( $_['cf_accounts_arr'], $_['threads_num'] );
	//print_r( $accounts_parts );
	
	foreach ( $accounts_parts as $k => $accounts ) {
		$_['threads'] = [];
		$_['api_errors_last'] = [];
		$_['api_errors_num'] = 0;
		
		$success_accounts = 0;
		
		foreach ( $accounts as $account ) {
			$row = [];
			$row = explode( ';', $account );
			$email = trim( $row[0] );
			$global_api_key = trim( $row[1] );
			$account_id = api_get_account_id( $email, $global_api_key );
			
			if ( empty( $account_id ) ) {
				echo "\nError: Не могу получить account_id в api_get_account_id";
				ob_flush(); flush();
				exit;
			}
						
			$_['threads'][ $account_id ]['success'] = '';
			$_['threads'][ $account_id ]['domains'] = [];
			$_['threads'][ $account_id ]['domains_num'] = 0;
			$_['threads'][ $account_id ]['next_page'] = 1;
			$_['threads'][ $account_id ]['total_pages'] = 1;
			$_['threads'][ $account_id ]['total_count'] = 0;
			$_['threads'][ $account_id ]['email'] = $email;
			$_['threads'][ $account_id ]['global_api_key'] = $global_api_key;			
		}
		
		while ( $success_accounts < count( $accounts ) && (int) $_['api_errors_num'] < 10 ) {
			api_zones_list();
			
			$success_accounts = count_success_accounts();
		}
		
		if ( (int) $_['api_errors_num'] >= 10 ) {
			echo "\n api_errors_num > 10: " . $_['api_errors_last'][0];
			file_put_contents( __DIR__ . '/_errors.txt', var_export( $_['api_errors_last'], true ), LOCK_EX );
			exit;
		}
		
		//запись доменов в файлы /export/...
		foreach ( $_['threads'] as $acc_id => $v ) {
			if ( ! empty( $v['domains'] ) ) {
				$domains_data = implode( "\n", $v['domains'] );
				$_['exported_domains'] = (int) $_['exported_domains'] + count( $v['domains'] );
			} else {
				$domains_data = '';
			}
			
			file_put_contents( $_['export_dir'] . '/' . $v['email'] . '.txt', $domains_data, FILE_APPEND | LOCK_EX );			
		}
		
		//print_r( $_['threads'] );
	}	
	
	$time2 = ( microtime ( true ) - $_['start_time_tmp'] );
	
	echo "\nAccounts: " . count( $_['cf_accounts_arr'] ) . ' | Domains: ' . $_['exported_domains'] . ' | Time: ' . time_to_human( $time2 );
	ob_flush(); flush();
	
	
}

function set_cloudflare_rules() {
	global $_;
	
	echo "\n------------------------------------------------------\n[+] Установка правил в доменах" . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	foreach ( $_['todo_arr'] as $email => $v ) {
		$_['start_time_tmp'] = microtime ( true );
		
		echo "\n" . $email . ': '; ob_flush(); flush();
		
		$global_api_key = '';
		$rules_token = '';
		$domains = [];
		$domains_done = 0;		
		$_['api_errors_num'] = 0;
		$done = '';
		
		$global_api_key = trim( $v['global_api_key'] );
		$rules_token 	= trim( $v['rules_token'] );
		
		while ( $done != 'done' && (int) $_['api_errors_num'] < 10 ) {
			$_['threads'] = [];
			$_['api_errors_last'] = [];
			$_['api_errors_num'] = 0;
			$domains_success = 0;
						
			$domains = get_domains_todo( $_['todo_arr'][ $email ]['domains'], $_['threads_num'] );
			
			if ( $domains == 'done' ) {
				$done = 'done';
				break;
			}
			
			if ( ! empty( $domains ) && $domains != 'done' ) {
				foreach( $domains as $k2 => $v2 ) {
					if ( in_array( $k2, $_['domains_arr'] ) ) {
						$_['threads'][ $k2 ]['success'] = '';
						$_['threads'][ $k2 ]['global_api_key'] = $global_api_key;
						$_['threads'][ $k2 ]['rules_token'] = $rules_token;
						$_['threads'][ $k2 ]['email'] = $email;
						$_['threads'][ $k2 ]['domain'] = $k2;
						$_['threads'][ $k2 ]['zone_id'] = $v2['zone_id'];
					}
				}				
				
				api_rulesets_redirect();
				
				//print_r( $_['threads'] ); exit;				

				foreach ( $_['threads'] as $k3 => $v3 ) {
					if ( ! empty( trim( $v3['success'] ) ) ) {
						$_['todo_arr'][ trim( $v3['email'] ) ]['domains'][ trim( $v3['domain'] ) ]['success'] = 1;
						$domains_success++;
						$_['domains_success_total'] = (int) $_['domains_success_total'] + 1;
					}					
				}
				
				if ( ! empty( $domains ) ) {
					echo ' +' . $domains_success . ''; ob_flush(); flush();
					$domains_done = $domains_done + $domains_success;
				}
				
				
			}
			
			if ( (int) $_['api_errors_num'] >= 10 ) {
				echo "\n api_errors_num > 10: " . $_['api_errors_last'][0];
				file_put_contents( __DIR__ . '/_errors.txt', var_export( $_['api_errors_last'], true ), LOCK_EX );
				exit;
			}
			
			//print_r( $_['todo_arr'] );
			
			update_todo_files();
		}		
		
		$time2 = ( microtime ( true ) - $_['start_time_tmp'] );
		
		echo "\nDomains: " . $domains_done . " | Time: " . time_to_human( $time2 ) . "\n"; ob_flush(); flush();
	}
}

function api_rulesets_redirect() {
	global $_;
	
	foreach ( $_['threads'] as $thread => $v ) {
		if ( empty( $_['threads'][ $thread ]['success'] ) ) {
			if ( 'del' == $_['action'] || 'delnext' == $_['action'] ) {
				$new_ruleset = json_encode([
					'name' => 'Rule Set',
					'rules' => [],
				]);
			} else {
				$new_ruleset = json_encode([
					'name' => 'Rule Set',
					'rules' => rules( $_['threads'][ $thread ]['domain'] ),
				]);
			}			
	
			$_['threads'][ $thread ]['curl_url'] = 'https://api.cloudflare.com/client/v4/zones/' . $_['threads'][ $thread ]['zone_id'] . '/rulesets/phases/http_request_dynamic_redirect/entrypoint';
			$_['threads'][ $thread ]['curl_method'] 	= 'PUT';
			$_['threads'][ $thread ]['curl_headers'] 	= [ 'Authorization: Bearer ' . $_['threads'][ $thread ]['rules_token'], 'Content-Type: application/json' ];
			$_['threads'][ $thread ]['curl_params'] 	= $new_ruleset;
			//$_['threads'][ $thread ]['proxy'] = get_proxy();
		}
	}
	
	$multi_result = curl_request( $_ );
	
	//print_r( $multi_result );

	$errors = 0;
	
	foreach ( $multi_result as $thread => $result ) {
		$r = json_decode( $result[1], true );
		
		//comment
		//print_r( $r ); //exit;
		
		if ( 200 === (int) $result[0] && ! empty( $r['success'] ) ) {
			$_['threads'][ trim( $result[2] ) ]['success'] = 1;
		} else {
			$errors++;
			$_['api_errors_num'] = (int) $_['api_errors_num'] + 1;
			$_['api_errors_last'][] = $r['errors'][0]['code'] . ' / ' . $r['errors'][0]['message'];
		}		
	}
	
	if ( empty( $errors ) ) {
		$_['api_errors_last'] = [];
		return true;
	} else {
		return false;
	}
}

function api_zones_list() {
	global $_;
	
	foreach ( $_['threads'] as $thread => $v ) {
		if ( empty( $_['threads'][ $thread ]['success'] ) ) {
			$_['threads'][ $thread ]['curl_url'] = 'https://api.cloudflare.com/client/v4/zones?page=' . $_['threads'][ $thread ]['next_page'] . '&per_page=50&order=status&direction=asc&match=all';
			$_['threads'][ $thread ]['curl_method'] 	= 'GET';
			$_['threads'][ $thread ]['curl_headers'] 	= [ 'X-Auth-Email: ' . $_['threads'][ $thread ]['email'], 'X-Auth-Key: ' . $_['threads'][ $thread ]['global_api_key'], 'Content-Type: application/json' ];
			$_['threads'][ $thread ]['curl_params'] 	= '';
			//$_['threads'][ $thread ]['proxy'] = get_proxy();
		}
	}
	
	$multi_result = curl_request( $_ );

	$errors = 0;
	
	foreach ( $multi_result as $thread => $result ) {
		$r = json_decode( $result[1], true );
		
		if ( 200 === (int) $result[0] && $r['success'] ) {
			if ( ! empty( $r['result'] ) && count( $r['result'] ) > 0 ) {
				foreach ( $r['result'] as $domain ) {
					$account_id = $domain['account']['id'];										
					$_['threads'][ $account_id ]['domains'][] = $domain['name'] . ';' . $domain['id'];					
				}				
				
				$_['threads'][ $account_id ]['total_count'] = (int) $r['result_info']['total_count'];
				$_['threads'][ $account_id ]['total_pages'] = ceil( (int) $_['threads'][ $account_id ]['total_count'] / (int) $r['result_info']['per_page'] );
				
				if ( (int) $_['threads'][ $account_id ]['total_pages'] > (int) $_['threads'][ $account_id ]['next_page'] ) {
					$_['threads'][ $account_id ]['next_page'] = (int) $_['threads'][ $account_id ]['next_page'] + 1;
				} else {
					$_['threads'][ $account_id ]['next_page'] = 1;
					$_['threads'][ $account_id ]['success'] = 1;
				}
				
				$_['threads'][ $account_id ]['domains_num'] = count( array_unique( $_['threads'][ $account_id ]['domains'] ) );
			} else {
				$_['threads'][ $result[2] ]['next_page'] = 1;
				$_['threads'][ $result[2] ]['success'] = 1;
			}	
		} else {
			$errors++;
			$_['api_errors_num'] = (int) $_['api_errors_num'] + 1;
			$_['api_errors_last'][] = $r['errors'][0]['code'] . ' / ' . $r['errors'][0]['message'];
		}		
	}
	
	if ( empty( $errors ) ) {
		$_['api_errors_last'] = [];
		return true;
	} else {
		return false;
	}
}

function api_get_account_id( $email, $global_api_key ) {
	$url = 'https://api.cloudflare.com/client/v4/accounts';
	 
	$headers = [
        "X-Auth-Email: $email",
        "X-Auth-Key: $global_api_key",
        "Content-Type: application/json"
    ];
	
	$ch = curl_init();

    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

    $response = curl_exec( $ch );

    if ( curl_errno( $ch ) ) {
		return false;
    }

    curl_close( $ch );
	
	$result = json_decode( $response, true );
	//print_r($result);

    return trim( $result['result'][0]['id'] );
}

function curl_request() {
	global $_;
	
	$result = [];
	$results = [];
	$http_code = '';
	$threads_num = 0;

	foreach ( $_['threads'] as $thread => $v ) {
		if ( empty( $_['threads'][ $thread ]['success'] ) ) {
			$threads_num++;
			$ch = 'ch_';
			${ $ch . $thread } = curl_init();
			
			curl_setopt( ${ $ch . $thread }, CURLOPT_URL, $_['threads'][ $thread ]['curl_url'] );
			curl_setopt( ${ $ch . $thread }, CURLOPT_TIMEOUT, 120 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_CONNECTTIMEOUT, 30 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( ${ $ch . $thread }, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( ${ $ch . $thread }, CURLOPT_SSL_VERIFYPEER, 0 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_SSL_VERIFYHOST, 0 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_DNS_CACHE_TIMEOUT, 240 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			curl_setopt( ${ $ch . $thread }, CURLOPT_NOSIGNAL, true );
			curl_setopt( ${ $ch . $thread }, CURLOPT_ENCODING, 'gzip' );
			
			curl_setopt( ${ $ch . $thread }, CURLOPT_HTTPHEADER, $_['threads'][ $thread ]['curl_headers'] );
			
			if ( $_['threads'][ $thread ]['curl_method'] === 'DELETE' ) {
				curl_setopt( ${ $ch . $thread }, CURLOPT_CUSTOMREQUEST, 'DELETE' );
			}
			
			if ( $_['threads'][ $thread ]['curl_method'] === 'POST' ) {
				curl_setopt( ${ $ch . $thread }, CURLOPT_POST, 1 );
				curl_setopt( ${ $ch . $thread }, CURLOPT_POSTFIELDS, $_['threads'][ $thread ]['curl_params'] );				
			}
			
			if ( $_['threads'][ $thread ]['curl_method'] === 'PUT' ) {
				curl_setopt( ${ $ch . $thread }, CURLOPT_CUSTOMREQUEST, 'PUT' );
				curl_setopt( ${ $ch . $thread }, CURLOPT_POSTFIELDS, $_['threads'][ $thread ]['curl_params'] );
			}
			
			if ( $_['threads'][ $thread ]['curl_method'] === 'PATCH' ) {
				curl_setopt( ${ $ch . $thread }, CURLOPT_POST, 1 );
				curl_setopt( ${ $ch . $thread }, CURLOPT_POSTFIELDS, $_['threads'][ $thread ]['curl_params'] );
				curl_setopt( ${ $ch . $thread }, CURLOPT_CUSTOMREQUEST, 'PATCH' );
			}
			
			if ( ! empty( $_['use_proxy'] ) ) {
				if ( 'socks5' == strtolower( $_['proxy_type'] ) ) {
					curl_setopt( ${ $ch . $thread }, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5 );
					curl_setopt( ${ $ch . $thread }, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME );
				} else {
					curl_setopt( ${ $ch . $thread }, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );	
				}

       			$proxy = explode(':', $_['threads'][ $thread ]['proxy'] );
				$proxy_login = $proxy[0] . ':' . $proxy[1];
				$proxy_pass = $proxy[2] . ':' . $proxy[3];
				
				curl_setopt( ${ $ch . $thread }, CURLOPT_HTTPPROXYTUNNEL, 1 );
       			curl_setopt( ${ $ch . $thread }, CURLOPT_PROXY, $proxy_login );
				curl_setopt( ${ $ch . $thread }, CURLOPT_PROXYUSERPWD, $proxy_pass ); 
			}
		}
	}
	
	/*echo '<pre>';
	print_r2($_['threads']);
	exit;*/
	
	if ( $threads_num > 0 ) {
		$mh = curl_multi_init();

		foreach ( $_['threads'] as $thread => $v ) {
			if ( empty( $_['threads'][ $thread ]['success'] ) ) {
				curl_multi_add_handle( $mh, ${ $ch . $thread } );
			}
		}

		$active = null;
		$space = 0;

		do {
			$status = curl_multi_exec( $mh, $active );
		} while ( $status == CURLM_CALL_MULTI_PERFORM );
		
		while ( $active && $status == CURLM_OK ) {
			if ( curl_multi_select( $mh ) != -1 ) {
				do {
					$status = curl_multi_exec( $mh, $active );
					
					if ( time() % 10 == 0 ) {
						if ( $space == 0 ) {
							$space = 1;
							echo '|';
							ob_flush();	flush();
						}
					} else {
						$space = 0;
					}
			
				} while ( $status == CURLM_CALL_MULTI_PERFORM );
			} else {
				usleep(100); // 1 * 1000000 = 1sec
			}
		}

		foreach ( $_['threads'] as $thread => $v ) {			
			if ( empty( $_['threads'][ $thread ]['success'] ) ) {
				$result[ $thread ][0] 	= curl_getinfo( ${ $ch . $thread }, CURLINFO_HTTP_CODE );
				$result[ $thread ][1] 	= curl_multi_getcontent( ${ $ch . $thread } );
				$result[ $thread ][2] 	= $thread;
				$results[ $thread ] 	= [ $result[ $thread ][0], $result[ $thread ][1], $result[ $thread ][2] ];

				curl_multi_remove_handle( $mh, ${ $ch . $thread } );
				curl_close( ${$ch . $thread} );
			}
		}

		curl_multi_close( $mh );
	}
	
	//print_r( $results ); exit;
	
	return $results;
}

function count_success_accounts() {
	global $_;
	
	$success_accounts = 0;
	
	foreach ( $_['threads'] as $thread => $v ) {
		if ( ! empty( $_['threads'][ $thread ]['success'] ) ) {
			$success_accounts++;
		}
	}
	
	return $success_accounts;
}

function time_to_human( $seconds ) {
	$format = [
		'd' => 86400,
		'h' => 3600,
		'm' => 60,
	];

	$result = '';

	foreach ( $format as $letter => $sec ) {
		$$letter 	= (int) floor( $seconds / $sec );
		$seconds 	-= ( $$letter * $sec );
		$result 	.= ( $$letter == 0 ) ? '' : $$letter . "$letter ";
	}

	return $result . ( (int) floor( $seconds ) ) . 's';
}

function create_todo() {
	global $_;
	
	echo "\n------------------------------------------------------\n[+] Распределение задач по аккаунтам в /todo" . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	$files = glob( $_['export_dir'] . '/*.txt' );
	$accounts_num = 0;
	$domains_num = 0;
	$domains_arr2 = [];

    foreach ( $files as $file ) {
		$todo_file = '';
		$todo_file_data = '';
		
		$export_file_arr = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		//print_r( $export_file_arr );
		
		if ( ! empty( $export_file_arr ) ) {
			$todo_file = $_['todo_dir'] . '/' . str_replace( $_['export_dir'] . '/', '', $file );
			$todo_file_data = '';
			
			foreach ( $export_file_arr as $k => $v ) {
				$row = explode( ';', $v );
				
				if ( in_array( trim( $row[0] ), $_['domains_arr'] ) ) {
					$domains_num++;
					$domains_arr2[] = trim( $row[0] );
					$todo_file_data .= trim( $row[0] ) . ';0;' . trim( $row[1] ) . "\n";
				}
			}
			
			if ( ! empty( $todo_file_data ) ) {
				$accounts_num++;
				file_put_contents( $todo_file, $todo_file_data, LOCK_EX );
			}			
		}
    }
	
	$error_domains = get_error_domains( $_['domains_arr'], $domains_arr2 );
	$error = '';
	
	if ( ! empty( $error_domains ) ) {
		file_put_contents( __DIR__ . '/_error_domains.txt', implode( "\n", $error_domains ), LOCK_EX );
		$error = ' - Не найдено: ' . count( $error_domains );
	}
	
	echo 'Аккаунтов в работе: ' . $accounts_num . '/' . count( $_['cf_accounts_arr'] ) . ' | Доменов: ' . $domains_num . '/' . count( $_['domains_arr'] ) . $error;
}

function get_error_domains( $domains1, $domains2 ) {
	if ( ! empty( $domains1 ) ) {
		$domains2 = array_flip( $domains2 );
		$result = [];

		foreach ( $domains1 as $domain ) {
			if ( ! isset( $domains2[ $domain ] ) ) {
				$result[] = $domain;
			}
		}

		if ( empty( $result ) ) {
			return false;
		}

		return $result;
	}
	
	return false;
}

function update_todo_files() {
	global $_;	
	
	foreach ( $_['todo_arr'] as $k => $v ) {
		$todo_file = '';
		$todo_file_data = '';
	
		$todo_file = $_['todo_dir'] . '/' . trim( $k ) . '.txt';
		
		foreach ( $v['domains'] as $k2 => $v2 ) {
			$todo_file_data .= trim( $k2 ) . ';' . trim( $v2['success'] ) . ';' . trim( $v2['zone_id'] ) . "\n";
		}
		
		file_put_contents( $todo_file, $todo_file_data, LOCK_EX );
	}
}

function read_todo() {
	global $_;
	
	echo "\n------------------------------------------------------\n[+] Чтение незавершенных задач в /todo" . "\n------------------------------------------------------\n";
	ob_flush(); flush();
	
	$files = glob( $_['todo_dir'] . '/*.txt' );
	$accounts_num = 0;
	$domains_num = 0;
	$_['todo_arr'] = [];
	
	$cf_accounts_arr = [];
	
	foreach ( $_['cf_accounts_arr'] as $account ) {
		$row = [];
		$row = explode( ';', $account );
		
		$cf_accounts_arr[ trim( $row[0] ) ]['global_api_key'] = trim( $row[1] );
		$cf_accounts_arr[ trim( $row[0] ) ]['rules_token'] = trim( $row[2] );
	}
	
	foreach ( $files as $file ) {
		$todo_file_arr = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$email = str_replace( $_['todo_dir'] . '/', '', $file );
		$email = str_replace( '.txt', '', $email );
		
		if ( ! empty( $todo_file_arr ) ) {
			foreach ( $todo_file_arr as $k => $v ) {
				$row = explode( ';', $v );
				
				if ( empty( trim( $row[1] ) ) ) {
					$domains_num++;
					
					$_['todo_arr'][ $email ]['global_api_key'] = $cf_accounts_arr[ $email ]['global_api_key'];
					$_['todo_arr'][ $email ]['rules_token'] = $cf_accounts_arr[ $email ]['rules_token'];
					$_['todo_arr'][ $email ]['domains'][ trim( $row[0] ) ] = [ 'success' => 0, 'zone_id' => trim( $row[2] ) ];
				}
				
			}
		}
	}
	
	echo 'Доменов в очереди: ' . $domains_num;
	//comment
	//print_r( $_['todo_arr'] );
}

function get_domains_todo( $domains_arr, $threads_num ) {
    if ( empty( $domains_arr ) ) {
        return 'done';
    }

    $result = [];

    foreach ( $domains_arr as $domain => $data ) {
        if ( 0 === (int) $data['success'] ) {
            $result[ $domain ] = $data;

            if ( count( $result ) >= $threads_num ) {
                break;
            }
        }
    }

    if ( empty( $result ) ) {
        return 'done';
    }

    return $result;
}

function get_action() {
    $uri_parts = explode( '?', $_SERVER['REQUEST_URI'], 2 );

    if ( count( $uri_parts ) < 2 || empty( $uri_parts[1] ) ) {
        return '';
    }

    return $uri_parts[1];
}

function empty_dir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }
	
	if ( file_exists( __DIR__ . '/_error_domains.txt' ) ) {
		unlink( __DIR__ . '/_error_domains.txt' );
	}
	
	if ( file_exists( __DIR__ . '/_errors.txt' ) ) {
		unlink( __DIR__ . '/_errors.txt' );
	}	
	
	echo "Очистка папки $dir\n";
	
    $files = glob( $dir . '/*.txt' );

    foreach ( $files as $file ) {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }

    return true;
}