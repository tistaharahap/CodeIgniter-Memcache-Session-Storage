<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
* memc
*
* A CodeIgniter library used to communicate with Memcache.
* Please take a look at $this->host_key and change the value to your liking.
*
* @package CodeIgniter
* @subpackage Memcache Session Storage
* @category Libraries
* @author Arieditya PrdH - @arieditya
* @link http://www.facebook.com/arieditya.prdh
*/

class CI_Memc {
	
	private $CI;
	private $conf = 'memcached';	// Config File
	private $connection;			// Server Connection
	
	private $host_key = '';
	
	function __construct() {
		$this->CI_Memc();
	}
	
	function CI_Memc() {
		if ( ! class_exists('Memcache'))
		{
			show_error("Memcache is not installed or enabled!", 500);
		}
		
		$this->CI =& get_instance();
		$this->host_key = 'www.example.com';
		$this->connect();
	}
	
	protected function connect() {
		$this->CI->config->load($this->conf,TRUE);
		
		$conf = $this->CI->config->item('memcache_storage');

		$parameter['default'] = $this->CI->config->item('memcache_default');
		$parameter['multi'] = $this->CI->config->item('memcache_multi');
		
		try {
			$connserv = new Memcache;
			$connserv->addServer(
				$conf["{$parameter['default']}"]['server'],
				$conf["{$parameter['default']}"]['port'],
				$conf["{$parameter['default']}"]['persistent']
			);
			
			if($parameter['multi']) {
				foreach($conf as $server => $connector) {
					if($server != $parameter['default']) {
						$connserv->addServer(
							$conf[$server]['server'],
							$conf[$server]['port'],
							$conf[$server]['persistent']
						);
					}
				}
			}
			
			$this->connection =& $connserv;

			if(empty($this->connection) || $this->connection === FALSE)
				throw new Exception;
		} catch(Exception $e) {
			show_error('Cannot connect to Memcache! Please check your configuration!', 500, 'Memcache Connection Error');
		}
	}
	
	function set($key = NULL, $value = NULL, $expire = 86400) {
		if(is_null($key) || empty($key) ) {
			return FALSE;
		}
		$key = $this->host_key.'#'.$key;
		$this->_set_namespace($key);
		
		$value = $this->_falsify($value);
		
		return $this->_set_to_memcache($key, $value, $expire);
	}
	
	function get($key) {
		$key = $this->host_key.'#'.$key;//
		$result = $this->_get_from_memcache($key);
		if(!$result) {
			$this->_delete_namespace($key);
		}
		return $result;
	}
	
	function delete($key) {
		$key = $this->host_key.'#'.$key;
		$this->_delete_namespace($key);
		return $this->_delete_memcache($key);
	}
	
	function _set_to_memcache($key, $value, $expire = 86400, $compress = FALSE) {
		$compress = is_bool($value) || is_int($value) || is_float($value) || $compress === FALSE ? false : MEMCACHE_COMPRESSED;
		if($this->connection->replace($key, $value, $compress, $expire) === FALSE) 
			return $this->connection->set($key, $value, $compress, $expire);
		return TRUE;
	}
	
	function _get_from_memcache($key) {
		$result = $this->connection->get($key);
		if($result) {
			return $this->_defalsify($result);
		}
		return FALSE;
	}
	
	function _delete_memcache($key) {
		$this->_set_to_memcache($key, "", 1, FALSE);
		return $this->connection->delete($key);
	}
	
	function _falsify($value) {
		if(empty($value) && is_array($value)) {
			return '<-FALSE_ARRAY->';
		} else if(empty($value) && is_object($value)) {
			return '<-FALSE_OBJECT->';
		} else if(empty($value) && is_bool($value)) {
			return '<-FALSE_BOOLEAN->';
		} else if(empty($value) && is_null($value)) {
			return '<-FALSE_NULL->';
		} else {
			return $value;
		}
	}

	function _defalsify($value = '') {
		if($value == '<-FALSE_ARRAY->') {
			return array();
		} else if($value == '<-FALSE_OBJECT->') {
			return new stdclass;
		} else if($value == '<-FALSE_BOOLEAN->') {
			return false;
		} else if($value == '<-FALSE_NULL->') {
			return null;
		} else if($value == '<-FALSE_INTEGER->') {
			return 0;
		} else {
			return $value;
		}
	}
	
	function _set_namespace($key = '') {
		if(empty($key) || strlen($key) < 1) return FALSE;
		$ns = explode('#',$key);
		$ns_index = '';
		while(count($ns)>1) {
			$ns_index .= (array_shift($ns).'#');
			$get = array();
			$get = $this->_get_from_memcache($ns_index.'*');
			if(is_array($get) AND count($get)>0) {
				$get[] = implode('#', $ns);
			} else {
				$get = array(implode('#', $ns));
			}
			
			$get = array_filter(array_unique($get));
			$this->_set_to_memcache($ns_index.'*',$get, 86400*30);
			
		}
	}
	
	function _get_namespace($key = '') {
		if(!is_string($key) || empty($key)) {
			return FALSE;
		}
		$key = trim($key,'#');
		if(strripos($key,'#*') !== 0) $key = $key.'#*';
		$result = $this->_get_from_memcache($key);
		if(!is_array($result)) {
			return FALSE;
		}
		return $result;
	}
	
	function _delete_namespace($key = '') {
		$ns_list = $this->_get_namespace($key);
		if(!$ns_list || !is_array($ns_list)) return FALSE;
		foreach($ns_list as $ns__) {
			$this->_delete_memcache($ns__);
		}
		$this->_delete_memcache($key);
		
	}
	
	function get_host() {
		return $this->host_key;
	}	
}

/* End of file memc.php */
/* Location: application/libraries/memc.php */