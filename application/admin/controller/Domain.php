<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\library\Btaction;

/**
 * 域名管理
 *
 * @icon fa fa-circle-o
 */
class Domain extends Backend
{
    protected $relationSearch = true;

    // 通用搜索
    protected $searchFields = ['domain','vhost.bt_name'];
    
    /**
     * Domain模型对象
     * @var \app\admin\model\Domain
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\Domain;
        $this->view->assign("auditList", $this->model->getAuditList());
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->with('vhost,domainlist')
                ->where($where)
                ->order($sort, $order)
                ->count();
            $list = $this->model
                ->with('vhost,domainlist')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            
                
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    // 域名审核
    public function audit($ids){
        $info = $this->model::get($ids);
        if(!$info){
            $this->error('域名不存在');
        }
        if($info->audit==1){
            $this->error('域名已审核');
        }
        $hostInfo = model('Host')::get($info->vhost_id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        $bt = new Btaction();
        $bt->bt_id = $hostInfo->bt_id;
        if($info->dir=='/'){
            $set = $bt->addDomain($info->domain,$hostInfo->bt_name);
        }else{
            $set = $bt->addDomain($info->domain,$info->dir,1);
        }
        if(!$set){
            $this->error($bt->_error);
        }
        $info->audit = 1;
        $info->save();
        $this->success('已审核');
    }

    public function import()
    {
        parent::import();
    }

    /**
     * 默认生成的控制器所继承的父类中有index/add/edit/del/multi五个基础方法、destroy/restore/recyclebin三个回收站方法
     * 因此在当前控制器中可不用编写增删改查的代码,除非需要自己控制这部分逻辑
     * 需要将application/admin/library/traits/Backend.php中对应的方法复制到当前控制器,然后进行修改
     */
    

}