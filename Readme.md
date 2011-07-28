
$b = new Zirc_Service_Bayuex(array('url' => 'http://127.0.0.1:8080/cometd'));
$b->subscribe(array('/chat/demo', '/some/test/'));
$o = 0;
$payload = array(
    array('channel' => '/chat/demo',
          'data' => array ('user' => 'zircote','chat' => 'test1')
    ),
    array('channel' => '/chat/demo',
          'data' => array ('user' => 'zircote','chat' => 'test2')
    ),
    array('channel' => '/chat/demo',
          'data' => array ('user' => 'zircote','chat' => 'test3')
    )
);
$b->publish($payload);
while ($data = $b->connect('/chat/demo')){

    $data = Zend_Json::decode($data, Zend_Json::TYPE_OBJECT);
    foreach ($data as $m) {
        if($m->data){
            print_r( $m->data );
        }
    }
    usleep(100);
    if (++$o == 1000000) {
        exit;;
    }
}