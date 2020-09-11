<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\Btaction;
use epanel\Epanel;
use Exception;
use think\Config;
use think\Debug;
use think\Cache;

/**
 * 计划任务监控接口
 */
class Queue extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];
    protected $token       = '';
    protected $_error      = '';
    protected $is_index    = '';

    protected $successNum = [];

    protected $errorNum = [];


    public function _initialize()
    {
        parent::_initialize();
        $this->token = $this->request->request('token');
        if ($this->token) {
            if (Config::get('site.queue_key') !== $this->token) {
                $this->error('接口密钥错误');
            }
        } else {
            $this->error('token为空');
        }
        $this->model = model('Queue');
    }

    /**
     * 计划任务队列
     *
     * @ApiTitle    计划任务队列
     * @ApiSummary  计划任务队列
     * @ApiMethod   (GET)
     * @ApiParams   (name="token", type="string", required=true, description="计划任务监控密钥")
     */
    public function index()
    {
        set_time_limit(0);
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '128M');
        $this->is_index = 1;
        // 列出所有有效任务
        $queList = $this->model->where(['status' => 'normal'])->order('weigh desc')->select();
        if (!$queList) {
            $this->error('无有效任务');
        }
        $n = [];
        // 遍历任务执行
        foreach ($queList as $key => $value) {
            Debug::remark('begin');
            // 计算判断在有效执行时间内的
            try {
                $e_runtime = $value['runtime'] + $value['executetime'];
                if (time() >= $e_runtime) {
                    // 开始执行指定方法

                    if ($value->configgroup) {
                        $configA = array_column(json_decode($value->configgroup, 1), 'value', 'key');
                    }
                    $limit     = isset($configA['limit']) ? $configA['limit'] : 10;
                    $ftqq      = isset($configA['ftqq']) ? $configA['ftqq'] : 0;
                    $checkTime = isset($configA['checkTime']) ? $configA['checkTime'] : 20;

                    switch ($value['function']) {
                        case 'email':
                            $s                          = $this->emailTask($limit);
                            $s ? $n[$value['function']] = $s : '';
                            break;
                        case 'btresource':
                            $s                          = $this->btResourceTask($limit, $checkTime);
                            $s ? $n[$value['function']] = $s : '';
                            break;
                        case 'hosttask':
                            $s                          = $this->hostTask();
                            $s ? $n[$value['function']] = $s : '';
                            break;
                        case 'hostclear':
                            $s                          = $this->hostClear();
                            $s ? $n[$value['function']] = $s : '';
                            break;
                        default:
                            $n[$value['function']] = 'null';
                            break;
                    }
                    // 记录任务最后执行时间
                    $up = model('queue')->update([
                        'runtime' => time(),
                        'id'      => $value->id,
                    ]);
                } else {
                    // $n[$value['function']] = 'continue';
                    // 当前方法跳过
                }
            } catch (Exception $e) {
                $n[$value['function']] = $e->getMessage();
            }
            Debug::remark('end');
        }
        if ($n) {
            // 记录执行日志
            model('queueLog')->data([
                'logs'      => json_encode($n),
                'call_time' => Debug::getRangeTime('begin', 'end'),
            ])->save();
        }

        $this->success('执行完成', $n);
        // 记录执行结果及执行时间
    }

    /**
     * 邮件队列
     *
     * @ApiTitle    邮件队列
     * @ApiSummary  邮件队列
     * @ApiMethod   (GET)
     * @ApiParams   (name="token", type="string", required=true, description="计划任务监控密钥")
     * @ApiParams   (name="limit", type="int", required=false, description="一次发送多少条", sample="10")
     */
    public function emailTask($limit = 5)
    {
        set_time_limit(0);
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '128M');
        $successNum = [];
        $errorNum   = [];

        return $this->is_index ? '' : $this->success('无有效任务');

        $list = model('Email')->where(['status' => '0'])->limit($limit)->select();
        if ($list) {
            $obj = \app\common\library\Email::instance();
            foreach ($list as $key => $value) {
                $result = $obj
                    ->to($value->email)
                    ->subject($value->title)
                    ->message($value->content)
                    ->send();
                if ($result) {
                    model('Email')->where('id', $value->id)->update(['status' => 1]);

                    $successNum[][$value->email] = '发送成功';
                } else {
                    model('Email')->where('id', $value->id)->update(['status' => 2]);

                    $errorNum[][$value->email] = '发送失败' . $obj->getError();
                }
            }
            return $this->is_index ? [$successNum, $errorNum] : $this->success('请求成功', [$successNum, $errorNum]);
        } else {
            return $this->is_index ? '' : $this->success('无有效任务');
        }
    }

    /**
     * 宝塔资源监控
     *
     * @param integer $limit
     * @param integer $checkTime
     * @param integer $tz_user
     * @param integer $tz_admin
     * @param integer $ftqq
     * @return void
     */
    public function btResourceTask($limit = 20, $checkTime = 0)
    {
        set_time_limit(0);
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '128M');
        $successNum = [];
        $errorNum   = [];
        //额定时间分钟数
        $time      = time() - 60 * $checkTime;
        
        $hostList = model('Host')
            ->alias('h')    
            ->where('h.check_time','<',$time)
            ->join('sql s','s.vhost_id = h.id','LEFT')
            ->limit($limit)
            ->field('h.*,s.username as sql_name,s.id as sql_id')
            ->select();
            
        if ($hostList) {
            foreach ($hostList as $key => $value) {
                // 写入检查时间，防止后面报错导致重复检查

                $value->check_time = time();
                $value->save();
                // 连接宝塔
                $bt            = new Btaction();
                $bt->bt_id     = $value->bt_id;
                $bt->bt_name   = $value->bt_name;
                // 测试连接
                if (!$bt->test()) {
                    $errorNum[][$value->bt_name] = $bt->_error;
                    continue;
                }
                // 查找站点是否存在
                $Websites = $bt->getSiteInfo($value->bt_name);
                if (!$Websites) {
                    $errorNum[][$value->bt_name] = $bt->_error;
                    continue;
                }

                $v = $bt->resource($value->sql_name, 1);

                if (isset($v['sql']) && isset($v['site']) && isset($v['flow'])) {
                    $getSqlSizes = is_numeric($v['sql']) ? bytes2mb($v['sql']) : 0;
                    $getWebSizes = is_numeric($v['site']) ? bytes2mb($v['site']) : 0;
                    $total_size  = is_numeric($v['flow']) ? bytes2mb($v['flow']) : 0;
                } else {
                    $errorNum[][$value->bt_name] = $v;
                    continue;
                }
                
                $value->site_size = $getWebSizes;
                $value->flow_size = $total_size;
                $value->sql_size = $getSqlSizes;
                $value->save();

                // 记录入库
                // Db::startTrans();

                if (($getSqlSizes > $value->sql_max && $value->sql_max != '0') || ($getWebSizes > $value->site_max && $value->site_max != '0') || ($total_size > $value->flow_max && $value->flow_max != '0')) {
                    // 超出停止
                    $stop = $bt->webstop();
                    if ($stop !== true) {
                        $errorNum[][$value->bt_name] = isset($stop['msg']) ? $stop['msg'] : '停止失败';
                        continue;
                    }
                    $value->status = 'excess';
                    $value->save();
                } elseif ($value->status == 'excess') {
                    // 恢复主机
                    $start = $bt->webstart();
                    if ($start !== true) {
                        $errorNum[][$value->bt_name] = isset($start['msg']) ? $start['msg'] : '开启失败';
                        continue;
                    }
                    if($value->endtime>time()){
                        $s = 'normal';
                    }else{
                        $s = 'expired';
                    }
                    $value->status = $s;
                    $value->save();
                }

                $successNum[][$value->bt_name] = ['sql_size' => $getSqlSizes, 'site_size' => $getWebSizes, 'flow_size' => $total_size];
            }
            return $this->is_index ? [$successNum, $errorNum] : $this->success('请求成功', [$successNum, $errorNum]);
        } else {
            return $this->is_index ? '' : $this->success('无有效任务');
        }
    }

    
    /**
     * 主机过期监控
     *
     * 
     * @return void
     */
    public function hostTask()
    {
        $successNum = [];
        $errorNum   = [];
        $hostList = model('Host')->where('endtime','<=',time())->where('status','<>','expired')->select();
        if ($hostList) {
            foreach ($hostList as $key => $value) {
                $bt            = new Btaction();
                $bt->bt_id     = $value->bt_id;
                $bt->bt_name   = $value->bt_name;
                // 测试连接
                if (!$bt->test()) {
                    $errorNum[][$value->bt_name] = $bt->_error;
                    continue;
                }

                // 查找站点是否存在
                $Websites = $bt->getSiteInfo($value->bt_name);
                if (!$Websites) {
                    $errorNum[][$value->bt_name] = $bt->_error;
                    continue;
                }

                switch (Config::get('site.expire_action')) {
                    case 'recycle':
                        $stop = $bt->webstop();
                        if ($stop !== true) {
                            $errorNum[][$value->bt_name] = isset($stop['msg']) ? $stop['msg'] : '停止失败';
                            break;
                        }
                        $value->status = 'expired';
                        $value->deletetime = time();
                        $value->save();
                        break;
                    case 'delete':
                        $del = $bt->siteDelete($value->bt_id,$value->bt_name);
                        if(!$del){
                            $errorNum[][$value->bt_name] = isset($bt->_error) ? $bt->_error : '删除失败';
                            break;
                        }
                        $value->delete(true);
                        break;
                    default:
                        
                        break;
                }

                $successNum[][$value->bt_name] = 'success';
                
            }
            return $this->is_index ? [$successNum, $errorNum] : $this->success('请求成功', [$successNum, $errorNum]);
        } else {
            return $this->is_index ? '' : $this->success('无有效任务');
        }
    }

    /**
     * 回收站清理
     *
     * @return void
     */
    public function hostClear(){
        $successNum = [];
        $errorNum   = [];

        //达到指定天数后删除站点并清除所有数据
        $time      = time() - 60 * 60  * 24 * Config('site.recycle_delete');
        $hostList = model('Host')::onlyTrashed()->where('deletetime','<=',$time)->select();
        if($hostList){
            foreach ($hostList as $key => $value) {
                $bt = new Btaction();
                $bt->bt_id = $value->bt_id;
                $bt->bt_name = $value->bt_name;
                $del = $bt->siteDelete($value->bt_id,$value->bt_name);
                if(!$del){
                    $errorNum[][$value->bt_name] = isset($bt->_error) ? $bt->_error : '删除失败';
                    break;
                }
    
                $value->delete(true);
                $successNum[][$value->bt_name] = 'success';
            }
            return $this->is_index ? [$successNum, $errorNum] : $this->success('请求成功', [$successNum, $errorNum]);
        }else{
            return $this->is_index ? '' : $this->success('无有效任务');
        }
    }
}