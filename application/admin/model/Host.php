<?php

namespace app\admin\model;

use think\Model;
use traits\model\SoftDelete;
use app\common\library\Btaction;
use think\Config;
use dnspod\Dnspod;

class Host extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'host';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性
    protected $append = [
        'check_time_text',
        'is_vsftpd_text',
        'endtime_text',
        'status_text',
        'user',
        'size_percentage',
    ];

    protected static function init()
    {
        self::beforeUpdate(function ($row) {
            $changed = $row->getChangedData();
            // 如果有状态发生改变
            if (isset($changed['status']) && ($changed['status'] != $row->origin['status'])) {
                $bt = new Btaction();
                $bt->bt_id = $row->bt_id;
                $bt->bt_name = $row->bt_name;
                if ($changed['status'] == 'normal') {
                    $bt->webstart();
                }
                if ($changed['status'] != 'normal') {
                    $bt->webstop();
                }
            }
            // 如果分类ID发生改变
            if (isset($changed['sort_id']) && ($changed['sort_id'] != $row->origin['sort_id'])) {
                $bt = new Btaction();
                // 构建数据
                $data = json_encode([$row->bt_id]);
                $bt->btAction->set_site_type($data, $changed['sort_id']);
            }
            // 如果到期时间发生改变
            if (isset($changed['endtime']) && ($changed['endtime'] != $row->origin['endtime'])) {
                $bt = new Btaction();
                $bt->bt_id = $row->bt_id;
                $bt->bt_name = $row->bt_name;
                // 构建数据
                $expTime = date('Y-m-d', $changed['endtime']);
                $bt->setEndtime($expTime);
            }
        });

        self::beforeInsert(function ($row) {
            $changed = $row->getChangedData();
            // TODO 将主机创建事件放在这里
        });
    }
    

    
    public function getIsVsftpdList()
    {
        return ['0' => __('Is_vsftpd 0'), '1' => __('Is_vsftpd 1')];
    }

    public function getStatusList()
    {
        return ['normal' => __('Status normal'), 'stop' => __('Status stop'), 'locked' => __('Status locked'), 'expired' => __('Status expired'), 'excess' => __('Status excess'), 'error' => __('Status error')];
    }


    public function getCheckTimeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['check_time']) ? $data['check_time'] : '');
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }


    public function getIsVsftpdTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['is_vsftpd']) ? $data['is_vsftpd'] : '');
        $list = $this->getIsVsftpdList();
        return isset($list[$value]) ? $list[$value] : '';
    }


    public function getEndtimeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['endtime']) ? $data['endtime'] : '');
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['status']) ? $data['status'] : '');
        $list = $this->getStatusList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    protected function setCheckTimeAttr($value)
    {
        return $value === '' ? null : ($value && !is_numeric($value) ? strtotime($value) : $value);
    }

    protected function setEndtimeAttr($value)
    {
        return $value === '' ? null : ($value && !is_numeric($value) ? strtotime($value) : $value);
    }
    
    protected static function passencode($password){
        return $password?getPasswordHash($password):$password;
    }

    public function sort($value){
        if($value){
            $bt = new Btaction();
            $sortList = $bt->getsitetype();
            if($sortList){
                $newArr = array_column($sortList,'name');
                return isset($newArr[$value])?$newArr[$value]:$value;
            }else{
                return $value;
            }
            
        }else{
            return $value;
        }
    }

    public function user()
    {
        return $this->belongsTo('User', 'user_id', 'id', [], 'LEFT');
    }

    public function getUserAttr($value,$data){
        return $data['user_id']?$this->user():$data['user_id'];
    }

    /**
     * 域名解析
     *
     * @param [type] $domain        根域名
     * @param [type] $value         记录值 如ip cname.xxx.com
     * @param [type] $sub_domain    解析值 如www @ xxxx
     * @param [type] $record_type   解析方式 'SRV', 'MX', 'CNAME', 'AAAA', 'A', 'TXT', 'NS'
     * @return void
     */
    public function doamin_analysis($domain,$value,$sub_domain,$record_type){
        $domain_find = model('Domain')->where(['domain'=>$domain,'status'=>'normal'])->find();
        if(!$domain_find){
            return '域名不存在';
        }
        $id = Config::get('dnspod.id');
        $token = Config::get('dnspod.token');
        if(!$id||!$token){
            return '配置不完整';
        }
        $dnspod = new Dnspod($id,decode($token));
        $jx = $dnspod->record_Create($domain_find['dnspod_id'],'',$value,$sub_domain,$record_type);
        if($jx&&isset($jx['record'])&&$jx['record']){
            $data = array_merge($jx['record'],['domain_id'=>$domain_find['dnspod_id'],'domain'=>$domain_find['domain']]);
            return $data;
        }else{
            return $dnspod->msg;
        }
    }

    // public function getIpAddressAttr($value,$data){
    //     return $value?model('Ipaddress')::all($value):$value;
    // }

    // 获取sql信息
    public function getSqlInfo($value, $data)
    {
        return Sql::get(['vhost_id' => $data['id']]);
    }

    // 获取ftp信息
    public function getFtpInfo($value, $data)
    {
        return Ftp::get(['vhost_id' => $data['id']]);
    }

    /**
     * 主机删除
     *
     * 删除FTP、SQL记录
     * @param [type] $id        主机ID
     * @param [type] $dels      强制删除
     * @return void
     */
    public function destroy_delete($id, $dels = 0)
    {
        $info = $this::onlyTrashed()->where(['id'=>$id])->find();
        if (!$info && $dels != 1) {
            return false;
        }
        if($info->bt_id&&$info->bt_name){
            $bt = new Btaction();
            $del = $bt->siteDelete($info->bt_id,$info->bt_name);
            if (!$del && $dels != 1) {
                return false;
            }
        }
        if($info->is_vsftpd){
            // TODO 如果有开通vsftpd，也删除
            // 暂时没有api，后续更新
        }
        $info->delete(true);
        // 删除数据库
        model('Sql')->where('vhost_id',$info->id)->delete(true);
        // 删除FTP
        model('Ftp')->where('vhost_id',$info->id)->delete(true);
        
        return true;
    }

    // 转换主机状态为数字状态
    public static function getNumber($status)
    {
        switch ($status) {
            case 'normal':
                $vhostStatus = 1;
                break;
            case 'stop':
                $vhostStatus = 0;
                break;
            case 'locked':
                $vhostStatus = 2;
                break;
            case 'expired':
                $vhostStatus = 3;
                break;
            case 'excess':
                $vhostStatus = 4;
                break;
            case 'error':
                $vhostStatus = 5;
                break;
            default:
                $vhostStatus = 0;
                break;
        }
        return $vhostStatus;
    }

    // 添加主机时资源组类型0=选择资源组,1=自定义资源配置
    public static function plans_type()
    {
        return [
            '0' => '否',
            '1' => '是',
        ];
    }

    public function getSizePercentageAttr($value, $data)
    {
        $site_getround = $data['site_max'] != 0 ? getround($data['site_max'], $data['site_size']) : 0;
        $sql_getround = $data['sql_max'] != 0 ? getround($data['sql_max'], $data['sql_size']) : 0;
        $flow_getround = $data['flow_max'] != 0 ? getround($data['flow_max'], $data['flow_size']) : 0;
        $data = [
            'site' => $site_getround,
            'sql' => $sql_getround,
            'flow' => $flow_getround,
        ];
        return $data;
    }
}