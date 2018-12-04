<?php 

namespace TS\RestclientUi\Controller;

/***************************************************************
*  Copyright notice
*
*  (c) 2015 Tommaso Sacramone <tommasosacramone.www@gmail.com>
*  
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 3 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TS\Restclient\Client\HttpClient;
use TS\Restclient\Client\HttpClientRequest;
use TS\Restclient\Client\HttpClientException;
use TYPO3\CMS\Extbase\Annotation as Extbase;

/**
 * A REST client UI 
 */
class HttpClientUiController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController {

    /**
     * historyRepository
     *
     * @Extbase\Inject
     * @var \TS\RestclientUi\Domain\Repository\HistoryRepository
     */
    protected $historyRepository = NULL;
  
  protected $extConf = NULL;
  
  protected $httpClientUiMethods = array(    
    HttpClientRequest::METHOD_GET => HttpClientRequest::METHOD_GET,
    HttpClientRequest::METHOD_POST => HttpClientRequest::METHOD_POST,
    HttpClientRequest::METHOD_PUT => HttpClientRequest::METHOD_PUT,
    HttpClientRequest::METHOD_DELETE => HttpClientRequest::METHOD_DELETE       
  );
  
  public function initializeAction() {
    $this -> extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('restclient_ui');
    
    if ($this -> extConf['history_storage'] !== "") {  
      $querySettings = $this -> historyRepository -> createQuery() -> getQuerySettings();
      $querySettings -> setStoragePageIds(array(intval($this -> extConf['history_storage'])));
      $this -> historyRepository -> setDefaultQuerySettings($querySettings);
    }
  }
  
  public function indexAction() {
    if ($this -> request -> hasArgument('history')) {
      $historyUid = $this -> request -> getArgument('history');
      $history = $this -> historyRepository -> findByUid($historyUid);
      $request = array();
      $request['url'] = $history -> getUrl();
      $request['method'] = $history -> getMethod();
      $request['header'] = unserialize($history -> getHeader());
      $request['data'] = unserialize($history -> getFields());      
      $this -> view -> assign('request', $request);  
    }
    
    $this -> view -> assign('methods', $this -> httpClientUiMethods);  
  }
  
  protected function encodeHtmlChars($dataToEncode) {
    if (is_array($dataToEncode)) {
      $data = array();
      foreach ($dataToEncode as $key => $value) {
        $data[htmlentities($key, ENT_QUOTES)] = htmlentities($value, ENT_QUOTES);
      }
    }
    else {
      $data = htmlentities($dataToEncode, ENT_QUOTES);
    }
    return $data;
  }
  
  protected function getWsResponse($methodRequest, $urlRequest, $headerRequest, $dataRequest) {
    $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('restclient_ui');
    
    $objectManager = GeneralUtility :: makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager');  
    
    //Request
    $httpClientRequest = $objectManager -> get('TS\Restclient\Client\HttpClientRequest');
    $httpClientRequest -> setMethod($methodRequest);
    $httpClientRequest -> setUrl($urlRequest);
    if (isset($headerRequest)) {
      $header = array();
      foreach ($headerRequest as $i => $v) {
        $index = intval($i);
        if ($index % 2 !== 0) {
          $name = $headerRequest[$index];
          $value = $headerRequest[$index+1];
          $header[] = $name.": ".$value; 
        }
        else {
          continue;
        }        
      }      
      $httpClientRequest -> setHeader($header);
    }
    if (isset($dataRequest)) {
      $data = array();      
      foreach ($dataRequest as $i => $v) {
        $index = intval($i);        
        if ($index % 2 !== 0) {
          $data[$v] = $dataRequest[$i+1];
        }
        else {
          continue;
        }        
      }       
      $httpClientRequest -> setFields($data);
    }      
    
    //Client    
    $httpClient =  $objectManager -> get('TS\Restclient\Client\HttpClient');        
    if ($extConf['client_use_restclient_settings'] === "1") {//It will use the restclient configuration, it will ignore the next fields      
      $httpClient -> setErrorThrowException(true);//Force
    }
    else {
      $httpClient -> reset(false);//reset basic configuration 
      $httpClient -> setErrorThrowException(true);//Force     
      //Setting log
      if ($extConf['error_log'] !== "") {
        $httpClient -> setErrorLog(intval($extConf['error_log']));
      }
      //Setting timeout
      if ($extConf['client_connection_timeout'] !== "") {
        $httpClient -> setConnectionTimeout(intval($extConf['client_connection_timeout']));
      }
      if ($extConf['client_timeout'] !== "") {
        $httpClient -> setTimeout(intval($extConf['client_timeout']));
      }
      //Setting follow redirect
      if ($extConf['client_follow_redirect'] === "1") {
        if ($extConf['client_max_redirect'] !== "") {
          $maxRedirect = intval($extConf['client_max_redirect']);
          $httpClient -> setFollowRedirect(true, $maxRedirect);   
        }
        else {
          $httpClient -> setFollowRedirect(true); 
        }
      }
      //Setting proxy
      if ($extConf['client_proxy_typo3'] === "1") {                
        if ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_host'] !== "") $proxyHost = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_host']; 
        $proxyPort = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_port'] !== "" ? intval($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_port']) : null;        
        if ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_user'] !== "") {
          $proxyCredentials = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_user'];
          if ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_password'] !== "") {
            $proxyCredentials .= ":".$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_password'];            
          }          
        }
        else {
          $proxyCredentials = null;
        }
        $proxyAuthScheme = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_auth_scheme'] !== "" ? $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy_auth_scheme'] : null;              
      }
      else {
        if ($extConf['client_proxy_address'] !== "") $proxyHost = $extConf['client_proxy_address'];    
        $proxyPort = $extConf['client_proxy_port'] !== "" ? intval($extConf['client_proxy_port']) : null;    
        $proxyCredentials = $extConf['client_proxy_credentials'] ? $extConf['client_proxy_credentials'] : null;
        $proxyAuthScheme = $extConf['client_proxy_auth_scheme'] ? $extConf['client_proxy_auth_scheme'] : null;
      }
      if (isset($proxyHost)) {
        $httpClient -> setProxy($proxyHost, $proxyPort, $proxyCredentials, $proxyAuthScheme);  
      }
      //Setting User Agent
      if ($extConf['client_user_agent'] !== "") $httpClient -> setUserAgent($extConf['user_agent']);          
    }
    $httpClient -> setRequest($httpClientRequest);
    
    //Response
    $response = array();
    try {
      $httpClient -> exec();
      
      $httpClientResponse = $httpClient -> getResponse();    
      $response['status'] = array('success' => true);
      $response['data'] = array();
      $response['data']['httpCode'] = $this -> encodeHtmlChars($httpClientResponse -> getHttpCode());
      $response['data']['header'] = $this -> encodeHtmlChars($httpClientResponse -> getHeader(true));     
      $response['data']['body'] = $this -> encodeHtmlChars($httpClientResponse -> getBody());      
      $response['data']['totalTime'] = $httpClientResponse -> getTotalTime();
      $response['data']['connectionTime'] = $httpClientResponse -> getConnectTime();
    }
    catch (HttpClientException $e) {
      $response['status'] = array('success' => false, 'message' => $e->getMessage());      
    }
    
    return $response;
  }
  
  protected function setHistory($methodRequest, $urlRequest, $headerRequest, $dataRequest, $storage = null) {
    $objectManager = GeneralUtility :: makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager');

    $history = $objectManager -> get('TS\RestclientUi\Domain\Model\History');
    if (isset($storage)) {       
      $history -> setPid($storage);
    }    
    $history -> setMethod($methodRequest);
    $history -> setUrl($urlRequest);
    $header = array();
    foreach ($headerRequest as $i => $v) {
      $index = intval($i);
      if ($index % 2 !== 0) {
        $name = $headerRequest[$index];
        $value = $headerRequest[$index+1];
        $header[$index] = array("name" => $name, "value" => $value); 
      }
      else {
        continue;
      }
    }
    if (count($header) > 0) {
      $history -> setHeader(serialize($header));
    }
    foreach ($dataRequest as $i => $v) {
      $index = intval($i);
      if ($index % 2 !== 0) {
        $name = $dataRequest[$index];
        $value = $dataRequest[$index+1];
        $data[$index] = array("name" => $name, "value" => $value); 
      }
      else {
        continue;
      }
    }
    if (count($data) > 0) {
      $history -> setFields(serialize($data));
    }
    
    $historyRepository = $objectManager -> get('TS\RestclientUi\Domain\Repository\HistoryRepository');        
    $historyRepository -> add($history);
    $persistenceManager = $objectManager -> get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager'); 
    $persistenceManager -> persistAll();
  }
  
  public function sendRequest ($params = array(), \TYPO3\CMS\Core\Http\AjaxRequestHandler &$ajaxObj = NULL) {
    $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('restclient_ui');
       
    $rcuiRequest = GeneralUtility :: _POST('tx_restclientui_tools_restclientuirestclientui');
    if (isset($rcuiRequest) && is_array($rcuiRequest) && isset($rcuiRequest['request']) && is_array($rcuiRequest['request'])) {
      $methodRequest = isset($rcuiRequest['request']['method']) ? $rcuiRequest['request']['method'] : null;
      $urlRequest = isset($rcuiRequest['request']['url']) ? $rcuiRequest['request']['url'] : null;
      $headerRequest = isset($rcuiRequest['request']['header']) ? $rcuiRequest['request']['header'] : null;
      $dataRequest = isset($rcuiRequest['request']['data']) ? $rcuiRequest['request']['data'] : null;      
      
      if ($extConf['history_enable'] === "1") {        
        $historyStorage = null;
        if ($extConf['history_storage'] !== "") $historyStorage = intval($extConf['history_storage']);
        $this -> setHistory($methodRequest, $urlRequest, $headerRequest, $dataRequest, $historyStorage); 
      }
      $response = $this -> getWsResponse($methodRequest, $urlRequest, $headerRequest, $dataRequest);
    }
    else {
      $response = array('status'=> array('success' => false));
    }
    
    $ajaxObj->setContentFormat('json');
    $ajaxObj->addContent('response', $response);
  } 
  
}

?>
