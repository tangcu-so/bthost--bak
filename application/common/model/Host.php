<?php

namespace app\common\model;

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
        // 'check_time_text',
        // 'is_vsftpd_text',
        // 'endtime_text',
        // 'status_text'
    ];
    

    
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

    // 获取宝塔主机分类
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

    /**
     * 域名解析
     *
     * @param [type] $domain        根域名
     * @param [type] $value         记录值 如ip cname.xxx.com
     * @param [type] $sub_domain    解析值 如www @ xxxx
     * @return void
     */
    public function doamin_analysis($domain,$value,$sub_domain){
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
        $jx = $dnspod->record_Create($domain_find['dnspod_id'],'',$value,$sub_domain);
        if($jx&&isset($jx['record'])&&$jx['record']){
            $data = array_merge($jx['record'],['domain_id'=>$domain_find['dnspod_id'],'domain'=>$domain_find['domain']]);
            return $data;
        }else{
            return $dnspod->msg;
        }
    }

    // 转换IP地址
    public function getIpAddressAttr($value,$data){
        return $data['ip_address']?collection(model('Ipaddress')::all($data['ip_address']))->toArray():[];
    }

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

    // 转化状态码为中文（已使用语言配置文件实现）
    public static function status($status)
    {
        switch ($status) {
            case 'normal':
                $status_attr = '正常';
                break;
            case 'stop':
                $status_attr = '停用';
                break;
            case 'locked':
                $status_attr = '锁定';
                break;
            case 'expired':
                $status_attr = '到期';
                break;
            case 'excess':
                $status_attr = '超量';
                break;
            case 'error':
                $status_attr = '异常';
                break;
            default:
                $status_attr = '异常';
                break;
        }
        return $status_attr;
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
}