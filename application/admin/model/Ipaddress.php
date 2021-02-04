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
        'status_text',
        'ippools'
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
        return $this->belongsTo('Ippools', 'ippools_id', 'id', [], 'LEFT')->setEagerlyType(0);
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
        $new_data = [];
        if (!is_null($list_rand) && !is_array($list_rand)) {
            // 如果自有一个数要给他转成数组
            $new_data[] = $list_rand;
        } else {
            $new_data = $list_rand;
        }
        // var_dump($new_data, $list_rand, $list, $num);
        // exit;
        // $ip_id_list = array_values($list_rand);
        return $new_data;
    }

    public function getIppoolsAttr($value, $data){
        return $data['ippools_id']?model('Ippools')::get($data['ippools_id']):$data['ippools_id'];
    }

}