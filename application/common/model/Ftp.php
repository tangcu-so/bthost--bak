<?php

namespace app\common\model;

use think\Model;
use traits\model\SoftDelete;

class Ftp extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'ftp';
    
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
    
    protected static function init()
    {
        self::beforeUpdate(function ($row) {
            $changed = $row->getChangedData();
            // 如果有修改密码
            if (isset($changed['password'])) {
                if ($changed['password']) {
                    $row->password = encode($changed['password']);
                } else {
                    unset($row->password);
                }
            }
        });

        self::beforeInsert(function ($row) {
            $changed = $row->getChangedData();
            // 新建账号时加密密码
            if (isset($changed['password'])) {
                if ($changed['password']) {
                    $row->password = encode($changed['password']);
                } else {
                    unset($row->password);
                }
            }
        });
    }

    
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

    public function getPasswordAttr($value,$data){
        return $data['password']?decode($data['password']):$data['password'];
    }


}