<?php
/**
 * WorkerBase.php.
 * Author: kill-xmen
 * E-mail: kill-xmen@qq.com
 * Date: 2016/2/15
 * Time: 11:29
 */

namespace App\Worker;

use EasyWork\EasyDB;
use EasyWork\Log;

abstract class Base
{
    /**
     * @var \Redis
     */
    private $redis;
    private $queue;
    private $flag = true;
    /**
     * @var \swoole_process
     */
    protected $worker;
    private $ppid = 0;
    /**
     * @var \mysql   
     */
    private $db = array();
    private $param = "";

    public function content($config)
    {

        if (!isset($config["host"]) || !isset($config["port"]) || !isset($config["timeout"]) || !isset($config["queue"])) {
            Log::log_write(vsprintf(" host=%s,port=%s,timeout=%s,queue=%s", $config));
            exit;
        }

        $this->redis = new \Redis();
        if (!$this->redis->pconnect($config["host"], $config["port"], isset($config["timeout"]))) {
            Log::log_write(vsprintf("redis can't connect.host=%s,port=%s,timeout=%s", $config));
            exit;
        }
        if (isset($config["db"]) && is_numeric($config["db"])) {
            $this->redis->select($config["db"]);
        }
        $this->queue = $config["queue"];
        //阀值判断
        if(isset($config["limit"])){
            $this->flag = intval($config['limit']);
        }
        if(isset($config['param_list'])){
            $this->param = $config['param_list'];
        }
        if(isset($config['database']) && $config['database'] != ""){
            $dblist = explode("|", $config['database']);
            foreach ($dblist as $key => $value) {
                //初始化DB实例
                $this->db[$value] = new EasyDB($value);
            }
        }
        // var_dump($this->db);
    }

    public function getQueue()
    {
        return $this->redis->rpop($this->queue);
    }
    //参数解析 只支持 带 | 分隔符
    public function param_parse($task)
    {
        $field = explode("|", $this->param);
        $param = explode("|", $task);
        $len = count($field);
        $len1 = count($param);
        //参数不匹配
        if($len != $len1){
            Log::log_write("参数不匹配:{$this->param}---{$task}");
            return false;
        }
        $data = array();
        foreach ($field as $k => $v) {
            $data[$v] = $param[$k];
        }
        return $data;
    }
    public function tick($worker)
    {
        $this->worker = $worker;
        $db = $this->db;
        \swoole_timer_tick(500, function () use ($db) {
            $i = $this->flag;
            while ($i) {
                $this->checkExit();
                $task = $this->getQueue();
                if (empty($task)) {
                    break;
                }
                $this->run($task, $db);
                if($this->flag !== true){
                    $i--;
                }
            }
        });
    }

    protected function _exit()
    {
        $this->worker->exit(1);
    }

    /**
     * 判断父进程是否结束
     */
    private function checkExit()
    {
        $ppid = posix_getppid();
        if ($this->ppid == 0) {
            $this->ppid = $ppid;
        }
        if ($this->ppid != $ppid) {
            $this->_exit();
        }
    }

    /**
     * 运行入口
     * @param $task
     * @return mixed
     */
    abstract public function run($task);
}