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
            // TODO 上线需要验证签名
            $this->error('签名错误');
        }
        $this->hostModel = model('host');
        $this->sqlModel = model('sql');
        $this->ftpModel = model('ftp');

        // IP白名单效验
        $ip = request()->ip();
        $forbiddenip = Config::get('site.api_returnip');
        if ($forbiddenip != '') {
            $black_arr = explode("\r\n", $forbiddenip);
            if (in_array($ip, $black_arr)) {
                $this->error('非白名单IP不允许请求');
            }
        }
    }

    public function index(){        
        $this->success('请求成功');
    }

    // 一键部署程序列表
    public function deployment_list()
    {
        $bt = new Btaction();
        $list = $bt->getdeploymentlist();
        $this->success('请求成功', $list);
    }

    // php列表
    public function php_list()
    {
        $bt = new Btaction();
        $list = $bt->getphplist();
        $this->success('请求成功', $list);
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
        $list = model('Domain')::all(['domainpools_id'=>$domainpools_id]);
        $this->success('请求成功',$list);
    }

    // 域名详情
    public function domain_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $info = model('Domain')::get($id);
        $this->success('请求成功',$info);
    }

    // 用户列表
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
        $hostInfo = $this->getHostInfo($host_id);
        $hostInfo->user_id = $user_id;
        $hostInfo->save();
        $this->success('转移成功');
    }

    // 数据库详情
    public function sql_info(){
        $id = $this->request->post('sql_id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->sqlModel::get($id);
        $this->success('请求成功',$info);
    }

    // 创建数据库
    // TODO 多数据库没出来之前临时停用
    public function sql_build(){
        $this->error('接口停用');
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        
        $username = $this->request->post('username',Random::alnum(8));
        $database = $this->request->post('database');
        $password = $this->request->post('password',Random::alnum(8));
        $console = $this->request->post('console');
        $type = $this->request->post('type','bt');

        if (!preg_match("/^[A-Za-z0-9]+$/", $username)) {
            $this->error('账号格式不正确');
        }

        $info = $this->getHostInfo($id);
        
        $sqlData = [
            'vhost_id'  => $info->id,
            'username'  => $username,
            'database'  => $database,
            'password'  => $password,
            'console'   => $console,
            'type'   => $type=='bt'?'bt':'custom',
        ];
        $create = $this->sqlModel::create($sqlData);
        $sqlData['id'] = $create->id;
        $this->success('创建成功',$sqlData);
    }

    // 数据库密码修改
    public function sql_pass(){
        $id = $this->request->post('sql_id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $password = $this->request->post('password',Random::alnum(8));

        $info = $this->sqlModel::get($id);
        $info->password = $password;
        $info->save();
        $this->success('修改成功',$info);
    }

    // FTP详情
    public function ftp_info(){
        $id = $this->request->post('ftp_id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->ftpModel::get($id);
        $this->success('请求成功',$info);
    }

    // FTP密码修改
    public function ftp_pass(){
        $id = $this->request->post('ftp_id/d');
        $password = $this->request->post('password',Random::alnum(8));
        if(!$id){
            $this->error('请求错误');
        }
        $info = $this->ftpModel::get($id);
        if(!$info){
            $this->error('ftp不存在');
        }
        $info->password = $password;
        $info->save();
        $this->success('修改成功',$info);
    }

    // FTP启用
    public function ftp_start(){

    }

    // FTP停用
    public function ftp_stop(){

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
        // 自定义站点名前缀
        $username = $this->request->post('username');

        if (date('Y-m-d', strtotime($endtime)) !== $endtime) {
            $this->error('时间格式错误，请严格按照Y-m-d格式传递');
        }

        $bt = new Btaction();
        if (!$bt->test()) {
            $this->error($bt->_error);
        }
        if($plans_id){
            // 如果传递资源组ID，使用资源组配置构建数据
            // 查询该资源组ID是否正确
            $plansInfo = model('Plans')->getPlanInfo($plans_id);
            if(!$plansInfo){
                $this->error(model('Plans')->msg);
            }
        }else{
            // 使用用户传递参数进行构建数据
            $pack_arr = $this->request->post('pack/a');
            $plansInfo = model('Plans')->getPlanInfo('', $pack_arr);
            if (!$plansInfo) {
                $this->error(model('Plans')->msg);
            }
        }
        // 构建站点信息
        $hostSetInfo = $bt->setInfo($this->request->post(), $plansInfo);
        // 连接宝塔进行站点开通
        $btInfo = $bt->btBuild($hostSetInfo);
        if (!$btInfo) {
            $this->error($bt->_error);
        }

        $bt->bt_id = $btId = $btInfo['siteId'];
        $btName = $hostSetInfo['bt_name'];

        Db::startTrans();

        // vsftpd创建

        // 修改到期时间
        $timeSet = $bt->btAction->WebSetEdate($btId, $endtime);
        if (!$timeSet['status']) {
            $this->error('开通时间设置失败|' . json_encode($endtime));
        }

        // 预装程序
        if ($plansInfo['preset_procedure']) {
            // 程序预装
            $defaultPhp = $hostSetInfo['version'] && $hostSetInfo['version'] != '00' ? $hostSetInfo['version'] : '56';
            $setUp = $bt->presetProcedure($plansInfo['preset_procedure'], $btName, $defaultPhp);
            if (!$setUp) {
                $this->error($bt->_error);
            }
        }
        if ($plansInfo['session']) {
            // session隔离
            $bt->btAction->set_php_session_path($btId, 1);
        }

        // 并发、限速设置
        // 默认并发、网速限制
        if (isset($plansInfo['perserver']) && $plansInfo['perserver'] != 0 && isset($bt->serverConfig['webserver']) && $bt->serverConfig['webserver'] == 'nginx') {
            // 有错误，记录，防止开通被打断
            $modify_status = $bt->setLimit($plansInfo);
            if (!$modify_status) {
                $this->error($bt->_error);
            }
        }

        $dnspod_record = $dnspod_record_id = $dnspod_domain_id = '';

        if ($plansInfo['dnspod']) {
            // 如果域名属于dnspod智能解析
            $record_type = Config::get('site.dnspod_analysis_type');
            $analysis = Config::get('site.dnspod_analysis_url');

            $sub_domain = $hostSetInfo['domain'];
            $domain_jx = $this->model->doamin_analysis($plansInfo['domain'], $analysis, $sub_domain, $record_type);
            if (!is_array($domain_jx)) {
                $this->error('域名解析失败|' . json_encode([$plansInfo['domain'], $analysis, $sub_domain, $domain_jx], JSON_UNESCAPED_UNICODE));
            }
            $dnspod_record = $sub_domain;
            $dnspod_record_id = $domain_jx['id'];
            $dnspod_domain_id = $domain_jx['domain_id'];
        }

        // 获取信息后存入数据库
        $host_data = [
            'user_id'               => $user_id,
            'sort_id'               => $sort_id,
            'bt_id'                 => $btId,
            'bt_name'               => $btName,
            'site_max'              => $plansInfo['site_max'],
            'sql_max'               => $plansInfo['sql_max'],
            'flow_max'              => $plansInfo['flow_max'],
            'is_audit'              => $plansInfo['domain_audit'],
            'is_vsftpd'             => $plansInfo['vsftpd'],
            'domain_max'            => $plansInfo['domain_num'],
            'web_back_num'          => $plansInfo['web_back_num'],
            'sql_back_num'          => $plansInfo['sql_back_num'],
            'ip_address'            => isset($plansInfo['ipArr']) ? $plansInfo['ipArr'] : '',
            'endtime'               => $endtime,
        ];
        $hostInfo = model('Host')::create($host_data);

        $vhost_id = $hostInfo->id;
        if (!$vhost_id) {
            $this->error('主机信息存储失败');
        }

        if ($btInfo['ftpStatus'] == true) {
            // 存储ftp
            $ftpInfo = model('Ftp')::create([
                'vhost_id' => $vhost_id,
                'username' => $btInfo['ftpUser'],
                'password' => $btInfo['ftpPass'],
            ]);
        }

        if ($btInfo['databaseStatus'] == true) {
            // 存储sql
            $sqlInfo = model('Sql')::create([
                'vhost_id' => $vhost_id,
                'database' => $btInfo['databaseUser'],
                'username' => $btInfo['databaseUser'],
                'password' => $btInfo['databasePass'],
            ]);
        }

        // 存入域名信息
        $domainInfo = model('Domainlist')::create([
            'domain' => $btName,
            'vhost_id' => $vhost_id,
            'domain_id' => $plansInfo['domainlist_id'],
            'dnspod_record' => $dnspod_record,
            'dnspod_record_id' => $dnspod_record_id,
            'dnspod_domain_id' => $dnspod_domain_id,
            'dir' => '/',
        ]);

        Db::commit();

        $this->success('创建成功', ['site' => $hostInfo, 'domain' => $domainInfo, 'sql' => $sqlInfo, 'ftp' => $ftpInfo]);
    }

    // 主机详情
    public function host_info(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $info = $this->getHostInfo($id);
        $info->sql = $this->sqlModel::all(['vhost_id'=>$id,'status'=>'normal']);
        $info->ftp = $this->ftpModel::get(['vhost_id'=>$id,'status'=>'normal']);
        $info->domain = model('Domainlist')::all(['vhost_id' => $id]);
        $this->success('请求成功',$info);
    }

    // 主机回收站（软删除）
    public function host_recycle(){
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('请求错误');
        }
        $hostFind = $this->getHostInfo($id);
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
            $hostFind = $this->getHostInfo($id);
            $userInfo = model('User')::get($hostFind->user_id);
            if(!$userInfo){
                $this->error('无此用户');
            }
            $userInfo->password = $password;
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
        $hostFind = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
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
        $hostFind = $this->getHostInfo($id);
        
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $hostInfo = $bt->getSiteInfo();
        if(!$hostInfo){
            $this->error($bt->_error);
        }
        $btid   = $hostInfo['id'];
        $edate  = $hostInfo['edate'];
        $status = $hostInfo['status'];

        $bt->bt_id = $btid;
        if ($btid != $hostFind->bt_id) {
            // 同步宝塔ID到本地
            $hostFind->bt_id = $btid;
        }

        // 同步状态到本地
        if ($hostFind->status == 'normal' && $status != 1) {
            $bt->webstart();
            $status = 1;
        } else {
            $bt->webstop();
            $status = 0;
        }

        $hostFind->save();

        // 同步本地到期时间到云端
        $localDate = date('Y-m-d', $hostFind->endtime);
        if($edate!=$localDate){
            $set = $bt->setEndtime($btid,$localDate);
            if(!$set){
                $this->error($bt->_error);
            }
        }

        $this->success('同步成功', ['bt_id' => $btid, 'endtime' => $localDate, 'status' => $status, 'hostStatus' => $hostFind->status]);
    }

    // 主机资源稽核，超停
    public function host_resource(){
        // 用于返回和同步主机资源：数据库、流量、站点
        $id = $this->request->post('id/d');
        if(!$id){
            $this->error('错误的请求');
        }
        $hostFind = $this->getHostInfo($id);

        $sqlFind = $this->sqlModel::get(['vhost_id'=>$id]);
        if($sqlFind&&$sqlFind->username){
            $sql_name = $sqlFind->username;
        }else{
            $sql_name = '';
        }
        
        $bt = new Btaction();
        $bt->bt_name = $hostFind->bt_name;
        $bt->bt_id = $hostFind->bt_id;
        $bt->sql_name = $sql_name;
        $size = $bt->getResourceSize();

        $hostFind->site_size = $size['websize'];
        $hostFind->flow_size = $size['total_size'];
        $hostFind->sql_size = $size['sqlsize'];


        $overflow = 0;
        if ($hostFind->sql_max != 0 && $hostFind->sql_size > $hostFind->sql_max) {
            $overflow = 1;
        }
        if ($hostFind->site_max != 0 && $hostFind->site_size > $hostFind->site_max
        ) {
            $overflow = 1;
        }
        if ($hostFind->flow_max != 0 && $hostFind->flow_size > $hostFind->flow_max
        ) {
            $overflow = 1;
        }

        if ($overflow) {
            $hostFind->status = 'excess';
        } else {
            // 判断既没有过期，也处于没有超量状态，就恢复主机
            if ($hostFind->endtime > time() && $hostFind->status == 'excess'
            ) {
                $hostFind->status = 'normal';
            }
        }
        $hostFind->check_time = time();

        $hostFind->allowField(true)->save();

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
        $sort_id = $this->request->post('sort_id/d');
        $is_audit = $this->request->post('is_audit/d');
        $endtime = $this->request->post('endtime');
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
        $hostInfo = $this->getHostInfo($id);
        if($site_max){$hostInfo->site_max = $site_max;}
        if($sql_max){$hostInfo->sql_max = $sql_max;}
        if($flow_max){$hostInfo->flow_max = $flow_max;}
        if($domain_max){$hostInfo->domain_max = $domain_max;}
        if($web_back_num){$hostInfo->web_back_num = $web_back_num;}
        if ($sql_back_num) {
            $hostInfo->sql_back_num = $sql_back_num;
        }
        if ($sort_id) {
            $hostInfo->sort_id = $sort_id;
        }
        if ($is_audit) {
            $hostInfo->is_audit = $is_audit;
        }
        if ($endtime) {
            if (date('Y-m-d', strtotime($endtime)) !== $endtime) {
                $this->error('时间格式错误，请严格按照Y-m-d格式传递');
            }
            $hostInfo->endtime = $endtime;
        }
        
        $hostInfo->save();
        $this->success('更新成功',$hostInfo);
    }

    // TODO 主机域名绑定（暂定）
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
        $hostInfo = $this->getHostInfo($id);
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
        $hostInfo = $this->getHostInfo($id);
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

        if (date('Y-m-d', strtotime($endtime)) !== $endtime) {
            $this->error('时间格式错误，请严格按照Y-m-d格式传递');
        }
        $hostInfo = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
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
        $hostFind = $this->getHostInfo($id);
        // 不修改宝塔备注
        // $bt = new Btaction();
        // $bt->bt_name = $hostFind->bt_name;
        // $bt->bt_id = $hostFind->bt_id;
        // $set = $bt->setPs($notice);
        // if(!$set){
        //     $this->error($bt->_error);
        // }
        $hostFind->notice = $notice;
        $hostFind->save();
        $this->success('修改成功');
    }

    /**
     * 获取主机信息
     *
     * @param [type] $id
     * @return obj
     */
    private function getHostInfo($id)
    {
        $hostFind = $this->hostModel::get($id);
        if (!$hostFind) {
            $this->error('主机不存在');
        }
        return $hostFind;
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
            'random' => $random,
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