<?php

namespace app\common\model;

use app\common\library\Btaction;
use think\Model;
use traits\model\SoftDelete;

class Sql extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'sql';
    
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
            if (isset($changed['password']) && isset($row->origin['password']) && ($changed['password'] != $row->origin['password'])) {

                if ($changed['password']) {
                    $row->password = encode($changed['password']);
                } else {
                    unset($row->password);
                }

                if ($row->type == 'bt') {
                    $bt = new Btaction();
                    $bt->sql_name = $row->username;
                    $bt->resetSqlPass($row->username, $changed['password']);
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
            // 单独创建数据库
            if (isset($changed['type']) && $changed['type'] == 'bt') {
                $bt = new Btaction();
                $database = $changed['database'] ? $changed['database'] : $changed['username'];
                $bt->buildSql($changed['username'], $database, $changed['password']);
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