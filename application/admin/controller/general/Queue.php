<?php

namespace app\admin\controller\general;

use app\common\model\Queue as queModel;
use app\common\controller\Backend;
use app\common\library\Btaction;
use think\Config;

/**
 * 计划任务
 *
 * @icon fa fa-user
 */
class Queue extends Backend
{
    protected $model = null;
    protected $noNeedRight = '';
    protected $multiFields = 'status';

    public $queUrl = '';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new queModel();
        $this->view->assign('typedata', \app\common\model\Queue::getTypeList());
        $this->queUrl = request()->domain() . '/api/queue/index?token=' . Config::get('site.queue_key');
        $this->view->assign('queUrl', $this->queUrl);
    }

    // 获取宝塔面板任务执行日志
    public function getLogs()
    {
        $bt = new Btaction();

        $logsInfo = $bt->get_cron('btHost计划任务');
        if (!$logsInfo) {
            $this->error('任务未添加，请先添加任务', null);
        }
        if (!isset($logsInfo['id'])) {
            $this->error('任务获取失败', null);
        }
        $log_id = $logsInfo['id'];
        $logs = $bt->btAction->GetLogs($log_id);
        if (!$logs) {
            $this->error($bt->btAction->_error, null);
        }
        $this->view->assign('logs', $logs);
        return $this->view->fetch('queuelogs');
    }

    public function detail($limit = 10)
    {
        $row = model('queueLog')->order('id desc')->paginate($limit)->each(function ($item, $key) {
            $item['logs'] = json_decode($item['logs'], 1);
            return $item;
        });
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('count', model('queueLog')->count());
        $this->view->assign('row', $row);
        return $this->view->fetch('queuelog');
    }

    // 清空日志
    public function quelogclear()
    {
        $this->model->where('id', '>', 1)->delete(true);
        $this->success('已清空');
    }

    // 快速监控
    public function deployment()
    {
        $url = $this->queUrl;
        $bt = new Btaction();

        // 判断任务是否已存在
        $is_exist = $bt->exist_cron('btHost计划任务');

        if ($is_exist) {
            $this->success('已部署');
        }
        $set = $bt->btAction->AddCrontab([
            'name' => 'btHost计划任务',
            'sType' => 'toUrl',
            'type' => 'minute-n',
            'where1' => 1,
            'urladdress' => $url,
        ]);
        if (!$set) {
            $this->error($bt->btAction->_error);
        }
        $this->success('部署成功');
    }
}