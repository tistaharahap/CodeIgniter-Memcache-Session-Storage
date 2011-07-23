<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
* MY_Session
*
* A CodeIgniter library to use Memcache as session storage coupled with bot detection
* to NOT give bots sessions. Useful especially with autoloaded session libraries.
* 
* Codes derrived from original Code Igniter Session class.
* 
* @package CodeIgniter
* @subpackage Memcache Session Storage
* @category Libraries
* @author Batista Harahap - @tista
* @link http://www.bango29.com/
*/

class MY_Session extends CI_Session {
	var $CI;
	var $is_bot;
	var $namespace;
	var $userdata;

	function  __construct($params = array()) {
		$this->MY_Session($params);
	}

	function MY_Session($params = array()) {
		$this->CI =& get_instance();
		$this->is_bot = ($this->__detectVisit() == 'bot') ? true : false;
		$this->__reset_namespace();
		$this->CI->load->library('memc');

		foreach (
			array(
			    'sess_encrypt_cookie',
			    'sess_use_database',
			    'sess_table_name',
			    'sess_expiration',
			    'sess_match_ip',
			    'sess_match_useragent',
			    'sess_cookie_name',
			    'cookie_path',
			    'cookie_domain',
			    'sess_time_to_update',
			    'time_reference',
			    'cookie_prefix',
			    'encryption_key'
			) as $key) {
			$this->$key = (isset($params[$key])) ? $params[$key] : $this->CI->config->item($key);
		}

		$this->CI->load->helper('string');

		if ($this->sess_encrypt_cookie == TRUE) {
			$this->CI->load->library('encrypt');
		}

		$this->now = $this->_get_time();

		$this->sess_expiration = (60*60*24*14);

		$this->sess_cookie_name = $this->cookie_prefix.$this->sess_cookie_name;

		if (!$this->sess_read()) {
			$this->sess_create();
		} else {
			$this->sess_update();
		}

	   	$this->_flashdata_sweep();

	   	$this->_flashdata_mark();

		$this->_sess_gc();
	}

	function sess_read() {
		if($this->is_bot) {
			$this->sess_destroy();
			return FALSE;
		}

		$session = $this->CI->input->cookie($this->sess_cookie_name);

		if($session === FALSE) {
			return FALSE;
		}

		if ($this->sess_encrypt_cookie == TRUE) {
			$session = $this->CI->encrypt->decode($session);
		} else {
			$hash	 = substr($session, strlen($session)-32);
			$session = substr($session, 0, strlen($session)-32);

			if ($hash !==  md5($session.$this->encryption_key)) {
				$this->sess_destroy();
				return FALSE;
			}
		}

		$session = $this->_unserialize($session);

		if (
			!is_array($session)
			OR ! isset($session['session_id'])
			OR ! isset($session['ip_address'])
			OR ! isset($session['user_agent'])
			OR ! isset($session['last_activity'])
		) {
			$this->sess_destroy();
			return FALSE;
		}

		if (($session['last_activity'] + $this->sess_expiration) < $this->now) {
			$this->sess_destroy();
			return FALSE;
		}

		if ($this->sess_match_ip == TRUE AND $session['ip_address'] != $this->CI->input->ip_address()) {
			$this->sess_destroy();
			return FALSE;
		}

		if (
			$this->sess_match_useragent == TRUE
			AND trim($session['user_agent']) != trim(substr($this->CI->input->user_agent(), 0, 50))
			) {
			$this->sess_destroy();
			return FALSE;
		}

		$this->__reset_namespace();
		$this->__build_namespace($session['session_id'], $session['ip_address'], $session['user_agent']);

		$query = $this->CI->memc->get($this->namespace);
		if(empty($query)) {
			$this->sess_destroy();
			return FALSE;
		}

		$row = json_decode($query);
		if(isset($row->user_data) AND $row->user_data != '') {
			$custom_data = $this->_unserialize($row->user_data);
			if(is_array($custom_data)) {
				foreach($custom_data as $key => $val) {
					$session[$key] = $val;
				}
			}
		}

		$this->userdata = $session;
		unset($session);

		return TRUE;
	}

	function sess_write() {
		$custom_userdata = $this->userdata;
		$cookie_userdata = array();

		foreach (array('session_id','ip_address','user_agent','last_activity') as $val) {
			unset($custom_userdata[$val]);
			$cookie_userdata[$val] = $this->userdata[$val];
		}

		if(count($custom_userdata) === 0) {
			$custom_userdata = '';
		} else {
			$custom_userdata = $this->_serialize($custom_userdata);
		}

		if(!$this->is_bot) {
			$this->__reset_namespace();
			$this->__build_namespace($this->userdata['session_id'], $this->userdata['ip_address'], $this->userdata['user_agent']);

			$write = array(
			    'session_id'	=> $this->userdata['session_id'],
			    'ip_address'	=> $this->userdata['ip_address'],
			    'user_agent'	=> $this->userdata['user_agent'],
			    'last_activity'	=> $this->userdata['last_activity'],
			    'user_data'		=> $custom_userdata
			);
			$this->CI->memc->set($this->namespace, json_encode($write), $this->sess_expiration);
		}

		$this->_set_cookie($cookie_userdata);
	}

	function sess_create() {
		$sessid = '';
		while(strlen($sessid) < 32) {
			$sessid .= mt_rand(0, mt_getrandmax());
		}

		$sessid .= $this->CI->input->ip_address();

		$this->userdata = array(
			'session_id' 	=> md5(uniqid($sessid, TRUE)),
			'ip_address' 	=> $this->CI->input->ip_address(),
			'user_agent' 	=> substr($this->CI->input->user_agent(), 0, 50),
			'last_activity'	=> $this->now,
			'user_data'	=> ''
		);

		if(!$this->is_bot) {
			$this->__reset_namespace();
			$this->__build_namespace($this->userdata['session_id'], $this->userdata['ip_address'], $this->userdata['user_agent']);

			$this->CI->memc->set($this->namespace, json_encode($this->userdata), $this->sess_expiration);
		}
		$this->_set_cookie();
	}

	function sess_update() {
		if (($this->userdata['last_activity'] + $this->sess_time_to_update) >= $this->now) {
			return;
		}

		$old_sessid = $this->userdata['session_id'];
		$new_sessid = '';
		while (strlen($new_sessid) < 32) {
			$new_sessid .= mt_rand(0, mt_getrandmax());
		}

		$new_sessid .= $this->CI->input->ip_address();

		$new_sessid = md5(uniqid($new_sessid, TRUE));

		// Delete old session
		$this->__reset_namespace();
		$this->__build_namespace($this->userdata['session_id'], $this->userdata['ip_address'], $this->userdata['user_agent']);
		$this->CI->memc->delete($this->namespace);

		$this->userdata['session_id'] = $new_sessid;
		$this->userdata['last_activity'] = $this->now;

		$cookie_data = NULL;

		if(!$this->is_bot) {
			$cookie_data = array();

			foreach (array('session_id','ip_address','user_agent','last_activity') as $val) {
				$cookie_data[$val] = $this->userdata[$val];
			}

			$user_data = "";
			if(isset($this->userdata['user_data']))  {
				if($this->userdata['user_data'] !== '') {
					$user_data = $this->userdata['user_data'];
				}
			}

			$update = array(
			    'session_id'	=> $this->userdata['session_id'],
			    'ip_address'	=> $this->userdata['ip_address'],
			    'user_agent'	=> $this->userdata['user_agent'],
			    'last_activity'	=> $this->userdata['last_activity'],
			    'user_data'		=> $user_data
			);
			$this->__reset_namespace();
			$this->__build_namespace($this->userdata['session_id'], $this->userdata['ip_address'], $this->userdata['user_agent']);
			$this->CI->memc->set($this->namespace, json_encode($update), $this->sess_expiration);
		}

		$this->_set_cookie($cookie_data);
	}

	function sess_destroy() {
		if(isset($this->userdata['session_id'])) {
			$this->__reset_namespace();
			$this->__build_namespace($this->userdata['session_id'], $this->userdata['ip_address'], $this->userdata['user_agent']);
			$this->CI->memc->delete($this->namespace);
		}

		setcookie(
			$this->sess_cookie_name,
			addslashes(serialize(array())),
			($this->now - 31500000),
			$this->cookie_path,
			$this->cookie_domain,
			0
		);
	}

	function _sess_gc() {
		return true;
	}

	function __build_namespace($sess_id, $ip_addr = 0, $user_agent = '') {
		$this->namespace .= $sess_id;
		if($this->sess_match_ip == TRUE && $ip_addr > 0)
			$this->namespace .= '#'.ip2long($ip_addr);
		if($this->sess_match_useragent == TRUE && $user_agent != '')
			$this->namespace .= '#'.md5($user_agent);
	}

	function __reset_namespace() {
		$this->namespace = "session#";
	}

	function __detectVisit() {
               $this->CI->load->library('user_agent');
               $agent = strtolower($this->CI->input->user_agent());

               $bot_strings = array(
                   "google", "bot", "yahoo", "spider", "archiver", "curl",
                   "python", "nambu", "twitt", "perl", "sphere", "PEAR",
                   "java", "wordpress", "radian", "crawl", "yandex", "eventbox",
                   "monitor", "mechanize", "facebookexternal", "bingbot"
               );

               foreach($bot_strings as $bot) {
                       if(strpos($agent, $bot) !== false) {
                               return "bot";
                       }
               }

               return "normal";
        }

}

/* End of file MY_Session.php */
/* Location: application/libraries/MY_Session.php */