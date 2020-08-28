<?php

namespace app\api\controller;

use app\common\controller\Api;
use fast\Random;
use think\Validate;
use think\Config;
use think\Db;
use app\common\library\Btaction;

/**
 * 主机操作对外接口
 */
class Vhost extends Api
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function _initialize()
    {
        parent::_initialize();
        $this->access_token = Config::get('site.access_token');
        if(!$this->token_check()){
            // $this->error('签名错误');
        }
        $this->hostModel = model('host');
        $this->sqlModel = model('sql');
        $this->ftpModel = model('ftp');

    }

    public function index(){        
        $this->success('请求成功');
    }

    // 云服务器状态及监控
    public function server_status(){
        $bt = new Btaction();
        $info = $bt->btAction->GetNetWork();
        $this->success('请求成功',$info);
    }

    // 网站分类列表
    public function sort_list(){
        $bt = new Btaction();
        $sortList = $bt->getsitetype();
        if(!$sortList){
            $this->error('请求失败');
        }
        $this->success('请求成功',$sortList);
    }

    // IP池列表
    public function ippools_list(){
        $list = model('Ippools')::all();
        $this->success('请求成功',$list);
    }

    // IP地址列表
    public function ipaddress_list(){
        $ippools_id = $this->request->post('ippools_id/d');
        if(!$ippools_id){
            $this->error('请求错误');
        }
        $list = model('Ipaddress')::all(['ippools_id'=>$ippools_id]);
        $this->success('请求成功',$list);
    }

    // IP地址详情
    public function ipaddress_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $info = model('Ipaddress')::get($id);
        $this->success('请求成功',$info);
    }

    // 资源组列表
    public function plans_list(){
        $list = model('Plans')::all();
        foreach ($list as $key => $value) {
            $list[$key]['value'] = json_decode($value->value,1);
        }
        $this->success('请求成功',$list);
    }

    // 资源组详情
    public function plans_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }

        $info = model('Plans')::get($id);
        $info->value = json_decode($info->value,1);
        $this->success('请求成功',$info);
    }

    // 域名池列表
    public function domainpools_list(){
        $list = model('Domainpools')::all();
        $this->success('请求成功',$list);
    }

    // 域名列表
    public function domain_list(){
        $domainpools_id = $this->request->post('domainpools_id/d');
        if(!$domainpools_id){
            $this->error('请求错误');
        }
        $list = model('Domainlist')::all(['domainpools_id'=>$domainpools_id]);
        $this->success('请求成功',$list);
    }

    // 域名详情
    public function domain_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $info = model('Domainlist')::get($id);
        $this->success('请求成功',$info);
    }

    // 账号列表
    public function user_list(){
        $list = model('User')::all();
        if($list){
            foreach ($list as $key => $value) {
                $value->password = decode($value->password,$value->salt);
                $value->hidden(['salt','loginip','token']);
            }
        }
        $this->success('请求成功',$list);
    }

    // 账号信息
    public function user_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $info = model('User')::get($id);
        $info->password = decode($info->password,$info->salt);
        $info->hidden(['salt','loginip','token']);
        $this->success('请求成功',$info);
    }

    // 主机转移账户
    public function host_push(){
        $host_id = $this->request->post('host_id/d');
        $user_id = $this->request->post('user_id/d');
        if(!$host_id||!$user_id){
            $this->error('请求错误');
        }
        $hostInfo = $this->hostModel::get($host_id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        $hostInfo->user_id = $user_id;
        $hostInfo->save();
        $this->success('转移成功');
    }

    // 数据库详情
    public function sql_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->sqlModel::get($id);
        $this->success('请求成功',$info);
    }

    // FTP详情
    public function ftp_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->ftpModel::get($id);
        $this->success('请求成功',$info);
    }

    // 主机列表
    public function host_list(){
        $list = $this->hostModel::all();
        $this->success('请求成功',$list);
    }

    // 创建主机
    public function host_build(){
        $plans_id = $this->request->post('plans_id/d');
        // 格式 Y-m-d
        $endtime = $this->request->post('endtime');
        $user_id = $this->request->post('user_id',1);
        $sort_id = $this->request->post('sort_id',1);

        if(date('Y-m-d', strtotime($endtime)) !== $endtime){
            $this->error('时间格式错误，请严格按照Y-m-d格式传递');
        }

        $bt = new Btaction();
        if($plans_id){
            // 如果传递资源组ID，使用资源组配置构建数据
            // 查询该资源组ID是否正确
            $plansInfo = model('Plans')->getPlanInfo($plans_id);
            if(!$plansInfo){
                $this->error(model('Plans')->msg);
            }
              
            $hostSetInfo = $bt->setInfo($this->request->post(),$plansInfo);
            // 连接宝塔进行站点开通
            
        }else{
            // 使用用户传递参数进行构建数据
        }

        $btInfo = $bt->btBuild($hostSetInfo);
        if (!$btInfo) {
            $this->error($bt->_error);
        }

        $bt->bt_id = $btId = $btInfo['siteId'];
        $btName = $hostSetInfo['bt_name'];

        // 修改到期时间
        $timeSet = $bt->setEndtime($endtime);
        if (!$timeSet) {
            $this->error($bt->_error);
        }

        // 预装程序
        if($plansInfo['preset_procedure']){
            // 程序预装
            $defaultPhp = $hostSetInfo['version']&&$hostSetInfo['version']!='00'?$hostSetInfo['version']:'56';
            $setUp = $bt->presetProcedure($plansInfo['preset_procedure'], $btName, $defaultPhp);
            if(!$setUp){
                $this->error($bt->_error);
            }
        }

        // 默认并发、网速限制
        if (isset($plansInfo['perserver']) && $plansInfo['perserver'] != 0&&isset($bt->btTend->serverConfig['webserver'])&&$bt->btTend->serverConfig['webserver']=='nginx') {
            // 有错误，记录，防止开通被打断
            $modify_status = $bt->setLimit($plansInfo);
            if (!$modify_status) {
                $this->error($bt->_error);
            }
        }

        // dnspod智能解析
        if($plansInfo['dnspod']){
            // var_dump($plansInfo['ip']);exit;
            $sub_domain = $hostSetInfo['domain'];
            $domain_jx = $this->hostModel->doamin_analysis($plansInfo['domain'],$plansInfo['ip'],$sub_domain);
            if(!is_array($domain_jx)){
                $this->error('域名解析失败|' . json_encode([$plansInfo['domain'],$plansInfo['ip'],$sub_domain,$domain_jx],JSON_UNESCAPED_UNICODE));
            }
            $dnspod_record = $domain_jx['domain'];
            $dnspod_record_id = $domain_jx['id'];
            $dnspod_domain_id = $domain_jx['domain_id'];
        }else{
            $dnspod_record = $dnspod_record_id = $dnspod_domain_id = '';
        }

        Db::startTrans();

        // 获取信息后存入数据库
        $site_data = [
            'user_id'               => $user_id,
            'sort_id'               => $sort_id,
            'bt_id'                 => $btId,
            'bt_name'               => $btName,
            'site_max'              => $plansInfo['site_max'],
            'sql_max'               => $plansInfo['sql_max'],
            'flow_max'              => $plansInfo['flow_max'],
            'analysis_type'         => $plansInfo['analysis_type'],
            'default_analysis'      => $plansInfo['default_analysis'],
            'is_audit'              => $plansInfo['domain_audit'],
            'is_vsftpd'             => $plansInfo['vsftpd'],
            'domain_max'            => $plansInfo['domain_num'],
            'web_back_num'          => $plansInfo['web_back_num'],
            'sql_back_num'          => $plansInfo['sql_back_num'],
            'ip_address'            => $plansInfo['ip'],
            'endtime'               => $endtime,
        ];
        $inc = model('Host')::create($site_data);

        $vhost_id = $inc->id;
        if(!$vhost_id){
            $this->error('主机信息存储失败');
        }
        $site_data['id'] = $vhost_id;

        $sql_data = $ftp_data = [];
        if($btInfo['ftpStatus']==true){
            // 存储ftp
            $ftp_data = [
                'vhost_id'=>$vhost_id,
                'username'=>$btInfo['ftpUser'],
                'password'=>$btInfo['ftpPass'],
            ];
            $ftp = $this->ftpModel::create($ftp_data);
            $ftp_data['id'] = $ftp->id;
        }
        
        if($btInfo['databaseStatus']==true){
            // 存储sql
            $sql_data = [
                'vhost_id'=>$vhost_id,
                'username'=>$btInfo['databaseUser'],
                'password'=>$btInfo['databasePass'],
            ];
            $sql = $this->sqlModel::create($sql_data);
            $sql_data['id'] = $sql->id;
        }

        // IP池地址占用
        model('Ipaddress')->where(['ip'=>$plansInfo['ip'],'ippools_id'=>$plansInfo['ippools_id']])->update(['vhost_id'=>$vhost_id]);
        // 存入域名信息
        $domain_data = [
            'domain'=>$btName,
            'vhost_id'=>$vhost_id,
            'domainlist_id'=>$plansInfo['domainlist_id'],
            'dnspod_record'=>$dnspod_record,
            'dnspod_record_id'=>$dnspod_record_id,
            'dnspod_domain_id'=>$dnspod_domain_id,
            'dir'=>'/',
        ];
        model('domain')::create($domain_data);

        Db::commit();

        $this->success('创建成功',['site'=>$site_data,'domain'=>$domain_data,'sql'=>$sql_data,'ftp'=>$ftp_data]);
    }

    // 主机详情
    public function host_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->hostModel::get($id);
        $this->success('请求成功',$info);
    }

    // 主机回收站（软删除）
    public function host_recycle(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $hostFind = $this->hostModel::get($id);
        if(!$hostFind){
            $this->error('主机不存在');
        }
        // 连接宝塔停用站点
        $bt = new Btaction();
        $bt->bt_id = $hostFind->bt_id;
        $bt->bt_name = $hostFind->bt_name;
        $set = $bt->webstop();
        if(!$set){
            $this->error($bt->_error);
        }
        $this->hostModel::destroy($id);
        $this->success('已回收');
    }
    
    // 主机回收站恢复
    public function host_recovery(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $hostFind = $this->hostModel::withTrashed()->where(['id'=>$id])->find();
        if(!$hostFind){
            $this->error('主机不存在');
        }
        // 连接宝塔启用站点
        $bt = new Btaction();
        $bt->bt_id = $hostFind->bt_id;
        $bt->bt_name = $hostFind->bt_name;
        $set = $bt->webstart();
        if(!$set){
            $this->error($bt->_error);
        }
        $hostFind->deletetime = null;
        $hostFind->save();
        $this->success('已恢复');
    }

    // 修改密码
    public function host_pass(){
        $id = $this->request->post('id/d');
        $type = $this->request->post('type','all');
        $password = $this->request->post('password',Random::alnum(12));
        if(!$id){
            $this->error('错误的请求');
        }
        $bt = new Btaction();
        
        if($type=='sql'||$type=='all'){
            $sqlFind = $this->sqlModel::get(['vhost_id'=>$id]);
            $bt->sql_name = $sqlFind->username;
            if(!$sqlFind){
                $this->error('无数据库');
            }
            $set = $bt->resetSqlPass($sqlFind->username,$password);
            if(!$set){
                $this->error($bt->_error);
            }
            $sqlFind->password = $password;
            $sqlFind->save();
        }
        if($type=='ftp'||$type=='all'){
            $ftpFind = $this->ftpModel::get(['vhost_id'=>$id]);
            $bt->ftp_name = $ftpFind->username;
            if(!$ftpFind){
                $this->error('无FTP');
            }
            $set = $bt->resetFtpPass($ftpFind->username,$password);
            if(!$set){
                $this->error($bt->_error);
            }
            $ftpFind->password = $password;
            $ftpFind->save();
        }
        if($type=='host'||$type=='all'){
            $hostFind = $this->hostModel::get($id);
            if(!$hostFind){
                $this->error('无此主机');
            }
            $userInfo = model('User')::get($hostFind->user_id);
            if(!$userInfo){
                $this->error('无此用户');
            }
            $salt = Random::alnum();
            $userInfo->salt = $salt;
            $userInfo->password = encode($password,$salt);
            $userInfo->save();
        }
        
        $this->success('请求成功',['password'=>$password]);
    }

    // 主机停用
    public function host_stop(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
        $hostInfo = $bt->webstop();
        if(!$hostInfo){
            $this->error($bt->_error);
        }
        $hostFind->status = 'stop';
        $hostFind->save();
        $this->success('主机已停用');
    }

    // 主机锁定
    public function host_locked(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
        $hostInfo = $bt->webstop();
        if(!$hostInfo){
            $this->error($bt->_error);
        }
        $hostFind->status = 'locked';
        $hostFind->save();
        $this->success('主机已锁定');
    }

    // 主机启用
    public function host_start(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
        $hostInfo = $bt->webstart();
        if(!$hostInfo){
            $this->error($bt->_error);
        }
        $hostFind->status = 'normal';
        $hostFind->save();
        $this->success('主机已开启');
    }

    // 主机运行状态
    public function host_status(){
        // 获取本地及服务器中站点运行状态
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $hostInfo = $bt->getSiteInfo();
        if(!$hostInfo){
            $this->error($bt->_error);
        }

        // normal:正常,stop:停止,locked:锁定,expired:过期,excess:超量,error:异常
        $this->success('请求成功',['loca'=>$hostFind->status,'server'=>$hostInfo['status']]);
    }

    // 主机同步
    public function host_sync(){
        // 用于同步主机状态、到期时间、宝塔ID
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $hostInfo = $bt->getSiteInfo();
        if(!$hostInfo){
            $this->error($bt->_error);
        }
        $btid   = $hostInfo['id'];
        $edate  = $hostInfo['edate'];
        $status = $hostInfo['status'];
        
        if($btid!=$hostFind->bt_id){
            // 同步宝塔ID到本地
            $hostFind->bt_id = $btid;
        }
        
        // 同步状态到本地
        $hostFind->status = $status=='1'?'normal':'stop';

        $hostFind->save();

        // 同步本地到期时间到云端
        $localDate = date('Y-m-d', $hostFind->endtime);
        if($edate!=$localDate){
            $set = $bt->setEndtime($btid,$localDate);
            if(!$set){
                $this->error($bt->_error);
            }
        }
        
        $this->success('同步成功',['bt_id'=>$btid,'endtime'=>$localDate,'status'=>$hostInfo['status']]);
    }

    // 主机资源稽核（考虑要不要判断是否超量停用）
    public function host_resource(){
        // 用于返回和同步主机资源：数据库、流量、站点
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);

        $sqlFind = $this->sqlModel::get(['vhost_id'=>$id]);
        if($sqlFind&&$sqlFind->username){
            $sql_name = $sqlFind->username;
        }else{
            $sql_name = '';
        }
        
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $get = $bt->resource($sql_name,1);
        
        $size['site'] = bytes2mb($get['site']);
        $size['flow'] = bytes2mb($get['flow']);
        $size['sql'] = bytes2mb($get['sql']);
        
        $hostFind->site_size = $size['site'];
        $hostFind->flow_size = $size['flow'];
        $hostFind->sql_size = $size['sql'];
        $hostFind->save();

        $max = [
            'site'=>$hostFind->site_max,
            'flow'=>$hostFind->flow_max,
            'sql'=>$hostFind->sql_max,
        ];
        
        $this->success('请求成功',['size'=>$size,'max'=>$max]);
    }

    // 主机信息修改
    public function host_edit(){
        $id = $this->request->post('id/d');
        // 传递整数型，单位M
        $site_max = $this->request->post('site_max/d');
        $flow_max = $this->request->post('flow_max/d');
        $sql_max = $this->request->post('sql_max/d');
        $domain_max = $this->request->post('domain_max/d');
        $web_back_num = $this->request->post('web_back_num/d');
        $sql_back_num = $this->request->post('sql_back_num/d');
        if(!$id){
            $this->error('请求错误');
        }
        if(!$site_max&&!$flow_max&&!$sql_max&&!$domain_max&&!$web_back_num&&!$sql_back_num){
            $this->error('请求错误');
        }
        // 修改内容包含：空间大小、数据库大小、流量大小、域名绑定数、网站备份数、数据库备份数
        $hostInfo = $this->hostModel::get($id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        if($site_max){$hostInfo->site_max = $site_max;}
        if($sql_max){$hostInfo->sql_max = $sql_max;}
        if($flow_max){$hostInfo->flow_max = $flow_max;}
        if($domain_max){$hostInfo->domain_max = $domain_max;}
        if($web_back_num){$hostInfo->web_back_num = $web_back_num;}
        if($sql_back_num){$hostInfo->sql_back_num = $sql_back_num;}
        
        $hostInfo->save();
        $this->success('更新成功',$hostInfo);
    }

    // 主机域名绑定（暂定）
    public function host_domain(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
    }

    // 主机绑定IP
    public function host_bindip(){
        $id = $this->request->post('id/d');
        $ip_id = $this->request->post('ip_id/d');
        if(!$id||!$ip_id){
            $this->error('错误的请求');
        }
        $hostInfo = $this->hostModel::get($id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        $ipInfo = model('Ipaddress')::get($ip_id);
        if(!$ipInfo){
            $this->error('IP不存在');
        }
        // 判断是否已经绑定该IP
        $ip_list = explode(',',$hostInfo->getData('ip_address'));
        if(in_array($ip_id,$ip_list)){
            $this->error('已绑定');
        }
        $ip_list = array_filter($ip_list);
        array_push($ip_list,[$ip_id]);
        $ip_str = implode(',',$ip_list);
        $hostInfo->ip_address = $ip_str;
        $hostInfo->save();
        $this->success('绑定成功',$hostInfo->ip_address);
    }

    // 主机解绑IP
    public function host_unbindip(){
        $id = $this->request->post('id/d');
        $ip_id = $this->request->post('ip_id/d');
        $hostInfo = $this->hostModel::get($id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        $ipInfo = model('Ipaddress')::get($ip_id);
        if(!$ipInfo){
            $this->error('IP不存在');
        }
        $ip_list = explode(',',$hostInfo->getData('ip_address'));
        if(!in_array($ip_id,$ip_list)){
            $this->error('未绑定该IP');
        }
        // 清除空值
        $ip_list = array_filter($ip_list);
        $key = array_search($ip_id,$ip_list);
        array_splice($ip_list,$key);
        $ip_str = implode(',',$ip_list);
        $hostInfo->ip_address = $ip_str;
        $hostInfo->save();
        $this->success('已解除绑定',$hostInfo->ip_address);
    }

    // 到期时间修改
    public function host_endtime(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $endtime = $this->request->post('endtime');

        if(date('Y-m-d', strtotime($endtime)) !== $endtime){
            $this->error('时间格式错误，请严格按照Y-m-d格式传递');
        }
        $hostInfo = $this->hostModel::get($id);
        if(!$hostInfo){
            $this->error('主机不存在');
        }
        $bt = new Btaction();
        $bt->bt_id = $hostInfo->bt_id;
        $set = $bt->setEndtime($endtime);
        if(!$set){
            $this->error($this->_error);
        }
        $hostInfo->endtime = strtotime($endtime);
        $hostInfo->save();
        $this->success('更新成功',$endtime);
    }

    // 主机限速设置
    public function host_speed(){
        // 仅支持Nginx环境
        $id = $this->request->post('id/d');
        // 限制当前站点最大并发数
        $perserver = $this->request->post('perserver/d');
        // 限制每个请求的流量上限（单位：KB）
        $limit_rate = $this->request->post('limit_rate/d');
        if(!$id||!$perserver||!$limit_rate){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_id = $hostFind->bt_id;
        $data = ['perserver'=>$perserver,'limit_rate'=>$limit_rate];
        $set = $bt->setLimit($data);
        if(!$set){
            $this->error($bt->_error);
        }
        $this->success('设置成功',$data);
    }
    
    // 主机限速停止
    public function host_speedoff(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_id = $hostFind->bt_id;
        $set = $bt->closeLimit();
        if(!$set){
            $this->error($bt->_error);
        }
        $this->success('已关闭限速');
    }

    // 主机备注修改
    public function host_notice(){
        $id = $this->request->post('id/d');
        $notice = $this->request->post('text');
        if(!$id||!$notice){
            $this->error('错误的请求');
        }
        $hostFind = $this->hostModel::get($id);
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
        $set = $bt->setPs($notice);
        if(!$set){
            $this->error($bt->_error);
        }
        $hostFind->notice = $notice;
        $hostFind->save();
        $this->success('修改成功');
    }
    
    // 签名验证
    private function token_check(){
        // 时间戳
        $time = $this->request->get('time/d');
        if((time()-$time)>10){
            return false;
        }
        // 随机数
        $random = $this->request->get('random');
        // 签名
        $signature = $this->request->get('signature');

        $data = [
            'time' => $time,
            'random' => $time,
            'access_token' => $this->access_token,
        ];

        sort($data,SORT_STRING);
        $str = implode($data);
        $sig_key = md5($str);
        $sig_key = strtoupper($sig_key);
        
        if($sig_key===$signature){
            return true;
        }else{
            return false;
        }
    }
}