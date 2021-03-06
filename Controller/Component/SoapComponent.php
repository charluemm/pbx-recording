<?php

App::uses('Component','Controller');
App::uses('Array2Xml', 'Lib');

/**
 * Class to handle SOAP calls
 *
 * @author Michael Müller <development@reu-network.de>
 * @author David Howon <howon.david@gmail.com>
 *
 */
class SoapComponent extends Component {
	
	/** @var \SoapClient */
	private $soapClient;
	
	private static $GROUP_PREFIX = "CC";
	
	public function __construct($config)
	{
		Configure::load("soap_config");
		$url = Configure::read("soap.wsdl");
		
		$options = array(
				'login' => Configure::read("soap.login"),
				'password' => Configure::read("soap.password"),
				'proxy_host' => Configure::read("proxy.host"),
				'proxy_port' => Configure::read("proxy.port"),
				'proxy_login' => Configure::read("proxy.login"),
				'proxy_password' => Configure::read("proxy.password"),
				//'trace' => 1,
				//'exceptions' => 1,
				'connection_timeout' => 10,
				'request_fulluri' => true,
				'stream_context' => stream_context_create(array(
						'http' => array(
								'protocol_version' => 1.0,
						),
						'ssl' => array(
								'verify_peer' => false,
								'verify_peer_name' => false,
								'allow_self_signed' => false
						)
				))
		);
		
		if(!empty($url))
			$this->soapClient = new \SoapClient($url, $options);
	}
	
	/**
	 *
	 * @param string $cn
	 * @param string $h323
	 * @param string $e165
	 * @throws \Exception if soap client is not configured
	 * @return UserInfoArray
	 */
	public function findUserConfig($cn = null, $h323 = null, $e165 = null)
	{
		if(empty($this->soapClient))
		{
			throw new \Exception("SoapClient is not configured. Check SOAP parameters in soap_config.php");
		}
		
		// search user
		$list = $this->soapClient->FindUser(true, true, true, true, $cn, $h323, $e165, 1, false, false);
		
		$show = array();
		foreach ($list as $user)
		{
			$cn = $user->cn;
			$result = new \SimpleXMLElement("<show />");
			$user = $result->addChild("user");
			$user->addAttribute("cn", $cn);
			//$user->addAttribute("config", "true");
			$show = $this->soapClient->Admin($result->asXML());
		}
		return $show;
	}
	
	/**
	 * get groups with given name prefix
	 *
	 * @param string $grpPrefix groups name prefix
	 * @throws \Exception
	 */
	public function getUserGroups($userConf)
	{
		if(empty($this->soapClient))
		{
			throw new \Exception("SoapClient is not configured. Check SOAP parameters in soap_config.php");
		}
		
		if(!($userConf instanceof SimpleXMLElement ))
			$userConf = new \SimpleXMLElement($userConf);
			
			// get all active groups starting with prefix
			$prefix = self::$GROUP_PREFIX;
			//$groupsConf = $userConf->user->xpath("grp[starts-with(@name, '$prefix')]");
			$groupsConf = $userConf->user->xpath("grp");
			
			$groups = array();
			foreach ($groupsConf as $group)
			{
				$name = $group->attributes()['name']->asXML();
				$groups[] = $name;
			}
			
			return $groups;
	}
	
	/**
	 * get recording status from user
	 *
	 * @param string $number the agent phone
	 * @throws \Exception if soap client is not configured
	 * @return array $userinfo array of user parameters
	 */
	public function getRecordingStatus($number)
	{
		if(empty($this->soapClient))
		{
			throw new \Exception("SoapClient is not configured. Check SOAP parameters in soap_config.php");
		}
		
		try
		{
			$userConf = $this->findUserConfig(null, null, $number);
		}
		catch(Exception $ex)
		{
			throw new \Exception($ex->getMessage());
		}
		
		$userConf = new \SimpleXMLElement($userConf);
		$recConf = $userConf->user->phone->rec;
		$return = array();
		$return['user_number'] = $number;
		$return['username'] = (string)$userConf->user['cn'];
		$return['number'] = (string)$recConf['e164'];
		
		// set return attributes
		$mode  = (string) $recConf['mode'];
		$twoWayMedia = (string)$recConf['recv'];
		$funcKeyControl = (string)$recConf['fkey'];
		$autoconnect = (string)$recConf['ac'];
		
		$return['recording'] = ($mode === "transparent" && $twoWayMedia === "0" && $autoconnect === "1" && $funcKeyControl === "1");
		
		return $return;
	}
	
	/**
	 * enable pbx recording
	 *
	 * @param int $number agent phone
	 * @param int $supervisor supervisor phone
	 * @throws \Exception if soap client is not configured
	 * @return boolean true, if successfully
	 */
	public function enableRecording($number, $supervisor)
	{
		if(empty($this->soapClient))
		{
			throw new \Exception("SoapClient is not configured. Check SOAP parameters in soap_config.php");
		}
		
		try
		{
			$agentConf = new \SimpleXMLElement($this->findUserConfig(null, null, $number));
			$supervisorConf = new \SimpleXMLElement($this->findUserConfig(null, null, $supervisor));
		}
		catch(Exception $ex)
		{
			throw new \Exception($ex->getMessage());
		}
		
		// check groups
		$agentGroups = $this->getUserGroups($agentConf);
		$supervisorGroups = $this->getUserGroups($supervisorConf);
		
		if(array_diff($agentGroups, $supervisorGroups) == $agentGroups)
		{
			throw new \Exception("Berechtigungsfehler für Supervisor $supervisor auf Agent $number");
		}
		
		// set recording settings
		$recConf = $this->setRecordingConf($agentConf, true, $supervisor);
		$result = $this->soapClient->Admin($recConf);
		
		// check result
		if(trim($result) == "<ok/>")
			return true;
			else
			{
				return false;
			}
			
	}
	
	/**
	 * disable pbx recording
	 *
	 * @param int $number agent phone
	 * @param int $supervisor supervisor phone
	 * @throws \Exception if soap client is not configured
	 * @return boolean true, if successful
	 */
	public function disableRecording($number, $supervisor)
	{
		if(empty($this->soapClient))
		{
			throw new \Exception("SoapClient is not configured. Check SOAP parameters in soap_config.php");
		}
		
		try
		{
			$agentConf = new \SimpleXMLElement($this->findUserConfig(null, null, $number));
			$supervisorConf = new \SimpleXMLElement($this->findUserConfig(null, null, $supervisor));
		}
		catch(Exception $ex)
		{
			throw new \Exception($ex->getMessage());
		}
		
		// check groups
		$agentGroups = $this->getUserGroups($agentConf);
		$supervisorGroups = $this->getUserGroups($supervisorConf);
		
		if(array_diff($agentGroups, $supervisorGroups) == $agentGroups)
		{
			throw new \Exception("Berechtigungsfehler für Supervisor $supervisor auf Agent $number");
		}
		
		// set recording settings
		$recConf = $this->setRecordingConf($agentConf, false, $supervisor);
		$result = $this->soapClient->Admin($recConf);
		
		// check result
		if(trim($result) == "<ok/>")
			return true;
			else
			{
				return false;
			}
	}
	
	/**
	 * create recording conf xml
	 *
	 * @param UserInfo $xmlConf agents userconfig
	 * @param boolean $enable enable or disable
	 * @param int $number supervisor phone number
	 */
	private function setRecordingConf($xmlConf, $enable, $number = null)
	{
		// check if phone attribute exists
		if(!isset($xmlConf->user->phone))
		{
			$recConf = $xmlConf->user->addChild('phone')->addChild('rec');
			$recConf->addAttribute("mode", $enable ? "transparent" : "off");
			$recConf->addAttribute("recv", 0);
			$recConf->addAttribute("fkey", 1);
			$recConf->addAttribute("ac", $enable ? 1 : 0);
			$recConf->addAttribute("e164", $enable ? $number : null);
		}// check if rec attribute exists
		elseif(!isset($xmlConf->user->phone->rec))
		{
			$recConf = $xmlConf->user->phone->addChild('rec');
			$recConf->addAttribute("mode", $enable ? "transparent" : "off");
			$recConf->addAttribute("recv", 0);
			$recConf->addAttribute("fkey", 1);
			$recConf->addAttribute("ac", $enable ? 1 : 0);
			$recConf->addAttribute("e164", $enable ? $number : null);
		}
		else
		{
			/** @var $recConf \SimpleXMLElement */
			$recConf = $xmlConf->user->phone->rec;
			$recConf['mode'] = $enable ? "transparent" : "off";
			$recConf['recv'] = 0;
			$recConf['fkey'] = 1;
			$recConf['ac'] = $enable ? 1 : 0;
			$recConf['e164'] = $enable ? $number : null;
		}
		
		// convert to array
		$arrayConf = json_decode(json_encode(simplexml_load_string($xmlConf->asXML())),true);
		$xml = Array2Xml::createXML("modify", $arrayConf);
		return $xml->saveXML();
	}
}