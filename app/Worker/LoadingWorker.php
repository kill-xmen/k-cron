<?php
/**
 * ReadBookWorker.php.
 * Author: kill-xmen
 * E-mail: kill-xmen@qq.com
 * Date: 2016/2/15
 * Time: 15:49
 */

namespace App\Worker;

use EasyWork\EasyDB;
use EasyWork\Log;



class LoadingWorker extends Base
{

    /**
     * 运行入口
     * @param $task
     * @return mixed
     */
    public function run($task, $dblist=array())
    {
        //结束进程
        if ($task == 'exit') {
            $this->_exit();
        }
        $data = $this->param_parse($task);
        if(empty($data) || !$data){
            $this->_exit();
        }
        // echo "run:";
        // var_dump($db->getDbconfig());
        $db = $dblist['test_123'];
        $table = 'log_loading_' . date("Ymd", strtotime($data['logdate']));
        $rs = $db->insert($table, $data);
        if(!$rs){
            //获取mysql错误 MySQL server has gone away
            $sqlerr = $db->getError();
            if($sqlerr[1] == 2006){
                $restdb = new EasyDB($db->getDbconfig()['dbname']);
            }
            $rs = $restdb->insert($table, $data);
            if(!$rs){
                Log::log_write("打点数据插入失败:". $task);
            }
            echo "rs:".$rs;
            $this->_exit();
        }
        echo "rs:".$rs;
    }
}