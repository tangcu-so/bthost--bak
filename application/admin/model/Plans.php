<?php

namespace app\admin\model;

use think\Model;
use traits\model\SoftDelete;

class Plans extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'plans';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    public $msg = '';

    // 追加属性
    protected $append = [
        'status_text'
    ];
    

    
    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['status']) ? $data['status'] : '');
        $list = $this->getStatusList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    public function getPlanInfo($id){
        $plansInfo = self::get(['status'=>'normal','id'=>$id]);
        if(!$plansInfo){
            return false;
        }
        $plansArr = json_decode($plansInfo->value,1);
        // var_dump($plansArr);exit;
        // 域名池，随机抽选一个域名
        if($plansArr['domainpools_id']){
            $domainArr = model('Domainlist')->where(['domainpools_id'=>$plansArr['domainpools_id'],'status'=>'normal'])->column('id,domain,dnspod');
        }else{
            $domainArr = false;
        }
        if(!$domainArr){
            $this->msg = '域名池中无可用域名';
            return false;
        }
        $domain_key = array_rand($domainArr);
        $domain = $domainArr[$domain_key]['domain'];
        // var_dump($domainArr,$domain_key,$domain);exit;

        // IP池，随机抽选一个IP
        if($plansArr['ippools_id']){
            $ipArr = model('Ipaddress')->where(['ippools_id'=>$plansArr['ippools_id'],'status'=>'normal'])->column('ip');
        }else{
            $ipArr = false;
        }
        if(!$ipArr){
            $this->msg = 'IP池中无可用IP';
            return false;
        }
        $ip_key = array_rand($ipArr);
        $ip = $ipArr[$ip_key];

        $plansArr['domain'] = $domain;
        $plansArr['ip'] = $ip;
        $plansArr['domainlist_id'] = $domain_key;
        $plansArr['dnspod'] = $domainArr[$domain_key]['dnspod'];
        return $plansArr;
    }


}