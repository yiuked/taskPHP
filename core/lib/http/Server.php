<?php
/**
 * taskPHP
 * @author     码农<8044023@qq.com>,cqcqphper 小草<cqcqphper@163.com>
 * @copyright  taskPHP
 * @license    https://git.oschina.net/cqcqphper/taskPHP
 */
namespace core\lib\http;
use core\lib\socket\Server as SocketServer;
use core\lib\Exception;
/**
 * http服务端
 * @author cqcqphper 小草<cqcqphper@163.com>
 */
class Server{
    /**
     * 服务配置
     * @var array
     */
    private $_config;
    /**
     * socket服务对象
     * @var SocketServer
     */
    private $_socket;

    /**
     * 请求方式
     * @var string
     */
    private $_method;
    /**
     * 请求的路径
     * @var string
     */
    private $_requestUri;
    /**
     * 请求的参数
     * @var string
     */
    private $_queryString;
    /**
     * 请求的文件
     * @var unknown
     */
    private $_filename;
    
    private $_contentType;
    private $_contentLength;

    /* request stdin */
    private $_queryEntity;

    private $_request=array();
    /* resp Server */
    const RESP_SERVER = "Server: lzx-tiny-httpd/0.1.0";
    const RESP_CONTENT_TYPE = "Content-Type: text/html";

    /* cgi请求返回json类型postman才能友好显示 */
    const RESP_CGI_CONTENT_TYPE = "Content-Type: application/json;charset=utf-8";
    
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    public function __construct($config){
        //配置
        $this->_config = $config;

        //socket
        $this->_socket = new SocketServer($this->_config['address'], $this->_config['port']);
    }

    public function listen(){
        //监听
        $this->_socket->listen();
        while(true){
            //连接
            $this->_socket->accept();
            //处理请求
            $this->acceptRequest();
        }
        $this->_socket->closeListenFD();
    }

    public function acceptRequest(){
        //根据请求状态行解析出method,query_string,filename
        $this->parseQueryStatusLine();
        
        //只支持GET和POST方法
        if ($this->_method !== self::METHOD_POST && $this->_method !== self::METHOD_GET) {
            return $this->error('Only support GET and POST methods');
        }

        //解析缓冲区剩余数据,GET就丢弃header头,POST则解析请求体
        $this->parseQueryEntity();
        
        /* $file = $this->getFileName();
        $fileInfo = new \SplFileInfo($file);
        if(!$fileInfo->isFile()){
            return $this->error('The file you are accessing does not exist');
        } */
        /* 获取get和post的值  */
        $this->resolveRequest();
        //判断请求的文件是否可执行,cgi请求的文件需要有可执行权限
        /* if(!$fileInfo->isExecutable()){
            $this->respData('hello taskPHP');
        }else{
            $this->cat($file);
        } */
        
        if($this->_method=='POST'){
            $html=json_encode($_POST);
        }else{
            if($_GET){
                //$html=json_encode($_GET);
                if($_GET['action']=='cmd'){
                    if($_GET['content']=='select'){
                        $html='';
                        $TaskManage = new \core\lib\TaskManage();
                        foreach ($TaskManage->run_worker_list() as $item){
                            $worker=$item->get_worker();
                            $html.= str_pad($worker->get_name(), 20).\core\lib\Timer::timer_to_string($worker->get_timer()). str_pad('', 10). date("Y-m-d H:i:s",$item->get_run_time()).PHP_EOL;
                        }
                    }elseif($_GET['content']=='reload'){
                        $TaskManage = new \core\lib\TaskManage();
                        $TaskManage->load_worker();
                        $html='task reload ok';
                    }elseif($_GET['content']=='loglist'){
                        $html='';
                        $TaskManage = new \core\lib\TaskManage();
                        foreach ($TaskManage->worker_result() as $item){
                            list($time,$task_class,$result)=$item;
                            $html.= str_pad($task_class, 20).$time. str_pad('', 10). $result.PHP_EOL;
                        }
                    }elseif($_GET['content']=='delete'){
                        $name=$_GET['name'];
                        if(!$name){
                            $html='specify the name of the task to delete';
                        }else{
                            $TaskManage = new \core\lib\TaskManage();
                            $TaskManage->del_worker($name);
                            $html= $name.' delete ok';
                        }
                        
                    }
                }
            }else{
                $html='<!DOCTYPE html>
                <html>
                <meta charset="utf-8" />
                <title>hello taskPHP</title>
                <body>
                <p><a href="/?action=cmd&content=select">查询任务</a></p>
                <p><a href="/?action=cmd&content=reload">重载任务</a></p>
                <p><a href="/?action=cmd&content=loglist">任务日志</a></p>
                <p><a href="/?action=cmd&content=delete&name=">删除任务</a></p>
                </body>
                </html>';
            }
        } 
        $this->respData($html);
        
        $this->_socket->closeConnectFD();
    }

    /**
     * 解析 get 和 post的
     *   */
    protected function resolveRequest(){
        //解析post 的数据
        parse_str($this->_queryEntity,$_POST);
        //解析 get数据
        parse_str($this->_queryString,$_GET);
    }
    
    /**
     * 是否是动态请求
     *
     * @param $method
     * @param $queryString
     * @return bool
     */
    public function isCgi(){
        return ($this->_method == self::METHOD_POST || !empty($this->_queryString));
    }

    /**
     * 解析请求状态行
     *
     * @param $connfd
     */
    public function parseQueryStatusLine(){
        $line = $this->_socket->readLine();

        $statusLineArr = explode(' ', trim($line));
        if (!is_array($statusLineArr) || count($statusLineArr) !== 3) {
            \core\lib\Ui::displayUI('parse request status line err.',false);
            return false;
        }

        list($this->_method, $this->_requestUri, $protocal) = $statusLineArr;

        if (strpos($this->_requestUri, '?') !== false) {
            $this->_filename    = strstr($this->_requestUri, '?', true);
            $this->_queryString = trim(strstr($this->_requestUri, '?'), '?');
        }else{
            $this->_filename    = $this->_requestUri;
            $this->_queryString = '';
        }
    }

    public function parseQueryEntity(){
        if ($this->_method == self::METHOD_GET) {
            do {
                $line = $this->_socket->readLine();
            } while (!empty($line)); // \r\n返回空
        } else {
            do {
                $line = $this->_socket->readLine();
                if (strpos($line, 'Content-Length:') !== false) {
                    $this->_contentLength = intval(trim(str_replace('Content-Length:', '', $line)));
                }

                if (strpos($line, 'Content-Type:') !== false) {
                    $this->_contentType = trim(str_replace('Content-Type:', '', $line));
                }
            } while (!empty($line));

            if (empty($this->_contentLength)) {
                \core\lib\Ui::displayUI('POST RQUEST CONTENT-LEHGTH IS NULL.',false);
                return false;
            }

            //读取消息体
            $this->_queryEntity = $this->_socket->read($this->_contentLength);
        }
    }

    public function respData($resp){
        $this->headers();
        $this->_socket->write($resp);
    }

    public function cat($file){
        $this->headers();

        $fileObj = new \SplFileObject($file, "r");
        while (!$fileObj->eof()) {
            $line = $fileObj->fgets();
            $this->_socket->write($line);
        }
    }

    public function headers(){
        $response = "HTTP/1.1 200 OK".PHP_EOL;
        $response .= self::RESP_SERVER.PHP_EOL;
        $response .= $this->isCgi() ? self::RESP_CGI_CONTENT_TYPE.PHP_EOL : self::RESP_CONTENT_TYPE.PHP_EOL;
        $response .= PHP_EOL;
        $this->_socket->write($response);
    }

    public function error($response){
        $this->headers();
        $this->_socket->write($response);
        $this->_socket->closeConnectFD();
    }
}