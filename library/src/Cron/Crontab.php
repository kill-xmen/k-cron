<?php
/**
 * Crontab.php.
 * Author: kill-xmen
 * E-mail: kill-xmen@qq.com
 * Date: 2016/2/15
 * Time: 11:12
 */

namespace EasyWork\Cron;


use EasyWork\Cron\Task\Tasks;
use EasyWork\Log;

class Crontab
{
    static public $process_name = "lzm_Master";//进程名称
    static public $pid_file;                    //pid文件位置
    static public $log_path;                    //日志文件位置
    static public $taskParams;                 //获取task任务参数
    static public $taskType;                 //获取task任务的类型
    /**
     * @var Tasks tasksHandle
     */
    static public $tasksHandle;                 //获取任务的句柄
    static public $daemon = false;              //运行模式
    static private $pid;                        //pid
    static public $checktime = true;           //精确对时
    static public $task_list = array();
    static public $unique_list = array();
    static public $worker = false;
    static public $delay = array();

    /**
     * @var \EasyWork\DI
     */
    static public $di = null;

    /**
     * 重启
     */
    static public function restart()
    {
        self::stop(false);
        sleep(1);
        self::start();
    }

    /**
     * 停止进程
     * @param bool $output
     */
    static public function stop($output = true)
    {
        $pid = @file_get_contents(self::$pid_file);
        if ($pid) {
            if (\swoole_process::kill($pid, 0)) {
                \swoole_process::kill($pid, SIGTERM);
                Log::log_write("进程" . $pid . "已结束");
            } else {
                @unlink(self::$pid_file);
                Log::log_write("进程" . $pid . "不存在,删除pid文件");
            }
        } else {
            $output && Log::log_write("需要停止的进程未启动");
        }
    }

    /**
     * 启动
     */
    static public function start()
    {
        if (file_exists(self::$pid_file)) {
            die("Pid文件已存在!\n");
        }
        self::daemon();
        self::set_process_name();
        self::run();
        Log::log_write("启动成功");
    }

    /**
     * 匹配运行模式
     */
    static private function daemon()
    {
        if (self::$daemon) {
            \swoole_process::daemon();
        }
    }

    /**
     * 设置进程名
     */
    static private function set_process_name()
    {
        if (!function_exists("swoole_set_process_name")) {
            self::exit2p("Please install swoole extension.http://www.swoole.com/");
        }
        \swoole_set_process_name(self::$process_name);
    }

    /**
     * 退出进程口
     * @param $msg
     */
    static private function exit2p($msg)
    {
        @unlink(self::$pid_file);
        Log::log_write($msg . "\n");
        exit();
    }

    /**
     * 运行
     */
    static protected function run()
    {
        self::$tasksHandle = new Tasks(strtolower(self::$taskType), self::$taskParams);
        self::register_signal();
        if (self::$checktime) {
            $run = true;
            Log::log_write("正在启动...");
            while ($run) {
                $s = date("s");
                if ($s == 0) {
                    self::load_config();
                    TickTable::get_task();
                    self::register_timer();
                    $run = false;
                } else {
                    Log::log_write("启动倒计时 " . (60 - $s) . " 秒");
                    sleep(1);
                }
            }
        } else {
            self::load_config();
            TickTable::get_task();
            self::register_timer();
        }
        self::get_pid();
        self::write_pid();
        //开启worker
        if (self::$worker) {
            static::$di->get('__sys_worker')->loadWorker();
        }
    }

    /**
     * 过去当前进程的pid
     */
    static private function get_pid()
    {
        if (!function_exists("posix_getpid")) {
            self::exit2p("Please install posix extension.");
        }
        self::$pid = posix_getpid();
    }

    /**
     * 写入当前进程的pid到pid文件
     */
    static private function write_pid()
    {
        file_put_contents(self::$pid_file, self::$pid);
    }

    /**
     * 根据配置载入需要执行的任务
     */
    static public function load_config()
    {
        $time = time();
        $config = self::$tasksHandle->getTasks((array)self::$taskParams);

        foreach ($config as $id => $task) {
            $ret = Parse::parse($task["rule"], $time);
            if ($ret === false) {
                Log::log_write(Parse::$error);
            } elseif (!empty($ret)) {
                TickTable::set_task($ret, array_merge($task, array("id" => $id)));
            }
        }
    }

    /**
     *  注册定时任务
     */
    static protected function register_timer()
    {
        \swoole_timer_tick(60000, function () {
            Crontab::load_config();
        });
        \swoole_timer_tick(1000, function ($interval) {
            Crontab::do_something($interval);
        });
    }

    /**
     * 运行任务
     * @param $interval
     * @return bool
     */
    static public function do_something($interval)
    {
        //是否设置了延时执行
        if (!empty(self::$delay)) {
            foreach (self::$delay as $pid => $task) {
                if (time() >= $task["start"]) {
                    (new Process())->create_process($task["task"]["id"], $task["task"]);
                    unset(self::$delay[$pid]);
                }
            }
        }

        $tasks = TickTable::get_task();
        if (empty($tasks)) return false;
        foreach ($tasks as $task) {
            if (isset($task["unique"]) && $task["unique"]) {
                if (isset(self::$unique_list[$task["id"]]) && (self::$unique_list[$task["id"]] >= $task["unique"])) {
                    continue;
                }
                self::$unique_list[$task["id"]] = isset(self::$unique_list[$task["id"]]) ? (self::$unique_list[$task["id"]] + 1) : 0;
            }
            static::$di->get('__sys_process')->create_process($task["id"], $task);
        }
        return true;
    }

    /**
     * 注册信号
     */
    static private function register_signal()
    {
        \swoole_process::signal(SIGTERM, function ($signo) {
            self::exit2p("收到退出信号,退出主进程");
        });
        \swoole_process::signal(SIGCHLD, function ($signo) {
            while ($ret = \swoole_process::wait(false)) {
                $pid = $ret['pid'];
                if (isset(self::$task_list[$pid])) {
                    $task = self::$task_list[$pid];
                    if ($task["type"] == "crontab") {
                        $end = microtime(true);
                        $start = $task["start"];
                        $id = $task["id"];
                        Log::log_write("{$id} [Runtime:" . sprintf("%0.6f", $end - $start) . "]");
                        $task["process"]->close();//关闭进程
                        unset(self::$task_list[$pid]);
                        if (isset(self::$unique_list[$id]) && self::$unique_list[$id] > 0) {
                            self::$unique_list[$id]--;
                        }
                    }

                    if ($task["type"] == "worker") {
                        $end = microtime(true);
                        $start = $task["start"];
                        $classname = $task["classname"];
                        Log::log_write("{$classname}_{$task["number"]} [Runtime:" . sprintf("%0.6f", $end - $start) . "]");
                        $task["process"]->close();//关闭进程
                        static::$di->get('__sys_worker')->create_process($classname, $task["number"], $task["redis"]);
                    }
                }
            };
        });
        \swoole_process::signal(SIGUSR1, function ($signo) {
            //TODO something
        });

    }
}