<?php
require_once 'Zend/Service/Abstract.php';
require_once 'Zend/Json.php';
require_once 'Zend/Log.php';
/**
 * A PHP CometD Publisher
 * @author zircote / Robert Allen
 * Publishing:
 * <code>
 * $bayuex = new Bayuex(array('url' => 'http://localhost:8080/cometd'));
 * $i = 0;
 * while($i < 10){
 *     $bayuex->publish('/chat/demo',array('chat' => 'test1','user' => 'zircote'));
 * }
 * $bayuex = null;
 * </code>
 *
 * Receiving:
 *
 * <code>
 * $bayuex = new Bayuex(array('url' => 'http://localhost:8080/cometd'));
 * while(!$data = Zend_Json::decode($bayuex->connect())){
 *     var_dump($data);
 * }
 * $bayuex = null;
 * </code>
 *
 */
class Zirc_Service_Bayuex extends Zend_Service_Abstract
{
    const CONNECTION_LONGPOLLING = 'long-polling';
    const CONNECTION_CALLBACK = 'callback-polling';
    /**
     *
     * @var array
     */
    protected $_channels = array();
    /**
     *
     * @var integer
     */
    protected $_id = 0;
    /**
     *
     * @var string
     */
    protected $_connectionType = self::CONNECTION_LONGPOLLING;
    /**
     *
     * @var string
     */
    private $_url;
    /**
     *
     * @var integer
     */
    private $_clientId;
    /**
     *
     * @var Zend_Http_Response
     */
    private $_lastResponse;
    /**
     *
     * @var Zend_Log
     */
    private $_log;
    /**
     *
     * @var array
     */
    private $_options;
    /**
     *
     * @param Zend_Config|array $options
     */
    public function __construct($options)
    {
        if($options instanceof Zend_Config){
            $options = $options->toArray();
        }
        if(!$options['log'] instanceof Zend_Log && isset($options['log'])){
            $this->_log = Zend_Log::factory($options['log']);
        }
        if(isset($options['url'])){
            $this->_url = $options['url'];
        }
        if(isset($options['connectionType'])){
            $this->_connectionType = $options['connectionType'];
        }
        if(!self::$_httpClient){
            self::setHttpClient(
                new Zend_Http_Client($this->_url, array(
                    'strictredirects' => TRUE,
                    'timeout' => 600,
                    'keepalive' => true))
            );
        }
        self::getHttpClient()->setUri($this->_url);
        $this->handShake();
    }
    /**
     * connect to server
     */
    public function connect()
    {
        $msg = $this->_getMessage('/meta/connect');
        $msg['connectionType'] = $this->_connectionType;
        try {
            $this->_sendMessage(
                $this->_url . '/connect', $this->_getPayload(array($msg))
            );
        } catch (Zend_Http_Client_Exception $e){
            // @todo add some form of logging mechanism here
        }
        return $this->_lastResponse->getBody();
    }
    /**
     * <code>
     * $b = new Bayuex(array('url' => 'http://127.0.0.1:8080/cometd'));
     * $b->subscribe('/chat/*');
     * while ($data = $b->connect('/chat/demo')){
     *     $data = Zend_Json::decode($data, Zend_Json::TYPE_OBJECT);
     *     foreach ($data as $m) {
     *        if($m->data){
     *            print_r( $m->data );
     *         }
     *     }
     *     usleep(100);
     * }
     * </code>
     * subscribe to server
     * @param array|string $subscription
     */
    public function subscribe($subscription)
    {
        if(!is_array($subscription)){
            $subscription = array($subscription);
        }
        $payload = array();
        foreach ($subscription as $sub) {
            $msg = $this->_getMessage('/meta/subscribe');
            $msg['subscription'] = $subscription;
            array_push($payload, $msg);
            $this->_addChannel($sub);
        }
        $this->_sendMessage($this->_url . '/connect', $this->_getPayload($payload));
        return $this->_lastResponse->getBody();
    }
    /**
     * unsubscribe to server channel(s)
     * @param array|string $subscription
     */
    public function unsubscribe($subscription)
    {
        if(!is_array($subscription)){
            $subscription = array($subscription);
        }
        $payload = array();
        foreach ($subscription as $sub) {
            $msg = $this->_getMessage('/meta/unsubscribe');
            $msg['subscription'] = $subscription;
            array_push($payload, $msg);
            $this->_delChannel($sub);
        }
        $this->_sendMessage($this->_url . '/connect', $this->_getPayload($payload));
        return $this->_lastResponse->getBody();
    }
    /**
     *
     * publish to server a message on channel with data
     * @param string $channel
     * @param string $data
     */
    public function publish($channel, $data = null )
    {
        if($data){
            $channel = array('channel' => $channel, 'data' => $data);
        }
        $payload = array();
        print_r($channel);
        foreach ($channel as $pack) {
            array_push($payload, $this->_getMessage($pack['channel'], $pack['data']));
        }
        $this->_sendMessage(
            $this->_url . '/publish', $this->_getPayload($payload)
        );
        return $this->_lastResponse->getBody();
    }
    /**
     *
     * disconnect from the server
     */
    public function disconnect()
    {
        $this->unsubscribe(array_keys($this->_channels));
        $msg = $this->_getMessage('/meta/disconnect');
        $this->_sendMessage(
            $this->_url . '/disconnect', $this->_getPayload(array($msg))
        );
        $this->_clientId = null;
    }
    /**
     * negotiate client status
     */
    public function handShake()
    {
        $msg = $this->_getMessage('/meta/handshake');
        $msg['version'] = '1.0';
        $msg['minimumVersion'] = '0.9';
        $msg['supportedConnectionTypes'] = array("callback-polling","long-polling");
        $this->_sendMessage(
            $this->_url . '/handshake', $this->_getPayload(array($msg))
        );
        $result = Zend_Json::decode(
            $this->_lastResponse->getBody(), Zend_Json::TYPE_OBJECT
        );
        if($result[0]->successful){
            $this->_clientId = $result[0]->clientId;
            $this->connect();
        } else {
            throw new RuntimeException('failed handshake');
        }
    }
    /**
     *
     * create the general data array that will be published
     * @access protected
     * @param string $channel
     * @param string $data
     * @throws RuntimeException
     */
    protected function _getMessage($channel, $data = null)
    {
        $msg = array(
            'channel' => $channel,
            'id' => $this->_id++
        );
        if($data){
            $msg['data'] = $data;
        }
        if($this->_clientId){
            $msg['clientId'] = $this->_clientId;
        }
        return $msg;
    }
    /**
     * format the paylod to be sent to the server
     * @param array $payload
     */
    protected function _getPayload(array $payload)
    {
        return (
             str_replace('\\', '', Zend_Json::encode($payload))
         );
    }
    /**
     *
     * insure the client is dosconnected from the pool
     */
    public function __destruct()
    {
        $this->disconnect();
    }
    /**
     *
     * adds channel name to the container
     * @param string $channel
     */
    protected function _addChannel($channel)
    {
        $this->_channels[$channel] = $channel;
    }
    /**
     *
     * removes from the container a channels
     * @param string $channel
     */
    protected function _delChannel($channel)
    {
        unset($this->_channels[$channel]);
    }
    /**
     * returns and array of all currently subscribed channels
     */
    public function getChannels()
    {
        return $this->_channels;
    }
    /**
     *
     * @param string $url
     * @param string $payload
     */
    protected function _sendMessage($url, $payload)
    {
        try{
            $this->_lastResponse = self::getHttpClient()
                ->setUri($url)
                ->setParameterPost('message', $payload)
                ->request(Zend_Http_Client::POST);
            self::getHttpClient()->resetParameters(true);
            return $this->_lastResponse->getStatus();
        } catch (Zend_Http_Client_Exception $e){
            // @todo add some form of logging mechanism here
            return 500;
        }
    }
}
