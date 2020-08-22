<?php

namespace app\admin\controller;

use app\common\controller\Backend;

/**
 * IP地址
 *
 * @icon fa fa-circle-o
 */
class Ipaddress extends Backend
{
    
    /**
     * Ipaddress模型对象
     * @var \app\admin\model\Ipaddress
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\Ipaddress;
        $this->view->assign("statusList", $this->model->getStatusList());
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
    
    
    public function add(){
        if($this->request->isPost()){
            $params = $this->request->post('row/a');
            if(!isset($params['ip'])||empty($params['ip'])){
                $this->error('IP地址不能为空');
            }
            $ippools_id = $params['ippools_id'];
            $mask = $params['mask'];
            $gateway = $params['gateway'];
            $data_arr = [];
            $arr = explode('-',$params['ip']);
            if(isset($arr[1])&&$arr[1]){
                $range_one =$arr[0];
                $range_two =$arr[1];
                if(filter_var($range_one, FILTER_VALIDATE_IP)&&filter_var($range_two, FILTER_VALIDATE_IP)) {
                
                }
                else {
                $this->error('IP地址不合法');
                }
                $address_list = ip_range($range_one,$range_two);
                for ($i=0; $i < count($address_list); $i++) { 
                    $data_arr[$i]['ip'] = $address_list[$i];
                    $data_arr[$i]['ippools_id'] = $ippools_id;
                    $data_arr[$i]['mask'] = $mask;
                    $data_arr[$i]['gateway'] = $gateway;
                    $data_arr[$i]['status'] = $params['status'];
                }
            }else{
                $data_arr[] = ['ip'=>$arr[0],'ippools_id'=>$ippools_id,'mask'=>$mask,'gateway'=>$gateway,'status'=>$params['status']];
            }
            $this->model->saveAll($data_arr);
            $this->success('添加成功');
        }
        return parent::add();
    }
}