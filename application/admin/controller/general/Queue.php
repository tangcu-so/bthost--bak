<?php

namespace app\admin\controller\general;

use app\common\model\Queue as queModel;
use app\common\controller\Backend;
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

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new queModel();
        $this->view->assign('typedata', \app\common\model\Queue::getTypeList());
        $this->view->assign('queUrl', request()->domain() . '/api/queue/index?token=' . Config::get('site.queue_key'));
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
}