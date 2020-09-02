<?php

namespace app\admin\model;

use think\Model;
use traits\model\SoftDelete;

class Ipaddress extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'ipaddress';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性
    protected $append = [
        'status_text'
    ];
    

    
    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden'), 'locked' => __('Locked')];
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['status']) ? $data['status'] : '');
        $list = $this->getStatusList();
        return isset($list[$value]) ? $list[$value] : '';
    }


    public function ippools()
    {
        return $this->belongsTo('Ippools', 'ippools_id', 'id', [], 'LEFT');
    }

    /**
     * 随机抽选IP数
     *
     * @param [type] $ippools_id
     * @return void
     */
    public function getRandId($ippools_id,$num = 1){
        $list = $this->where(['ippools_id'=>$ippools_id,'status'=>'normal'])->column('id,ip');
        $list_rand = array_rand($list,$num);
        $ip_id_list = array_values($list_rand);
        return $list_rand;
    }

}