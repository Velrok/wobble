<?php
	require_once 'config.php';

	# TODO: Replace with __autoload()
	require_once dirname(__FILE__).'/classes/NotificationRepository.class.php';
	require_once dirname(__FILE__).'/classes/TopicRepository.class.php';
	require_once dirname(__FILE__).'/classes/UserRepository.class.php';
	require_once dirname(__FILE__).'/classes/ContactsRepository.class.php';
	require_once dirname(__FILE__).'/classes/SecurityService.class.php';
	require_once dirname(__FILE__).'/classes/ValidationService.class.php';

	jsonrpc_export_functions(array (
		// Topics
		array('file' => 'api_topiclist.php', 'method' => 'topics_list'),
		array('file' => 'api_topiclist.php', 'method' => 'topics_create'),
		
		// Topic
		array('file' => 'api_topic.php', 'method' => 'topic_get_details'),
		array('file' => 'api_topic.php', 'method' => 'topic_add_user'),
		array('file' => 'api_topic.php', 'method' => 'topic_remove_user'),
		array('file' => 'api_topic.php', 'method' => 'post_create'),
		array('file' => 'api_topic.php', 'method' => 'post_edit'),
		array('file' => 'api_topic.php', 'method' => 'post_delete'),
		array('file' => 'api_topic.php', 'method' => 'post_read'),
		
		// User / Session
		array('file' => 'api_user.php', 'method' => 'user_get'),
		array('file' => 'api_user.php', 'method' => 'user_get_id'),
		array('file' => 'api_user.php', 'method' => 'user_register'),
		array('file' => 'api_user.php', 'method' => 'user_change_name'),
		array('file' => 'api_user.php', 'method' => 'user_login'),
		array('file' => 'api_user.php', 'method' => 'user_signout'),
		
		// Notifications
		array('file' => 'api_notifications.php', 'method' => 'get_notifications'),
		
		// Contact list
		array('file' => 'api_user.php', 'method' => 'user_get_contacts'),
		array('file' => 'api_user.php', 'method' => 'user_add_contact'),
		array('file' => 'api_user.php', 'method' => 'user_remove_contact'),
		
		// Test functions
		array('file' => 'api_test.php', 'method' => 'testecho')
	));

	function ctx_before_request($method, $params) {
		session_start();
		if ( !empty($_SESSION['userid'])) {
			UserRepository::touch($_SESSION['userid']);
		}
	}
	jsonrpc_export_before('ctx_before_request'); # Is there a more php5-ish way?

	function ctx_after_request($method, $params, $result, $exception) {
	
	}
	jsonrpc_export_after('ctx_after_request');
	



	###
	# Helper Functions
	#
	#


	global $PDO_CONTEXT_VAR;
	$PDO_CONTEXT_VAR = null;
	function ctx_getpdo() {
		global $PDO_CONTEXT_VAR;
		
		if ( $PDO_CONTEXT_VAR == null ) {
			$pdo = new PDO(PDO_URL, PDO_USER, PDO_PASSWORD);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); # Raise exceptions, so they get logged by Airbrake, or whatever
			$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
			$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			$PDO_CONTEXT_VAR = $pdo;
		}
		return $PDO_CONTEXT_VAR;
	}	
	
	function ctx_getuserid() {
		return isset($_SESSION['userid']) ? $_SESSION['userid'] : NULL;
	}
	