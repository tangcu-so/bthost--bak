<?php

namespace app\admin\model;

use app\common\library\Btaction;
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
        'status_text',
        'vhost',
    ];

    protected static function init()
    {
        self::beforeUpdate(function ($row) {
            $changed = $row->getChangedData();
            \app\common\model\Ftp::ftp_update_status($row);
            // 如果有修改密码
            if (isset($changed['password'])) {
                if ($changed['password']) {
                    if (\app\common\model\Ftp::ftp_update_pass($row) == false) {
                        return false;
                    }
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

        // TODO 删除前事件
        self::beforeDelete(function ($row) {
            \app\common\model\Ftp::ftp_del($row);
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


    public function vhost()
    {
        return $this->belongsTo('Host', 'vhost_id', 'id', [], 'LEFT');
    }

    public function getVhostAttr($value,$data){
        return $data['vhost_id']?$this->vhost():$data['vhost_id'];
    }

}