<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use app\common\library\Btaction;
use think\Config;
use think\Cookie;
use think\Hook;
use think\Session;
use think\Validate;
use btpanel\Btpanel;
use think\Debug;
use think\Db;
use think\Cache;

/**
 * 控制中心
 */
class Vhost extends Frontend
{
    protected $layout = 'default';
    protected $noNeedLogin = ['login', 'register','clear_cache'];
    // protected $noNeedRight = ['*']; //测试阶段无需鉴权
    protected $noNeedRight = ['logout'];

    /**
     * 宝塔站点ID
     *
     * @var string
     */
    private $bt_id          = '';
    private $btTend        = null;

    private $reg         = "/(;|{|}|\|)/";
    private $reg_rewrite = "/(root|alias|_by_lua|http_upgrade)/";
    private $reg_file    = "/(web_config|web.config|.user.ini)/";

    private $check_time  = 3600;

    private $hostInfo = null;

    private $_error = '';

    private $vhost_id = null;

    private $server_type = 'linux';

    private $webRootPath = null;

    // 资源超出停用面板
    public $is_excess_stop = 0;

    public function _initialize()
    {
        Debug::remark('begin');
        parent::_initialize();
        $this->hostModel = model('Host');
        $this->ftpModel = model('Ftp');
        $this->sqlModel = model('Sql');
        $host_id = Cookie::get('host_id_' . $this->auth->id);
        if (!$host_id) {
            return $this->redirect('/sites');
        }
        $hostInfo = $this->hostModel::get(['user_id'=>$this->auth->id,'id'=>$host_id]);
        if(!$hostInfo){
            $this->error('站点不存在<a href="' . url('index/user/index') . '">切换站点</a>', '');
        }
        $ftpInfo = $this->ftpModel::get(['vhost_id'=>$hostInfo->id,'status'=>'normal']);
        $sqlInfo = $this->sqlModel::get(['vhost_id'=>$hostInfo->id,'status'=>'normal']);
        $hostInfo->ftp = $ftpInfo?$ftpInfo:'';
        $hostInfo->sql = $sqlInfo?$sqlInfo:'';
        // $hostInfo->vhost_id = $hostInfo->id;
        
        // 用户信息表
        $this->userInfo = $this->auth->getUserinfo();
        // 主机信息表
        $this->hostInfo = $hostInfo;
        // 验证主机是否过期
        if (time() > $this->hostInfo['endtime']) {
            $this->error('已过期','/sites');
        }

        $this->is_excess_stop = Config('site.excess_panel');
        
        // 状态甄别
        switch ($this->hostInfo['status']) {
            case 'normal':
                break;
            case 'stop':
                break;
            case 'locked':
                $this->error('主机已锁定','/sites');
                break;
            case 'expired':
                $this->error('主机已到期','/sites');
                break;
            case 'excess':
                $this->is_excess_stop?$this->error('主机超量，已被停用',''):'';
                break;
            case 'error':
                $this->error('主机异常','/sites');
                break;
            default:
                $this->error('主机异常','/sites');
                break;
        }

        // 获取系统配置
        $this->Config = Config::get('site');

        $this->btTend   = new Btaction();
        $this->btAction = $this->btTend->btAction;
        $this->hostInfo->server_os = $this->server_type = $this->btTend->os;
        
        // 信息初始化
        $this->btTend->ftp_name = isset($hostInfo->ftp->username)?$hostInfo->ftp->username:'';
        $this->btTend->sql_name = isset($hostInfo->sql->username)?$hostInfo->sql->username:'';
        // 站点名
        $this->btTend->bt_name = $this->siteName = $this->hostInfo['bt_name'];
        // 宝塔站点id
        $this->btTend->bt_id = $this->bt_id = $this->hostInfo['bt_id'];

        // 主机ID
        $this->vhost_id = $this->hostInfo->id;

        // 站点初始化
        $webInit = $this->btTend->webInit();
        if(!$webInit){
            $this->error($this->btTend->_error);
        }
        // 检查资源使用量
        if(!$this->check()){
            $this->is_excess_stop?$this->error($this->_error,''):'';
        }

        $this->webRootPath = $this->btTend->webRootPath;
        $this->dirUserIni = $this->btTend->dirUserIni;

        // 获取等待连接耗时
        $connectTime = $this->btTend->getRequestTime();
        if (!$connectTime) {
            $this->error('连接服务器API失败，请检查API配置或防火墙是否正常','/sites');
        }
        $this->assign('timeOut', $connectTime);
        // php加载时长
        $this->assign('rangeTime', Debug::getRangeTime('begin','end',6).'s');
        
        $this->assign('hostInfo', $this->hostInfo);
        $this->assign('userInfo', $this->userInfo);
        // TODO webserver环境获取失败
        $this->assign('serverConfig', $this->btTend->serverConfig);

        $this->assign('phpmyadmin',Config('site.phpmyadmin'));
    }

    /**
     * 空的请求
     * @param $name
     * @return mixed
     */
    // public function _empty($name)
    // {
    //     $data = Hook::listen("user_request_empty", $name);
    //     foreach ($data as $index => $datum) {
    //         $this->view->assign($datum);
    //     }
    //     return $this->view->fetch('user/' . $name);
    // }
    
    /**
     * 首页
     */
    public function index()
    {
        $this->view->assign('title', __('Console center'));
        return $this->view->fetch();
    }

    // 控制台
    public function main()
    {
        $phpVer = $this->btTend->getSitePhpVer($this->siteName);
        $siteStatus = $this->btTend->getSiteStatus($this->siteName);
        if (isset($this->hostInfo->ftp->username) && $this->hostInfo->ftp->username) {
            $ftpInfo = $this->btTend->getFtpInfo();
            if (!$ftpInfo) {
                $ftpInfo = false;
            }
        } else {
            $ftpInfo = false;
        }

        $phpversion_list = Cache::remember('phpversion_list',function(){
            return $this->btAction->GetPHPVersion();
        });

        // 转换资源百分比
        $site_getround = $this->hostInfo->site_max != 0 ? getround($this->hostInfo->site_max, $this->hostInfo->site_size) : 0;
        $sql_getround = $this->hostInfo->sql_max != 0 ? getround($this->hostInfo->sql_max, $this->hostInfo->sql_size) : 0;
        $flow_getround = $this->hostInfo->flow_max != 0 ? getround($this->hostInfo->flow_max, $this->hostInfo->flow_size) : 0;

        $this->view->assign('userLocked', $this->btTend->userini_status);
        $this->view->assign('title', __('Console center'));
        $this->view->assign('ftpInfo', $ftpInfo);
        $this->view->assign('phpVer', $phpVer);
        $this->view->assign('siteStatus', $siteStatus);
        $this->view->assign('site_getround', $site_getround);
        $this->view->assign('sql_getround', $sql_getround);
        $this->view->assign('flow_getround', $flow_getround);
        $this->view->assign('phpversion_list', $phpversion_list);
        return $this->view->fetch();
    }

    /**
     * 清除缓存
     */
    public function clear_cache(){
        // 清除waf类型缓存
        Cache::rm('getWaf');
        // 清除php版本列表缓存
        Cache::rm('phpversion_list');
        // 清除伪静态规则缓存
        // Cache::rm('phpversion_list');

        $this->success('清理成功','');
    }

    /**
     * 设置PHP版本
     * @Author   Youngxj
     * @DateTime 2019-12-07
     * @return   [type]     [description]
     */
    public function phpSet()
    {
        $phpVer = input('post.ver');
        if (!$phpVer) {
            $this->error('php版本为空');
        }
        $phpversion_list = $this->btAction->GetPHPVersion();
        foreach ($phpversion_list as $key => $value) {
            if (in_array($phpVer, $phpversion_list[$key])) {
                $a = true;
                break;
            } else {
                $a = false;
            }
        }
        if (!$a) {
            $this->error('该PHP版本暂不支持');
        }

        $setPHP = $this->btTend->setPhpVer($phpVer);
        if(!$setPHP){
            $this->error($this->btTend->_error);
        }
        $this->success('修改成功');
    }

    /**
     * 网站停止运行
     * @Author   Youngxj
     * @DateTime 2019-12-14
     */
    public function webStop()
    {
        // 先判断站点状态，防止多次重复操作
        $set = $this->btTend->webstop();
        if(!$set){
            $this->error($this->btTend->_error);
        }
        $this->hostModel->save([
            'status'=>'stop'
        ],['id'=>$this->hostInfo->id]);
        $this->success('暂停成功');
    }

    /**
     * 网站开启
     * @Author   Youngxj
     * @DateTime 2019-12-14
     */
    public function webStart()
    {
        switch ($this->hostInfo['status']) {
            case 'normal':
                break;
            case 'stop':
                break;
            case 'locked':
                $this->error('主机已被锁定','');
                break;
            case 'expired':
                $this->error('主机已到期','');
                break;
            case 'excess':
                $this->error('主机超量，已被停用','');
                break;
            case 'error':
                $this->error('主机异常','');
                break;
            default:
                $this->error('主机异常','');
                break;
        }
        // 先判断站点状态，防止多次重复操作
        $set = $this->btTend->webstart();
        if(!$set){
            $this->error($this->btTend->_error);
        }
        $this->hostModel->save([
            'status'=>'normal'
        ],['id'=>$this->hostInfo->id]);
        $this->success('开启成功');
    }

    // 域名绑定
    public function domain(){
        //获取域名绑定列表
        $domainList = $this->btTend->getSiteDomain();
        //获取子目录绑定信息
        $dirList = $this->btTend->getSiteDirBinding();

        
        // 剩余可绑定数

        // 获取未审核的域名
        $auditList = model('Domainlist')->where('status','<>',1)->select();

        $count = count($domainList) -1 + count($dirList['binding']) + count($auditList);
        $sys = $this->hostInfo->domain_max - $count;
        
        $this->view->assign('title', __('domain'));
        $this->view->assign('sys',$sys);
        $this->view->assign('count',$count);
        $this->view->assign('dirList',$dirList);
        $this->view->assign('domainList',$domainList);
        $this->view->assign('auditList',$auditList);
        return $this->view->fetch();
    }
    
    /**
     * 增加域名绑定
     * @return [type] [description]
     */
    public function incDomain()
    {
        $post_str = $this->request->post();

        if (!empty($post_str['domain'])) {
            $domain_list = trim($post_str['domain']);
            $domain_arr  = explode("\n", $domain_list);

            $block = model('Domainlist')->where(['status' => 'normal'])->select();
            if ($block) {
                foreach ($domain_arr as $k1 => $v1) {
                    foreach ($block as $k2 => $v2) {
                        // 正则查找（待扩展）
                        $reg = "/$v2->domain/i";
                        preg_match($reg, $v1, $sss);
                        if ($sss) {
                            $this->error($v1 . '不能被绑定');
                        }
                        // 字符串查找
                        // if (strpos($v1, $v2->domain) !== false) {
                        // var_dump(11);
                        // $this->error($v1 . '不能被绑定');
                        // }
                    }
                }
            }

            if (!isset($post_str['dirs']) || $post_str['dirs'] == '') {
                $this->error('绑定目录不能为空');
            }
            if (preg_match($this->reg, $post_str['dirs']) || preg_match($this->reg, $post_str['domain'])) {
                $this->error('非法参数');
            }

            if (count($domain_arr)-1 >= $this->hostInfo['domain_max'] && $this->hostInfo['domain_max'] != '0') {
                $this->error('绑定失败：已超出可用域名绑定数1');
            }
            $domainCount = model('Domainlist')->where('vhost_id', $this->vhost_id)->count();

            if ($domainCount-1  >= $this->hostInfo['domain_max'] && $this->hostInfo['domain_max'] != 0) {
                $this->error('绑定失败：已超出可用域名绑定数3');
            }

            //获取域名绑定列表
            $domainList = $this->btTend->getSiteDomain();
            //获取子目录绑定信息
            $dirList = $this->btTend->getSiteDirBinding();
            // 读取默认域名，防止被恶意使用泛解析域名
            // $defaultDomain = $this->hostInfo['default_domain'];
            // 读取用户绑定的泛解析域名，防止被恶意使用该泛解析域名
            // 之后从长计议

            // 域名检测
            foreach ($domain_arr as $key => $value) {

                $isnot_fjx = preg_match('/\*[a-zA-Z0-9]/', $value);
                if ($isnot_fjx) {
                    $this->error('域名格式不正确，请调整后重新提交' . $value);
                }

                // 正则匹配法
                $isnotall = preg_match('/\*\.([a-zA-Z0-9]+[^\.])$/', $value);
                // var_dump($isnotall);
                if ($isnotall) {
                    $this->error('域名格式不正确，请调整后重新提交' . $value);
                }

                // 拆分数组法匹配
                // $isnotall = explode('.',$value);
                // var_dump($isnotall);
                // if($isnotall){
                //     if(count($isnotall)<=2&&$isnotall[0]=='*'){
                //         var_dump(false);
                //     }else{
                //         var_dump(true);
                //     }
                // }else{
                //     var_dump(false);
                // }


                // 判断当前绑定域名是否存在数据库中
                $domain_find = model('Domainlist')->where('domain', $value)->find();
                if ($domain_find) {
                    $this->error('当前域名已被绑定' . $value);
                }
            }

            if ($post_str['dirs'] == '/') {
                $isdir = 0;
                $name  = $this->siteName;
            } else {
                $isdir = 1;
                $name  = $post_str['dirs'];
            }
            Db::startTrans();

            foreach ($domain_arr as $key => $value) {
                // 添加到数据库中
                $data = [
                    'vhost_id'    => $this->vhost_id,
                    'domain'      => $value,
                    'dir'         => $post_str['dirs'],
                    'status'       => $this->hostInfo->is_audit?0:1,
                ];
                
                $add = model('Domainlist')::create($data);
                if (!$add) {
                    Db::rollback();
                    $this->error('添加失败，请稍候重试');
                }
            }

            if($this->hostInfo->is_audit){
                Db::commit();
                $this->success('请等待审核');
            }

            $domain_str = str_replace("\n", ',', $post_str['domain']);

            $modify_status = $this->btTend->addDomain($domain_str, $name, $isdir);

            if (isset($modify_status) && $modify_status['status'] == 'true') {
                Db::commit();
                $this->success($modify_status['msg']);
            } else {
                Db::rollback();
                $this->error('添加失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('域名不能为空');
        }
    }

    /**
     * 删除域名绑定
     * @return [type] [description]
     */
    public function delDomain()
    {
        $delete = $this->request->post('delete');
        $type = $this->request->post('type');
        $id = $this->request->post('id/d');
        Db::startTrans();
        $domainInfo = model('Domainlist')::get(['vhost_id'=>$this->vhost_id,'domain'=> $delete]);
        // 先删除数据库的，如果删除失败就回滚，删除成功之后再删除宝塔面板中的，删除失败就回滚数据库
        if($domainInfo){
            $domainInfo->delete(true);
        }
        if(isset($domainInfo->status)&&$domainInfo->status!=1){
        } elseif ($type == 'domain') {
            $modify_status = $this->btTend->delDomain($this->bt_id, $this->siteName, $delete, 80);
            if (!$modify_status) {
                Db::rollback();
                $this->error('删除失败' . $modify_status['msg']);
            }
        } elseif ($type == 'dir') {
            // 先删除数据库的，如果删除失败就回滚，删除成功之后再删除宝塔面板中的，删除失败就回滚数据库
            $modify_status = $this->btTend->delDomainDir($id);
            if (!$modify_status) {
                Db::rollback();
                $this->error('删除失败' . $modify_status['msg']);
            }
        }
        Db::commit();
        $this->success('删除成功');
    }

    // 密码修改
    public function pass()
    {
        $this->view->assign('title', __('pass'));
        return $this->view->fetch();
    }

    /**
     * 主机密码修改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function passVhost()
    {
        if (request()->post()) {
            $validate = new Validate([
                'oldpass'  => 'require|length:6,30',
                'password' => 'require|length:6,30',
            ], [
                'oldpass'  => '密码不符合规范，长度应大于6小于30位',
                'password' => '密码不符合规范，长度应大于6小于30位',
            ]);
            $data = [
                'oldpass'  => input('post.oldpass'),
                'password' => input('post.password'),
            ];
            if (!$validate->check($data)) {
                $this->error($validate->getError());
            }
            $update = $this->auth->changepwd($data['password'], $data['oldpass'], 0);
            if (!$update) {
                $this->error($this->auth->getError());
            }
            $this->success('修改成功');
        }
    }

    /**
     * 数据库密码修改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function passSql()
    {

        if (request()->post()) {
            if (!$this->hostInfo->sql) {
                $this->error('当前没有开通这项业务');
            }
            // 判断是否存在这项业务
            $sqlInfo = $this->btTend->getSqlInfo();
            if (!$sqlInfo) {
                $this->error('数据库不存在');
            }
            $sqlFind = $this->sqlModel::get($this->hostInfo->sql->id);

            $validate = new Validate([
                'password' => 'require|length:6,12',
            ], [
                'password' => '密码不符合规范，长度应大于6小于12位',
            ]);
            $data = [
                'password' => input('post.password'),
            ];
            if (!$validate->check($data)) {
                $this->error($validate->getError());
            }
            Db::startTrans();

            $sqlFind->password = $data['password'];
            $sqlFind->save();
            Db::commit();
            $this->success('设置成功');
        }
    }

    /**
     * ftp密码修改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function passFtp()
    {
        if (request()->post()) {
            // 判断是否存在这项业务
            if (!$this->hostInfo->ftp) {
                $this->error('当前没有开通这项业务');
            }
            $ftpInfo = $this->btTend->getFtpInfo();
            if (!$ftpInfo) {
                $this->error('当前没有开通这项业务');
            }

            $ftpFind = $this->ftpModel::get($this->hostInfo->ftp->id);

            $validate = new Validate([
                'password' => 'require|length:6,12',
            ], [
                'password' => '密码不符合规范，长度应大于6小于12位',
            ]);
            $data = [
                'password' => input('post.password'),
            ];
            
            if (!$validate->check($data)) {
                $this->error($validate->getError());
            }
            Db::startTrans();

            $ftpFind->password = $data['password'];
            $ftpFind->save();

            Db::commit();
            $this->success('设置成功');
        }
    }

    /**
     * 带宽限制
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function speed()
    {
        if ($this->server_type == 'linux') {
            $netInfo = $this->btAction->GetLimitNet($this->bt_id);
            if (empty($netInfo['limit_rate']) && empty($netInfo['perip']) && empty($netInfo['perserver'])) {
                $netInfo['status'] = false;
            } else {
                $netInfo['status'] = true;
            }
            $viewTheme = 'speed';
        } else {
            $netInfo = $this->btAction->GetLimitNet($this->bt_id);
            if (empty($netInfo['limit_rate']) && empty($netInfo['timeout']) && empty($netInfo['perserver'])) {
                $netInfo['status'] = false;
            } else {
                $netInfo['status'] = true;
            }
            $viewTheme = 'speed_win';
        }
        $this->view->assign('title', __('speed'));

        return $this->view->fetch($viewTheme, [
            'netInfo' => $netInfo,
        ]);
    }

    /**
     * 网站限速修改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function speedUp()
    {
        $post_str = $this->request->post();
        $validate = new Validate([
            'perserver'  => 'require|between:0,500',
            'perip'      => 'between:0,60',
            'limit_rate' => 'require|between:0,2048',
            'timeout'    => 'between:0,1000',
        ], [
            'perserver'  => '并发限制',
            'perip'      => '单IP限制',
            'limit_rate' => '流量限制',
            'timeout'    => '超时时间',
        ]);

        if (!$validate->check($post_str)) {
            $this->error($validate->getError());
        }
        if ($this->server_type == 'linux') {
            if (!empty($post_str['perserver']) && !empty($post_str['perip']) && !empty($post_str['limit_rate'])) {
                $modify_status = $this->btAction->SetLimitNet($this->bt_id, $post_str['perserver'], $post_str['perip'], $post_str['limit_rate']);
                if (isset($modify_status) && $modify_status['status'] == 'true') {
                    $this->success($modify_status['msg']);
                } else {
                    $this->error('设置失败：' . $modify_status['msg']);
                }
            } else {
                $this->error('所有选项不能为空');
            }
        } else {
            if (!empty($post_str['perserver']) && !empty($post_str['timeout']) && !empty($post_str['limit_rate'])) {
                $modify_status = $this->btAction->SetLimitNet_win($this->bt_id, $post_str['perserver'], $post_str['timeout'], $post_str['limit_rate']);
                if (isset($modify_status) && $modify_status['status'] == 'true') {
                    $this->success($modify_status['msg']);
                } else {
                    $this->error('设置失败：' . $modify_status['msg']);
                }
            } else {
                $this->error('所有选项不能为空');
            }
        }
    }

    /**
     * 关闭网站限速
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function speedOff()
    {
        $post_str = $this->request->post();

        if (isset($post_str['speed']) && $post_str['speed'] == 'off') {
            if ($modify_status = $this->btAction->CloseLimitNet($this->bt_id)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        }
    }

    /**
     * 默认文件
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function defaultfile()
    {
        $indexFile = $this->btAction->WebGetIndex($this->bt_id);

        $this->view->assign('title', __('defaultfile'));
        return $this->view->fetch('defaultfile', [
            'indexfile' => $indexFile,
        ]);
    }

    /**
     * 默认文件修改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function fileUp()
    {
        $post_str = $this->request->post();

        if (!empty($post_str['Dindex'])) {
            // 增加非法字符效验
            if (!preg_match("/^[\w\s\.\,]+$/i", $post_str['Dindex'])) {
                $this->error('非法参数');
            }
            $modify_status = $this->btAction->WebSetIndex($this->bt_id, $post_str['Dindex']);
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('不能为空');
        }
    }

    /**
     * 301重定向（普通版）
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Rewrite301()
    {
        if ($this->hostInfo->server_os == 'windows') {
            $this->error('当前不支持该模块','');
        }

        $rewriteInfo           = $this->btAction->Get301Status($this->siteName);
        $rewriteInfo['domain'] = explode(',', $rewriteInfo['domain']);

        $this->view->assign('title', __('rewrite301'));
        return $this->view->fetch('rewrite301', [
            'rewriteInfo' => $rewriteInfo,
        ]);
    }

    /**
     * 301重定向（普通版）更新
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function r301Up()
    {
        if ($this->hostInfo->server_os == 'windows') {
            $this->error('当前不支持该模块');
        }
        $post_str = $this->request->post();

        if (!empty($post_str['domains']) && !empty($post_str['toUrl'])) {
            $rewriteInfo           = $this->btAction->Get301Status($this->siteName);
            $rewriteInfo['domain'] = explode(',', $rewriteInfo['domain']);
            if ($post_str['domains'] !== 'all' && !deep_in_array($post_str['domains'], $rewriteInfo['domain'])) {
                $this->error('域名错误');
            }

            if (preg_match($this->reg, $post_str['domains']) || preg_match($this->reg, $post_str['toUrl'])) {
                $this->error('非法参数');
            }
            $modify_status = $this->btAction->Set301Status($this->siteName, $post_str['toUrl'], $post_str['domains'], 1);
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('不能为空');
        }
    }

    /**
     * 301重定向（普通版）关闭
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function r301Off()
    {
        if ($this->hostInfo->server_os == 'windows') {
            $this->error('当前不支持该模块');
        }
        $post_str = $this->request->post();

        if (isset($post_str['rewrite']) && $post_str['rewrite'] == 'off') {
            if ($modify_status = $this->btAction->Set301Status($this->siteName, 'http://baidu.cpom$request_uri', 'all', 0)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        }
    }

    /**
     * 重定向（测试版）
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function redir()
    {
        if ($this->btTend->serverConfig['webserver'] != 'nginx') {
            $this->error('当前不支持该模块','');
        }
        // 获取网站下的域名列表
        $WebsitesList = $this->btAction->Websitess($this->bt_id, 'domain');
        // 获取重定向内测版列表
        $RedirectList = $this->btAction->GetRedirectList($this->siteName);
        // var_dump($RedirectList);
        if ($RedirectList) {
            foreach ($RedirectList as $key => $value) {
                if (isset($RedirectList[$key]['redirectdomain'][0])) {
                    $RedirectList[$key]['redirectdomain'] = $RedirectList[$key]['redirectdomain'][0];
                }
            }
        }
        $this->view->assign('title', __('redir'));
        return $this->view->fetch('redir', [
            'WebsitesList' => $WebsitesList,
            'RedirectList' => $RedirectList,
        ]);
    }

    /**
     * 重定向（测试版）更新
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function redirUp()
    {
        if ($this->request->post()) {
            // 目标Url
            $tourl1 = input('post.tourl1');
            // 重定向域名
            $redirectdomains = input('post.redirectdomain');
            // 是否开启重定向
            $types = input('post.type') ? '1' : '0';
            // 是否保留参数
            $holdpaths = input('post.holdpath') ? '1' : '0';
            // 重定向名称（用于修改）
            $redirectname = input('post.redirectname');
            // 重定向方式 301 or 302
            $redirecttype = input('post.redirecttype') == '301' ? '301' : '302';
            // 重定向内容 域名 or 路径
            $domainortype = input('post.domainortype') == 'domain' ? 'domain' : 'path';
            // 重定向路径 （用于路径）
            $redirectpath = input('post.redirectpath');
            if (!$tourl1) {
                return ['code' => '-1', 'msg' => '跳转链接为空'];
            }
            if (preg_match($this->reg, $redirectname) || preg_match($this->reg, $tourl1) || preg_match($this->reg, $redirectpath) || preg_match($this->reg, $redirectdomains)) {
                $this->error('非法参数');
            }
            // 获取网站下的域名列表
            $WebsitesList = $this->btAction->Websitess($this->bt_id, 'domain');
            if (!empty($redirectdomains) && !deep_in_array($redirectdomains, $WebsitesList)) {
                $this->error('域名不正确');
            }
            //批量选择域名
            //$redirectdomain = explode(',', $redirectdomains);
            $redirectdomain = json_encode(explode(',', $redirectdomains));
            $type           = $types ? 1 : '0';
            $holdpath       = $holdpaths ? 1 : '0';
            if (isset($redirectname) && $redirectname) {
                $redirUp = $this->btAction->ModifyRedirect($this->siteName, $redirectname, $redirecttype, $domainortype, $redirectdomain, $redirectpath, $tourl1, $type, $holdpath);
            } else {
                $redirUp = $this->btAction->CreateRedirect($this->siteName, $redirecttype, $domainortype, $redirectdomain, $redirectpath, $tourl1, $type, $holdpath);
            }

            if ($redirUp) {
                return ['code' => '200', 'msg' => @$redirUp['msg']];
            } else {
                return ['code' => '-1', 'msg' => '添加失败：' . @$redirUp['msg']];
            }
        }
    }

    /**
     * 重定向（测试版）删除
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function redirDel()
    {
        if (!$this->request->post()) {
            return ['code' => '-1', 'msg' => '非法请求'];
        }
        $redirectname = input('post.redirectname');
        $del          = $this->btAction->DeleteRedirect($this->siteName, $redirectname);
        if ($del) {
            return ['code' => '200', 'msg' => @$del['msg']];
        } else {
            return ['code' => '-1', 'msg' => '删除失败：' . @$del['msg']];
        }
    }

    /**
     * 伪静态规则
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function rewrite()
    {
        //获取内置伪静态规则名
        $rewriteList = $this->btAction->GetRewriteList($this->siteName);
        //获取当前网站伪静态规则
        if ($this->server_type == 'linux') {
            $rewriteInfo = $this->btAction->GetFileBody($this->siteName, 1);
        } else {
            $rewriteInfo = $this->btAction->GetSiteRewrite($this->siteName);
        }

        $this->view->assign('title', __('rewrite'));
        //获取子目录绑定信息
        $dirList = $this->btAction->GetDirBinding($this->bt_id);
        return view('rewrite', [
            'dirList'     => $dirList,
            'rewriteList' => $rewriteList,
            'rewriteInfo' => $rewriteInfo,
        ]);
    }

    /**
     * 伪静态规则获取
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function rewriteGet()
    {
        $post_str = $this->request->post();
        // 增加传值效验
        // $rewrites = ['0.当前','EduSoho','EmpireCMS','dabr','dbshop','dedecms','default','discuz','discuzx','discuzx2','discuzx3','drupal','ecshop','emlog','laravel5','maccms','mvc','niushop','phpcms','phpwind','sablog','seacms','shopex','thinkphp','typecho','typecho2','wordpress','wp2','zblog'];
        // if(!in_array($post_str['rewrites'],$rewrites)){
        //     $this->error('非法请求');
        // }
        if ($this->server_type == 'linux') {
            if (isset($post_str['rewrites']) && !empty($post_str['rewrites'])) {
                if ($post_str['rewrites'] == '0.当前') {
                    $rewrite = $this->siteName;
                    $type    = 1;
                } else {
                    $rewrite = $post_str['rewrites'];
                    $type    = 0;
                }
                if ($post_str['dirdomain'] == '/') {
                    $modify_status = $this->btAction->GetFileBody($rewrite, $type);
                } else {
                    if ($post_str['rewrites'] == '0.当前') {
                        $modify_status = $this->btAction->GetDirRewrite($post_str['dirdomain']);
                    } else {
                        $modify_status = $this->btAction->GetFileBody($rewrite, $type);
                    }
                }
                if (isset($modify_status) && $modify_status['status'] == 'true') {
                    return ['code' => '200', 'msg' => '请求成功', 'data' => @$modify_status['data']];
                } else {
                    $this->error('请求失败：' . @$modify_status['msg']);
                }
                exit();
            } else {
                $this->error('非法请求');
            }
        } else {
            $rewrite = $post_str['rewrites'];
            if (isset($rewrite) && !empty($rewrite)) {
                if ($rewrite == '0.当前') {
                    $rewriteInfo = $this->btAction->GetSiteRewrite($this->siteName);
                    return ['code' => '200', 'msg' => '请求成功', 'data' => @$rewriteInfo['data']];
                }

                // 获取当前运行环境
                $type = 'iis';

                if ($post_str['dirdomain'] == '/') {
                    $modify_status = $this->btAction->GetFileBody_win($rewrite, $type);
                } else {
                    if ($rewrite == '0.当前') {
                        $modify_status = $this->btAction->GetDirRewrite($post_str['dirdomain']);
                    } else {
                        $modify_status = $this->btAction->GetFileBody_win($rewrite, $type);
                    }
                }
                if (isset($modify_status) && $modify_status['status'] == 'true') {
                    return ['code' => '200', 'msg' => '请求成功', 'data' => @$modify_status['data']];
                } else {
                    $this->error('请求失败：' . @$modify_status['msg']);
                }
                exit();
            } else {
                $this->error('非法请求');
            }
        }
    }

    /**
     * 伪静态规则设置
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function rewriteSet()
    {
        $dirdomain = input('post.dirdomain');
        $rewrite   = input('post.rewrite', '', null);
        // var_dump($rewrite);exit;
        if (preg_match($this->reg_rewrite, $rewrite)) {
            $this->error('非法参数');
        }
        if (isset($rewrite)) {
            if ($dirdomain == '/') {
                if ($this->server_type == 'linux') {
                    $modify_status = $this->btAction->SaveFileBody($this->siteName, $rewrite, 'utf-8');
                } else {
                    $modify_status = $this->btAction->SetSiteRewrite($this->siteName, $rewrite);
                }
            } else {

                // $this->btAction->GetDirRewrite($dirdomain, 1);
                $GetDirRewrite = $this->btAction->GetDirRewrite($dirdomain, 1);
                // var_dump($GetDirRewrite);exit;
                if (!$GetDirRewrite || $GetDirRewrite['status'] != 'true') {
                    $this->error('设置失败：' . @$GetDirRewrite['msg']);
                } else {
                    $dir_path = $GetDirRewrite['filename'];
                }
                $modify_status = $this->btAction->SaveFileBody($dir_path, $rewrite, 'utf-8', 1);
            }
            // var_dump($modify_status);exit;
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success(@$modify_status['msg']);
            } else {
                $this->error('设置失败：' . @$modify_status['msg']);
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 文件管理(FTP)
     *
     * @return void
     */
    public function file_ftp()
    {
        if (!extension_loaded('ftp')) {
            $this->error('未开启FTP扩展','');
        }

        // 判断当前站点是否开通ftp

        $type = input('post.type');
        if (!$this->hostInfo->ftp) {
            $this->error('不支持该模块', '');
        }
        $host     = Config::get('site.ftp_server') ? Config::get('site.ftp_server') : '127.0.0.1';
        $ssl     = Config::get('site.ftp_ssl') == true ? true : false;
        $port     = Config::get('site.ftp_port') ? Config::get('site.ftp_port') : '21';

        $username = $this->hostInfo->ftp->username;
        $password = $this->hostInfo->ftp->password;
        if (!$host || !$port || !$username || !$password) {
            $this->error('不支持该模块', '');
        }

        // 防止错误
        try {
            $ftp = new \FtpClient\MyFtpClient();
            $ftp->connect($host,$ssl,$port,30);
            $ftp->login($username, $password);
        } catch (\Exception $e) {
            $excMsg = $e->getMessage();
            switch ($excMsg) {
                case 'Login incorrect':
                    $this->error('账号或密码错误','');
                    break;
                case 'Unable to connect':
                    $this->error('FTP服务器连接失败','');
                    break;
                default:
                    $this->error('FTP连接失败');
                    break;
            }
        }
        // 定义临时下载目录
        $tempDir = ROOT_PATH . 'logs/ftp_temp/' . $this->siteName . '/';

        if (!is_dir($tempDir)) {
            // 判断临时目录是否存在，不存在则创建
            mkdir(iconv("UTF-8", "GBK", $tempDir), 0755, true);
        }

        // 进入指定目录
        $path = input('get.path');
        $ftp->chdir($path);

        $path = $ftp->pwd();

        $path_arr = explode('/',$path);
        $text_arr = [];
        for ($i=0; $i < count($path_arr); $i++) { 
            if($path_arr[$i]){
                if(isset($text_arr[$i-1]['url'])&&$text_arr[$i-1]['url']){
                    $str = $text_arr[$i-1]['url'];
                }else{
                    $str = '';
                }
                $text_arr[$i]['path'] = $path_arr[$i];
                $text_arr[$i]['url'] = $str.'/'.$path_arr[$i];
            }
            
        }
        $this->view->assign('paths',$text_arr);

        // 新文件夹
        if ($type == 'newdir') {
            $newdir = input('post.newdir');

            try {
                $new = $ftp->mkdir($path . '/' . $newdir);
            } catch (\Exception $e) {
                $this->error('目录创建失败' . $e->getMessage());
            }

            if ($new) {
                $this->success('目录创建成功');
            } else {
                $this->error('目录创建失败');
            }
        }
        // 上传文件
        if ($type == 'uploadfile') {
            $websize = bytes2mb($this->btTend->getWebSizes($this->hostInfo['bt_name']));
            if ($this->hostInfo['site_max'] != '0' && $websize > $this->hostInfo['site_max']) {
                $this->error('空间大小超出，已停止资源');
            }

            $path = input('get.path') == '/' ? '' : input('get.path');

            $file = request()->file('zunfile');
            $info = $file->move($tempDir, $file->getInfo('name'));
            if ($info) {
                set_time_limit(0);
                $postFile = $tempDir . $info->getFilename();
                try {
                    $put = $ftp->put($path . '/' . $info->getFilename(), $postFile, 2);
                } catch (\Exception $e) {
                    $this->error('上传失败' . $e->getMessage());
                }

                if (!$put) {
                    $this->error('上传失败');
                }
                $this->success('上传成功');
            } else {
                $this->error('文件有误');
            }
        }
        // 新文件
        if ($type == 'newfile') {
            $newfile = input('post.newfile') ? preg_replace('/([\.]){2,}||([\/]){1,}/', '', input('post.newfile')) : '';
            $path    = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';

            // $createFile = fopen($tempDir . $newfile, 'w+');
            $createFile = fopen($tempDir . $newfile, 'w+');
            $write      = fwrite($createFile, '123456');
            if (!$createFile || !$write) {
                $this->error('文件创建失败，请检查/logs目录权限');
            }
            fclose($createFile);
            try {
                $new = $ftp->up_file($path . $newfile, $tempDir . $newfile, true, FTP_ASCII);
            } catch (\Exception $e) {
                $this->error('文件创建失败' . $e->getMessage());
            }

            if ($new) {
                $this->success('文件创建成功');
            } else {
                $this->error('文件创建失败');
            }
        }
        //删除文件
        if ($type == 'deletefile') {
            $file = input('post.file');
            if (!$file) {
                $this->error('请选择文件');
            }
            try {
                $deleteFile = $ftp->del_file($file);
            } catch (\Exception $e) {
                $this->error('删除失败' . $e->getMessage());
            }

            if ($deleteFile) {
                $this->success('删除成功' . $file);
            } else {
                $this->error('删除失败' . $file);
            }
        }
        //删除目录
        if ($type == 'deletedir') {
            $file = input('post.file');
            if (!$file) {
                $this->error('请选择目录');
            }
            try {
                $deleteDir = $ftp->del_all($file, true);
            } catch (\Exception $e) {
                $this->error('删除失败' . $e->getMessage());
            }

            if ($deleteDir) {
                $this->success('删除成功' . $file);
            } else {
                $this->error('删除失败' . $file);
            }
        }
        //文件/目录 重命名
        if ($type == 'MvFile') {
            // $path        = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '';
            $oldFileName = input('post.oldName') ? preg_replace('/([\.]){2,}/', '', input('post.oldName')) : '';
            $newFileName = input('post.newName') ? preg_replace('/([\.]){2,}/', '', input('post.newName')) : '';
            if (!$oldFileName || !$newFileName) {
                $this->error('不能为空');
            }

            $old = $path == '/' ? $path . $oldFileName : $path . '/' . $oldFileName;
            $new = $path == '/' ? $path . $newFileName : $path . '/' . $newFileName;

            // 有些服务器不支持此特性
            // if($ftp->get_size($new)=='-1'){
            //     $this->error('该命名已存在');
            // }

            try {
                $MvFile = $ftp->rename($old, $new);
            } catch (\Exception $e) {
                $this->error('失败' . $e->getMessage());
            }

            if ($MvFile) {
                $this->success('成功');
            } else {
                $this->error('失败');
            }
        }
        // 剪切/复制文件
        if ($type == 'cut') {
            $file = input('post.file');
            $name = input('post.name');
            $copy = input('post.copy/d');
            if (!$file || !$name) {
                $this->error('文件为空');
            }
            $n = $copy ? 'copyFileName' : 'cutFileName';
            cookie::set($n, json_encode(['file' => $file, 'name' => $name]));
            $this->success('成功');
        }
        // 粘贴文件
        if ($type == 'paste') {
            $cut_file  = cookie::get('cutFileName');
            $copy_file = cookie::get('copyFileName');
            $type      = $copy_file ? 'copy' : 'cut';
            if (!$cut_file && !$copy_file) {
                $this->error('文件不存在');
            }
            $fileArr = $type == 'copy' ? json_decode($copy_file, 1) : json_decode($cut_file, 1);

            $oldfile = $fileArr['file'];
            $newfile = $path == '/' ? $path . $fileArr['name'] : $path . '/' . $fileArr['name'];
            set_time_limit(0);

            try {
                if ($type == 'cut') {
                    // 移动文件
                    $act = $ftp->move_file($oldfile, $newfile);
                } else if ($type == 'copy') {
                    // 复制文件（容易超时，只能复制小文件）
                    try {
                        // 先下载
                        $down = @$ftp->get($tempDir . $fileArr['name'], $oldfile, 1);
                        // 再上传
                        $put = @$ftp->put($newfile, $tempDir . $fileArr['name'], 2);

                        $act = 1;
                    } catch (\Exception $e) {
                        $this->error('操作超时');
                    }

                    // $act = $ftp->copy_file($oldfile, $newfile, $tempDir . $fileArr['name']);
                }

                if (!$act) {
                    $this->error($ftp->getError());
                }
                cookie::set('cutFileName', null);
                cookie::set('copyFileName', null);
            } catch (\Exception $e) {
                $this->error('失败' . $e->getMessage());
            }
            $this->success('成功');
        }
        // 批量粘贴文件
        if ($type == 'pastes') {
            $cut_files  = cookie::get('cutFileNames');
            $copy_files = cookie::get('copyFileNames');

            $type = $copy_files ? 'copy' : 'cut';
            if (!$cut_files && !$copy_files) {
                $this->error('文件不存在');
            }
            $fileArr     = $type == 'copy' ? json_decode($copy_files, 1) : json_decode($cut_files, 1);
            $arr_success = [];
            $arr_error   = [];
            set_time_limit(0);

            foreach ($fileArr as $key => $value) {
                $oldfile = $value['file'];
                $newfile = $path == '/' ? $path . $value['name'] : $path . '/' . $value['name'];
                if ($type == 'cut') {
                    try {
                        // 移动文件
                        $act = $ftp->move_file($oldfile, $newfile);
                    } catch (\Exception $e) {
                        $this->error('失败' . $e->getMessage());
                    }
                } else if ($type == 'copy') {
                    // 复制文件（容易超时，只能复制小文件）
                    try {
                        // 先下载
                        $down = @$ftp->get($tempDir . $value['name'], $oldfile, 1);
                        // 再上传
                        $put = @$ftp->put($newfile, $tempDir . $value['name'], 2);

                        $act = 1;
                    } catch (\Exception $e) {
                        $this->error('操作超时');
                    }

                    // $act = $ftp->copy_file($oldfile, $newfile, $tempDir . $value['name']);
                }
                if ($act) {
                    $arr_success[] = $value['name'];
                } else {
                    $arr_error[] = $value['name'];
                }
            }
            cookie::set('cutFileNames', null);
            cookie::set('copyFileNames', null);
            $ms = "成功(" . count($arr_success) . ")：" . implode(',', $arr_success) . "<br>失败(" . count($arr_error) . ")：" . implode(',', $arr_error);
            $this->success('成功' . $ms);
        }
        // 批量操作
        if ($type == 'batch') {
            // $path  = input('post.path') ? preg_replace('/([\.]){2,}/', '', input('post.path') . '') : '/';
            $data  = input('post.data');
            $batch = input('post.batch');
            if ($data == '' || $path == '') {
                $this->error('错误的请求');
            }
            switch ($batch) {
                case 'del':
                    $data_arr   = explode(',', $data);
                    $sc_error   = [];
                    $sc_success = [];
                    // 因为无法分辨文件夹/文件所以统统删一遍，存在目录与文件名一致的情况的问题
                    foreach ($data_arr as $key => $value) {
                        $del = $path == '/' ? $path . $value : $path . '/' . $value;
                        try {
                            $delete = $ftp->del_all($del);
                        } catch (\Exception $e) {
                            $this->error('失败' . $e->getMessage());
                        }

                        if (!$delete) {
                            $sc_error[] = $del;
                        } else {
                            $sc_success[] = $del;
                        }
                    }
                    $this->success('处理完成' . "<br/>" . '成功：' . implode(',', $sc_success) . "<br/>" . '失败：' . implode(',', $sc_error));

                    break;
                case 'openZip':

                    break;
                case 'CutFile':
                    $file = input('post.file');
                    $name = input('post.name');
                    $copy = input('post.copy/d');
                    $path = input('post.path');

                    $data_arr = explode(',', $data);

                    $n   = $copy ? 'copyFileNames' : 'cutFileNames';
                    $arr = [];
                    foreach ($data_arr as $key => $value) {
                        $arr[$key]['name'] = $value;
                        $arr[$key]['file'] = $path . $value;
                    }
                    cookie::set($n, json_encode($arr));
                    $this->success('成功');
                    break;
                case 'CutFiles':
                    cookie('CutFile', null);
                    cookie('CutFiles', null);
                    cookie('cutFileName', null);
                    cookie('cutFileNames', null);
                    cookie('copyFileName', null);
                    cookie('copyFileNames', null);

                    $file = input('post.file');
                    $name = input('post.name');
                    $copy = 1;
                    $path = input('post.path');

                    $data_arr = explode(',', $data);

                    $n   = $copy ? 'copyFileNames' : 'cutFileNames';
                    $arr = [];
                    foreach ($data_arr as $key => $value) {
                        $arr[$key]['name'] = $value;
                        $arr[$key]['file'] = $path . $value;
                    }
                    cookie::set($n, json_encode($arr));
                    $this->success('成功');
                    break;
                default:
                    $this->error('错误的请求');
                    break;
            }
        }
        //获取文件夹大小 (有些服务器不支持此特性)
        if ($type == 'getsize') {
            $path = input('post.path');
            if (!$path) {
                $this->error('请选择内容');
            }
            $paths = $path;
            try {
                $size = $ftp->dirSize($paths);
            } catch (\Exception $e) {
                $this->error('获取失败' . $e->getMessage());
            }

            if (isset($size)) {
                $this->success(formatBytes($size));
            } else {
                $this->error('获取失败');
            }
        }
        //获取文件内容
        if ($type == 'getfile') {
            $file = input('post.file') ? preg_replace('/([\.]){2,}/', '/', input('post.file')) : '';
            if (!$file) {
                $this->error('请选择文件');
            }
            try {
                $open_file = $ftp->getContent($file);
            } catch (\Exception $e) {
                $this->error('打开失败' . $e->getMessage());
            }

            if ($open_file) {
                $this->success('成功', '', $open_file);
            } else {
                $this->error('打开失败');
            }
        }
        //保存文件
        if ($type == 'savefile') {
            $file = input('post.file') ? preg_replace('/([\.]){2,}/', '/', input('post.file')) : '';
            if (!$file) {
                $this->error('请选择文件');
            }
            $content  = input('post.content', '', null);
            $encoding = input('post.encoding') ? input('post.encoding') : 'utf-8';
            try {
                $put = $ftp->putFromString($file, $content);
            } catch (\Exception $e) {
                $this->error('保存失败' . $e->getMessage());
            }
            if ($put) {
                $this->success('保存成功');
            } else {
                $this->error('保存失败');
            }
        }
        // 文件下载FTP
        if (input('get.downfile')) {
            $file = input('get.downfile');

            $arr = explode('/', $file);

            if (!is_dir($tempDir)) {
                mkdir(iconv("UTF-8", "GBK", $tempDir), 0755, true);
            }
            // 文件名
            $downFileName = end($arr);
            try {
                // 1:二进制,2:文本模式，已知windows下使用二进制下载图片出现编码错误
                // 下载时不能开启app_trace
                $down = $ftp->get($tempDir . $downFileName, $file,2);
            } catch (\Exception $e) {
                $this->error('下载失败' . $e->getMessage());
            }

            if ($down) {
                $file = @fopen($tempDir . $downFileName, "r");
                if (!$file) {
                    $this->error('文件丢失');
                } else {
                    return downloadTemplate($tempDir, $downFileName);
                }
            } else {
                $this->error('文件下载失败');
            }
        }
        if (input('get.api')) {
            $api = input('get.api');
            switch ($api) {
                case 'dirlist':
                    $list = $ftp->get_rawlist($path);
                    $this->success('请求成功', '', $list);
                    break;

                default:
                    # code...
                    break;
            }
        }

        try {
            $list = $ftp->get_rawlist($path);
        } catch (\Exception $e) {
            $this->error('文件获取失败' . $e->getMessage(),'');
        }

        $php_upload_max = byteconvert(ini_get('upload_max_filesize'));

        $crumbs_nav = array_filter(explode('/', $path));

        $this->view->assign('title', __('file_ftp'));
        return $this->view->fetch('file_ftp', [
            'crumbs_nav'     => $crumbs_nav,
            'viewpath'       => $path == '/' ? $path : $path . '/',
            'list'           => $list,
            'php_upload_max' => $php_upload_max,
        ]);
    }

    /**
     * 在线文件管理模块
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function file()
    {
        //获取网站根目录
        $WebGetKey = $this->webRootPath;
        if (!$WebGetKey) {
            $this->error('获取网站根目录失败','');
        }
        // 获取跨域信息
        $getini = $this->dirUserIni;
        if (!$getini) {
            $this->error('意外的错误','');
        }
        
        //请求路径
        $path = input('get.path') ? preg_replace('/([\.]){2,}/', '', input('get.path') . '/') : '/';
        // TODO 要实现的目的是屏蔽[../ ./ //]等字符
        // TODO 搜索后文件访问路径有问题，整个在线文件管理文件及路径安全还需要全部重新做
        // var_dump($path);exit;
        $path_arr = explode('/',$path);
        $text_arr = [];
        for ($i=0; $i < count($path_arr); $i++) { 
            if($path_arr[$i]){
                if(isset($text_arr[$i-1]['url'])&&$text_arr[$i-1]['url']){
                    $str = $text_arr[$i-1]['url'];
                }else{
                    $str = '';
                }
                $text_arr[$i]['path'] = $path_arr[$i];
                $text_arr[$i]['url'] = $str.'/'.$path_arr[$i];
            }
            
        }
        $this->view->assign('paths',$text_arr);
        // var_dump($path);exit;
        //var_dump($path);
        //请求文件
        $file = input('post.file') ? preg_replace('/([\.]){2,}|([\/\/]){2,}/', '', input('post.file')) : '';

        //var_dump($file);
        // 防止有心人post删除防跨站文件
        // if (strpos(input('post.file'), '.user.ini') !== false) {
        //     $this->error('非法请求');
        // }
        if (preg_match($this->reg_file, $file)) {
            $this->error('非法请求','');
        }

        if ($WebGetKey) {
            //$newWebGetKey = str_replace($WebGetKey,'/',$WebGetKey);

            $Webpath = $WebGetKey . $path;
            $type    = input('post.type');
            if ($type == 'mvfile') {
                $sfile = $Webpath . $file;
                $dfile = $Webpath . input('post.name');
                // var_dump($sfile);
                // var_dump($dfile);
                // exit();
                if (!$this->path_root_check($sfile, $WebGetKey)) {
                    $this->error('非法操作');
                }
                if (!$this->path_root_check($dfile, $WebGetKey)) {
                    $this->error('非法操作');
                }
                if ($this->MvFile($sfile, $dfile)) {
                    $this->success('成功');
                } else {
                    $this->error('失败');
                }
            }
            // 数据库导入
            if ($type == 'sqlinput') {
                // $file = input('post.file') ? preg_replace('/([\.]){2,}/', '/', input('post.file')) : '';
                if (!$file) {
                    $this->error('请选择文件');
                }
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                if (isset($this->hostInfo->sql->username) && $this->hostInfo->sql->username != '') {
                    $input = $this->btAction->SQLInputSqlFile($WebGetKey . $file, $this->hostInfo->sql->username);
                    if ($input && isset($input['status']) && $input['status'] == 'true') {
                        $this->success($input['msg']);
                    } else {
                        $this->error('失败');
                    }
                } else {
                    $this->error('当前主机没有开通数据库');
                }
            }
            if (input('get.go') == 1) {
                $new_url = url_set_value(request()->url(true), 'go', '0');
                $this->success('正在下载，请勿刷新页面……', $new_url);
                exit();
            }
            // 文件下载
            if (input('get.downfile')) {
                $file = input('get.downfile') ? preg_replace('/([\.]){2,}/', '/', input('get.downfile')) : '/';
                $info = pathinfo($file);

                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $down = $this->btAction->download($WebGetKey . $file, $info['basename']);
                if ($down && isset($down['status']) && $down['status'] == 'false') {
                    $this->success($down['msg']);
                }
                exit();
            }
            //php文件查杀
            if ($type == 'webshellcheck') {
                if (!$file) {
                    $this->error('请选择文件');
                }
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $check = $this->btAction->webshellCheck($WebGetKey . $file);
                if ($check && isset($check['status']) && $check['status'] == 'true') {
                    $this->success($file . $check['msg']);
                } else {
                    $this->error('检查失败');
                }
            }
            //删除文件
            if ($type == 'deletefile') {
                if (!$file) {
                    $this->error('请选择文件');
                }
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $deleteFile = $this->btAction->DeleteFile($WebGetKey . $file);
                if ($deleteFile && isset($deleteFile['status']) && $deleteFile['status'] == 'true') {
                    $this->success('删除成功' . $file);
                } else {
                    $this->error('删除失败' . $file);
                }
            }
            //删除目录
            if ($type == 'deletedir') {
                if (!$file) {
                    $this->error('请选择目录');
                }
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $deleteDir = $this->btAction->DeleteDir($WebGetKey . $file);
                if ($deleteDir && isset($deleteDir['status']) && $deleteDir['status'] == 'true') {
                    $this->success('删除成功' . $file);
                } else {
                    $this->error('删除失败' . $file);
                }
            }
            //解压
            if ($type == 'unzip') {
                $password = input('post.password') ? input('post.password') : 'undefined';
                $zipType  = input('post.zipType');
                $sfile    = input('post.sfile') ? preg_replace('/([\.]){2,}/', '', input('post.sfile')) : '';
                if (!$sfile) {
                    $this->error('请选择文件');
                }
                $dfile  = input('post.dfile') ? preg_replace('/([\.]){2,}/', '/', input('post.dfile')) : '/';
                $unpass = input('post.unpass');
                $coding = input('post.coding') == 'UTF-8' ? input('post.coding') : 'gb18030';
                if ($sfile == '' || $dfile == '') {
                    $this->error('文件路径或解压路径为空');
                }
                // var_dump($WebGetKey . $sfile.'<br/>'. $WebGetKey . $dfile.'<br/>'. $password.'<br/>'. $zipType.'<br/>'. $coding);
                // exit();
                $UnZip = $this->btAction->UnZip($WebGetKey . $sfile, $WebGetKey . $dfile, $password, $zipType, $coding);
                if ($UnZip && isset($UnZip['status']) && $UnZip['status'] == 'true') {
                    $this->success('已将解压任务添加到消息队列，解压时间视文件大小所定');
                } else {
                    $this->error('解压失败');
                }
            }
            //压缩
            if ($type == 'zip') {
                $path    = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';
                $sfile   = input('post.sfile') ? preg_replace('/([\.]){2,}/', '', input('post.sfile')) : '';
                $dfile   = input('post.dfile') ? preg_replace('/([\.]){2,}/', '', input('post.dfile')) : '';
                $zipType = input('post.zipType');
                if (!$sfile || !$dfile || !$zipType) {
                    $this->error('非法请求');
                }
                // var_dump($sfile . '<br>' . $WebGetKey . $dfile . '<br>' . $zipType . '<br>' . $WebGetKey . $path);
                // exit();
                switch ($zipType) {
                    case 'zip':
                        $zipType = 'zip';
                        break;
                    case 'rar':
                        $zipType = 'rar';
                        break;
                    case 'tar.gz':
                        $zipType = 'tar.gz';
                        break;
                    default:
                        $this->error('非法请求');
                        break;
                }

                $zip = $this->btAction->fileZip($sfile, $WebGetKey . $dfile, $zipType, $WebGetKey . $path);
                if ($zip && isset($zip['status']) && $zip['status'] == 'true') {
                    $this->success($zip['msg']);
                } else {
                    $this->error('失败');
                }
            }
            //文件重命名/移动
            if ($type == 'MvFile') {
                $path        = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';
                $oldFileName = input('post.oldName') ? preg_replace('/([\.]){2,}/', '/', input('post.oldName')) : '/';
                $newFileName = input('post.newName') ? preg_replace('/([\.]){2,}/', '/', input('post.newName')) : '/';
                if (!$oldFileName || !$newFileName) {
                    $this->error('不能为空');
                }
                // var_dump($WebGetKey . $path . $oldFileName.'<br>'.$WebGetKey . $path . $newFileName);
                // exit();
                if (!$this->path_root_check($WebGetKey . $path . $oldFileName, $WebGetKey)) {
                    $this->error('非法操作');
                }
                if (!$this->path_root_check($WebGetKey . $path . $newFileName, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $MvFile = $this->btAction->MvFile($WebGetKey . $path . $oldFileName, $WebGetKey . $path . $newFileName);
                if ($MvFile && isset($MvFile['status']) && $MvFile['status'] == 'true') {
                    $this->success($MvFile['msg']);
                } else {
                    $this->error('失败');
                }
            }
            //获取文件夹大小
            if ($type == 'getsize') {
                $path = input('post.path');
                if (!$path) {
                    $this->error('请选择文件');
                }
                $paths = $WebGetKey . $path;
                $size  = $this->btAction->GetWebSize($paths);
                if (isset($size['size'])) {
                    $this->success(formatBytes($size['size']));
                } else {
                    $this->error('获取失败');
                }
            }
            //复制/剪切
            if ($type == 'cut') {
                cookie('CutFile', null);
                cookie('CutFiles', null);
                cookie('cutFileName', null);
                cookie('cutFileNames', null);
                cookie('copyFileName', null);
                cookie('copyFileNames', null);
                // $file = input('post.file') ? preg_replace('/([\.]){2,}/', '/', input('post.file')) : '/';
                $name = input('post.name') ? preg_replace('/([\.]){2,}/', '', input('post.name')) : '';
                $copy = input('post.copy');
                if ($copy) {
                    if ($file && $name) {
                        cookie('copyFileName', $name);
                        cookie('copyFileNames', $file);
                        $this->success('请选择合适位置粘贴');
                    } else {
                        $this->error('失败');
                    }
                } else {
                    if ($file && $name) {
                        cookie('cutFileName', $name);
                        cookie('cutFileNames', $file);
                        $this->success('请选择合适位置粘贴');
                    } else {
                        $this->error('失败');
                    }
                }
            }
            //粘贴
            if ($type == 'paste') {
                $path = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';

                $copy = input('post.copy');
                if ($copy) {
                    $copyFileNames = cookie('copyFileNames') ? preg_replace('/([\.]){2,}/', '', cookie('copyFileNames')) : '';
                    $copyFileName  = cookie('copyFileName') ? preg_replace('/([\.]){2,}/', '', cookie('copyFileName')) : '';
                    if ($copyFileNames) {
                        $sfile = $WebGetKey . $copyFileNames;
                        $dfile = $WebGetKey . $path . $copyFileName;
                        if (!$this->path_root_check($sfile, $WebGetKey)) {
                            $this->error('非法操作');
                        }
                        if (!$this->path_root_check($dfile, $WebGetKey)) {
                            $this->error('非法操作');
                        }

                        $mv = $this->btAction->CopyFile($sfile, $dfile);
                    } else {
                        $this->error('无内容');
                    }
                } else {
                    $cutFileNames = cookie('cutFileNames') ? preg_replace('/([\.]){2,}/', '', cookie('cutFileNames')) : '';
                    if ($cutFileNames) {
                        $sfile = $WebGetKey . $cutFileNames;
                        $dfile = $WebGetKey . $path;
                        $mv    = $this->btAction->MvFile($sfile, $dfile);
                    } else {
                        $this->error('无内容');
                    }
                }
                cookie('CutFile', null);
                cookie('CutFiles', null);
                cookie('cutFileName', null);
                cookie('cutFileNames', null);
                cookie('copyFileName', null);
                cookie('copyFileNames', null);
                if ($mv && isset($mv['status']) && $mv['status'] == 'true') {
                    $this->success('完成');
                } elseif ($mv && isset($mv['status']) && $mv['status'] != 'true') {
                    $this->error($mv['msg']);
                } else {
                    $this->error('失败');
                }
            }
            // 新文件夹
            if ($type == 'newdir') {
                $newdir = input('post.newdir') ? preg_replace('/([\.]){1,}||([\/]){1,}/', '', input('post.newdir')) : '';
                $path   = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';
                if (!$this->path_root_check($WebGetKey . $path . $newdir, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $new = $this->btAction->CreateDir($WebGetKey . $path . $newdir);
                if ($new && isset($new['status']) && $new['status'] == 'true') {
                    $this->success('目录创建成功');
                } else {
                    $this->error('目录创建失败');
                }
            }
            // 新文件
            if ($type == 'newfile') {
                $newfile = input('post.newfile') ? preg_replace('/([\.]){2,}||([\/]){1,}/', '', input('post.newfile')) : '';
                $path    = input('post.path') ? preg_replace('/([\.]){2,}/', '/', input('post.path')) : '/';
                if (!$this->path_root_check($WebGetKey . $path . $newfile, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $new = $this->btAction->CreateFile($WebGetKey . $path . $newfile);
                if ($new && isset($new['status']) && $new['status'] == 'true') {
                    $this->success('文件创建成功');
                } else {
                    $this->error('文件创建失败');
                }
            }
            // 新版分片上传
            if ($files = request()->file('blob')) {
                header("Content-type: text/html; charset=utf-8");
                $websize = bytes2mb($this->btTend->getWebSizes($this->hostInfo['bt_name']));
                $this->hostModel->save([
                    'site_size'=>$websize,
                ],['id'=>$this->hostInfo->id]);
                if ($this->hostInfo['site_max'] != '0' && $websize > $this->hostInfo['site_max']) {
                    $this->hostModel->save([
                        'status'=>'excess',
                    ],['id'=>$this->hostInfo->id]);
                    $this->error('空间大小超出，已停止资源');
                }
                $path     = input('get.path') ? preg_replace('/([\.]){2,}/', '', input('get.path') . '') : '/';
                $filePath = ROOT_PATH . 'logs' . DS . 'uploads';

                if (!is_dir($filePath)) {
                    // 判断临时目录是否存在，不存在则创建
                    mkdir(iconv("UTF-8", "GBK", $filePath), 0755, true);
                }

                // 获取文件扩展名
                $temp_arr = explode(".", input('post.f_name'));
                $file_ext = array_pop($temp_arr);
                $file_ext = trim($file_ext);
                $file_ext = strtolower($file_ext);

                // 获取加密文件名
                $file_name  = md5(input('post.f_name')).'.'.$file_ext;
                // 由于中文名上传失败
                // $info = $files->move($filePath, input('post.f_name'));
                $info = $files->move($filePath, $file_name);
                $data = '';
                if ($info) {
                    $postFile = $filePath . DS . $info->getFilename();
                    // $postFile = $filePath.DS.'1235556.png';
                    // iconv("UTF-8","gb2312",$postFile);
                    // var_dump($postFile);exit;
                    if (class_exists('CURLFile')) {
                        // php 5.5
                        $data = new \CURLFile(realpath($postFile));
                    } else {
                        $data = '@' . realpath($postFile);
                    }
                    // $data->postname = $info->getFilename();
                    // $fileName       = $info->getFilename();
                    // 传递原始文件名到服务器中
                    $fileName       = input('post.f_name');
                    $f_size  = input('post.f_size');
                    $f_start = input('post.f_start');
                    $up      = $this->btAction->UploadFiles($WebGetKey . $path, $fileName, $f_size, $f_start, $data);
                    if ($up && is_numeric($up)) {
                        return $up;
                    } elseif (isset($up['status']) && $up['status'] == 'true') {
                        $this->success('上传成功');
                    } else {
                        $this->error('上传失败');
                    }
                } else {
                    $this->error('文件有误');
                }
            }
            //老上传接口
            if ($files = request()->file('zunfile')) {
                $php_upload_max = byteconvert(ini_get('upload_max_filesize'));
                //$this->error('功能未写完');
                $path     = input('get.path') ? preg_replace('/([\.]){2,}/', '', input('get.path') . '') : '/';
                $filePath = ROOT_PATH . 'logs' . DS . 'uploads';

                if (!is_dir($filePath)) {
                    // 判断临时目录是否存在，不存在则创建
                    mkdir(iconv("UTF-8", "GBK", $filePath), 0755, true);
                }

                $info = $files->validate(['size' => $php_upload_max])->move($filePath, '');

                if ($info) {
                    $postFile = $filePath . DS . $info->getFilename();
                    if (class_exists('CURLFile')) {
                        // php 5.5
                        $data['zunfile'] = new \CURLFile(realpath($postFile));
                    } else {
                        $data['zunfile'] = '@' . realpath($postFile);
                    }
                    $data['zunfile']->postname = $info->getFilename();
                    $up                        = $this->btAction->UploadFile($WebGetKey . $path, $data);
                    if ($up && isset($up['status']) && $up['status'] == 'true') {
                        $this->success('上传成功');
                    } else {
                        $this->error('上传失败');
                    }
                } else {
                    $this->error('文件有误');
                }
            }
            //获取文件内容
            if ($type == 'getfile') {
                if (!$file) {
                    $this->error('请选择文件');
                }
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $fileContent = $this->btAction->GetFileBodys($WebGetKey . $file);
                if ($fileContent && isset($fileContent['status']) && $fileContent['status'] == 'true') {
                    return ['code' => 1, 'msg' => '获取成功', 'data' => $fileContent['data'], 'encoding' => $fileContent['encoding']];
                }elseif($fileContent && isset($fileContent['msg'])){
                    return ['code' => 0, 'msg' => $fileContent['msg']];
                } else {
                    $this->error('获取失败');
                }
            }
            //保存文件
            if ($type == 'savefile') {
                // $file = input('post.file') ? preg_replace('/([\.]){2,}/', '/', input('post.file')) : '';
                if (!$file) {
                    $this->error('请选择文件');
                }
                $content  = input('post.content', '', null);
                $encoding = input('post.encoding') ? input('post.encoding') : 'utf-8';
                if (!$this->path_root_check($WebGetKey . $file, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $savefile = $this->btAction->SaveFileBodys($content, $WebGetKey . $file, $encoding);
                // var_dump($fileContent);
                if ($savefile && isset($savefile['status']) && $savefile['status'] == 'true') {
                    $this->success('保存成功');
                } else {
                    $this->error('获取失败' . @$savefile['msg']);
                }
            }
            //查看图片
            if (input('post.type') == 'images') {
                // TODO 中文文件名图片查看错误，本地服务器屏蔽关键词“你”，原因未知
                $file = input('post.file') ? preg_replace('/([\.]){2,}|([\/\/]){2,}/', '', input('post.file')) : '';
                // var_dump($WebGetKey . $file, $file);
                // exit;
                $images = $this->btAction->images_view($WebGetKey . $file,$file);
                // header('Content-type: image/jpeg');
                return json(['image'=>$images]);
                // print_r($images);exit;
            }
            // 批量操作
            if ($type == 'batch') {
                $path  = input('post.path') ? preg_replace('/([\.]){2,}/', '', input('post.path') . '') : '/';
                $data  = input('post.data');
                $batch = input('post.batch');
                if ($data == '' || $path == '') {
                    $this->error('错误的请求');
                }
                switch ($batch) {
                    case 'del':
                        $data_arr = explode(',', $data);

                        foreach ($data_arr as $key => $value) {
                            if ($data_arr[$key] == $WebGetKey) {
                                $this->error('非法操作');
                            }
                            if ($data_arr[$key] == $WebGetKey . '/') {
                                $this->error('非法操作');
                            }
                        }
                        $data = json_encode($data_arr);

                        $del = $this->btAction->SetBatchData($WebGetKey . $path, 4, $data);
                        if ($del && isset($del['status']) && $del['status'] == 'true') {
                            $this->success('批量删除成功');
                        } else {
                            $this->success('批量删除失败');
                        }
                        break;
                    case 'openZip':

                        break;
                    case 'CutFile':
                        cookie('CutFile', null);
                        cookie('CutFiles', null);
                        cookie('cutFileName', null);
                        cookie('cutFileNames', null);
                        cookie('copyFileName', null);
                        cookie('copyFileNames', null);
                        $data_arr = explode(',', $data);

                        $data = json_encode($data_arr);

                        $del = $this->btAction->SetBatchData($WebGetKey . $path, 2, $data);
                        if ($del && isset($del['status']) && $del['status'] == 'true') {
                            cookie('CutFile', 1);
                            $this->success('剪切成功，请选择合适位置粘贴');
                        } else {
                            $this->success('批量剪切失败');
                        }
                        break;
                    case 'CutFiles':
                        cookie('CutFile', null);
                        cookie('CutFiles', null);
                        cookie('cutFileName', null);
                        cookie('cutFileNames', null);
                        cookie('copyFileName', null);
                        cookie('copyFileNames', null);
                        $data_arr = explode(',', $data);

                        $data = json_encode($data_arr);

                        $del = $this->btAction->SetBatchData($WebGetKey . $path, 1, $data);
                        if ($del && isset($del['status']) && $del['status'] == 'true') {
                            cookie('CutFiles', 1);
                            $this->success('复制成功，请选择合适位置粘贴');
                        } else {
                            $this->success('批量复制失败');
                        }
                        break;
                    default:
                        $this->error('错误的请求');
                        break;
                }
            }
            // 执行粘贴批量复制/剪切的任务
            if ($type == 'BatchPaste') {
                cookie('CutFile', null);
                cookie('CutFiles', null);
                cookie('cutFileName', null);
                cookie('cutFileNames', null);
                cookie('copyFileName', null);
                cookie('copyFileNames', null);
                $ty   = input('post.ty') ? input('post.ty') : 1;
                $path = input('post.path') ? preg_replace('/([\.]){2,}/', '', input('post.path') . '') : '/';
                // if (!$path) {
                //     $this->error('非法操作');
                // }
                $bat = $this->btAction->BatchPaste($WebGetKey . $path, $ty);
                if ($bat && isset($bat['status']) && $bat['status'] == 'true') {
                    $this->success($bat['msg']);
                } else {
                    $this->error('失败');
                }
            }
            // 远程下载
            if ($type == 'DownloadFile') {
                // TODO 下载后文件权限为root，可能存在安全隐患
                // 队列ID出来之后再进行队列监控转换文件组权限
                $this->error('该功能暂停使用');
                $path      = input('post.path') ? preg_replace('/([\.]){2,}/', '', input('post.path') . '') : '';
                $mUrl      = input('post.mUrl') ? preg_replace('/([\.]){2,}/', '', input('post.mUrl') . '') : '';
                $dfilename = input('post.dfilename') ? preg_replace('/([\.]){2,}/', '', input('post.dfilename') . '') : '';
                if (!$mUrl) {
                    $this->error('下载地址错误');
                }
                if (!$dfilename) {
                    $this->error('文件名错误');
                }
                if (!$this->path_root_check($WebGetKey . $path, $WebGetKey)) {
                    $this->error('非法操作');
                }
                $websize = bytes2mb($this->btTend->getWebSizes($this->hostInfo['bt_name']));
                $this->hostModel->save([
                    'site_size'=>$websize,
                ],['id'=>$this->hostInfo->id]);
                if ($this->hostInfo['site_max'] != '0' && $websize > $this->hostInfo['site_max']) {
                    $this->hostModel->save([
                        'status'=>'excess',
                    ],['id'=>$this->hostInfo->id]);
                    $this->error('空间大小超出，已停止资源');
                }
                $down = $this->btAction->DownloadFile($WebGetKey . $path, $mUrl, $dfilename);
                if ($down && isset($down['status']) && $down['status'] == 'true') {
                    $this->success($down['msg']);
                } else {
                    $this->error('下载失败');
                }
            }
            // TODO 获取队列（目前没有任务ID对应）
            if($type == 'get_task_lists'){

            }
            
            $search = $this->request->get('search');
            // 目前子目录搜索有问题
            // $all = $this->request->get('all')?'True':'';
            $dirList = $this->btAction->GetDir($Webpath,1,$search);
            if (isset($dirList['status']) && $dirList['status'] != 'true') {
                $this->error('请求目录不存在','');
            }

            //文件夹
            if (isset($dirList['DIR']) && $dirList['DIR'] != '') {
                foreach ($dirList['DIR'] as $key => $value) {
                    if (preg_match($this->reg_file, $dirList['DIR'][$key])) {
                        unset($dirList['DIR'][$key]);
                    } else {
                        $dirList['DIR'][$key] = preg_grep("/\S+/i", explode(';', $dirList['DIR'][$key]));
                    }
                }
            }
            //文件
            if (isset($dirList['FILES']) && $dirList['FILES'] != '') {
                foreach ($dirList['FILES'] as $key => $value) {

                    if (preg_match($this->reg_file, $dirList['FILES'][$key])) {
                        unset($dirList['FILES'][$key]);
                    } else {
                        $dirList['FILES'][$key] = preg_grep("/\S+/i", explode(';', $dirList['FILES'][$key]));
                    }
                }
            }
            // api文件接口
            if($this->request->get('callback')){
                // 处理目录与文件数据
                $path_file_arr_paths = $path_file_arr_files = $list = [];
                foreach ($dirList['DIR'] as $key => $value) {
                    if( 1||$value['4']!='root'){
                        foreach ($value as $key2 => $value2) {
                            if(in_array($value2,['.well-known','web_config','../','..'])){
                                break;
                            }
                            switch ($key2) {
                                case '0':
                                    $path_file_arr_paths[$key]['name'] = $value2;
                                    break;
                                case '1':
                                    $path_file_arr_paths[$key]['size'] = $value2;
                                    break;
                                case '2':
                                    $path_file_arr_paths[$key]['time'] = $value2?date("Y-m-d",$value2):$value2;
                                    break;
                                case '3':
                                    $path_file_arr_paths[$key]['auths'] = $value2;
                                    break;
                                case '4':
                                    $path_file_arr_paths[$key]['group'] = $value2;
                                    break;
                                default:
                                    $path_file_arr_paths[$key][] = $value2;
                                    break;
                            }
                        }
                    }
                }
                foreach ($dirList['FILES'] as $key => $value) {
                    foreach ($value as $key2 => $value2) {
                        if(in_array($value2,['.user.ini'])){
                            break;
                        }
                        switch ($key2) {
                            case '0':
                                $path_file_arr_files[$key]['name'] = $value2;
                                break;
                            case '1':
                                $path_file_arr_files[$key]['size'] = $value2&&is_numeric($value2)?formatBytes($value2):$value2;
                                break;
                            case '2':
                                $path_file_arr_files[$key]['time'] = $value2&&is_numeric($value2)?date("Y-m-d",$value2):$value2;
                                break;
                            case '3':
                                $path_file_arr_files[$key]['auths'] = $value2;
                                break;
                            case '4':
                                $path_file_arr_files[$key]['group'] = $value2;
                                break;
                            default:
                                $path_file_arr_files[$key][] = $value2;
                                break;
                        }
                    }
                }

                $list = array_merge($path_file_arr_paths,$path_file_arr_files);

                $total = count($list);
                return jsonp(['rows'=>$list,'total'=>$total],200);
            }
            $php_upload_max = byteconvert(ini_get('upload_max_filesize'));

            $this->view->assign('title', __('file'));
            return view('file', [
                'search'         => $this->request->get('search'),
                'viewpath'       => $path,
                'dirList'        => $dirList,
                'php_upload_max' => $php_upload_max,
            ]);
        } else {
            $this->error('读取网站根目录出错','');
        }
    }

    /**
     * 文件移动
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    private function MvFile()
    {
        $MvFile = $this->btAction->MvFile();
        if ($MvFile && isset($MvFile['status']) && $MvFile['status'] == 'true') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 网站备份
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function back()
    {
        $WebBackupList = $SqlBackupList = [];

        $WebBackupList = $this->btAction->WebBackupList($this->bt_id);
        if (isset($WebBackupList['data'][0])) {
            foreach ($WebBackupList['data'] as $key => $value) {
                $WebBackupList['data'][$key]['size'] = formatBytes($WebBackupList['data'][$key]['size']);
                // 下载备份文件
                if (input('get.down_back_file') == $WebBackupList['data'][$key]['name']) {
                    $filePath = $WebBackupList['data'][$key]['filename'];
                    $fileName = $WebBackupList['data'][$key]['name'];
                    $down     = $this->btAction->download($filePath, $fileName);
                    if ($down && isset($down['status']) && $down['status'] == 'false') {
                        $this->success($down['msg']);
                    }
                    exit;
                }
            }
        }

        if (isset($this->hostInfo->sql->username) || $this->hostInfo->sql->username) {
            //获取数据库ID
            $WebSqlList = $this->btAction->WebSqlList($this->hostInfo->sql->username);
            if (!$WebSqlList || !isset($WebSqlList['data'][0])) {
                $SqlBackupList['data'] = [];
                // $this->error('没有找到数据库','');
            }else{
                //获取数据库备份列表
                $SqlBackupList = $this->btAction->WebBackupList($WebSqlList['data'][0]['id'], '1', '5', '1');

                if (isset($SqlBackupList['data'][0])) {
                    foreach ($SqlBackupList['data'] as $key => $value) {
                        $SqlBackupList['data'][$key]['size'] = formatBytes($SqlBackupList['data'][$key]['size']);
                        // 下载备份文件
                        if (input('get.down_back_sql') == $SqlBackupList['data'][$key]['name']) {
                            $filePath = $SqlBackupList['data'][$key]['filename'];
                            $fileName = $SqlBackupList['data'][$key]['name'];
                            $down     = $this->btAction->download($filePath, $fileName);
                            if ($down && isset($down['status']) && $down['status'] == 'false') {
                                $this->success($down['msg']);
                            }
                            exit;
                        }
                    }
                }
            }
        }

        $this->view->assign('title', __('back'));

        return view('back', [
            'countback_site'    => count(@$WebBackupList['data']),
            'WebBackupList'     => $WebBackupList,
            'SqlBackupList'     => $SqlBackupList,
            'countback_sql'     => count(@$SqlBackupList['data']),
        ]);
    }

    /**
     * 网站备份创建
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function webBackInc()
    {

        $WebBackupList = $this->btAction->WebBackupList($this->bt_id);
        $securityArray = [];
        foreach ($WebBackupList['data'] as $key => $value) {
            $securityArray[$key] = $WebBackupList['data'][$key]['id'];
        }
        $post_str = $this->request->post();
        if (isset($post_str['to']) && $post_str['to'] == 'back') {
            $back_num = isset($this->hostInfo['web_back_num']) ? $this->hostInfo['web_back_num'] : 5;
            if ($back_num == 0 || count($WebBackupList['data']) < $back_num) {
                if ($modify_status = $this->btAction->WebToBackup($this->bt_id)) {
                    $this->success($modify_status['msg']);
                } else {
                    $this->error('备份失败：' . $modify_status['msg']);
                }
            } else {
                $this->error('无可用手动备份次数，请删除原有备份后重新执行');
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 网站备份删除
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function webBackDel()
    {

        $WebBackupList = $this->btAction->WebBackupList($this->bt_id);
        $securityArray = [];
        foreach ($WebBackupList['data'] as $key => $value) {
            $securityArray[$key] = $WebBackupList['data'][$key]['id'];
        }
        $post_str = $this->request->post();
        if (isset($post_str['del'])) {
            if (in_array($post_str['del'], $securityArray)) {
                if ($modify_status = $this->btAction->WebDelBackup($post_str['del'])) {
                    $this->success($modify_status['msg']);
                } else {
                    $this->error('删除失败：' . $modify_status['msg']);
                }
            } else {
                $this->error('非法请求');
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * FTP开关
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function ftpStatus()
    {

        $ftpInfo = $this->btAction->WebFtpList($this->hostInfo->ftp->username);
        if (!$ftpInfo || !isset($ftpInfo['data']['0'])) {
            $this->error('没有开通这项业务');
        }
        $post_str = $this->request->post();
        if (isset($post_str['ftp']) && $post_str['ftp'] == 'off') {
            if ($modify_status = $this->btAction->SetStatus($ftpInfo['data'][0]['id'], $ftpInfo['data'][0]['name'], 0)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } elseif (isset($post_str['ftp']) && $post_str['ftp'] == 'on') {
            if ($modify_status = $this->btAction->SetStatus($ftpInfo['data'][0]['id'], $ftpInfo['data'][0]['name'], 1)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * Mysql数据库工具箱
     *
     * @return void
     */
    public function sqlTools(){
        if($this->hostInfo->server_os=='windows'){
            $this->error('当前主机不支持该功能','');
        }
        if (!isset($this->hostInfo->sql->username) || !$this->hostInfo->sql->username) {
            $this->error('没有开通该项业务','');
        }
        $sqlInfo = $this->btAction->WebSqlList($this->hostInfo->sql->username);
        if (!$sqlInfo || !isset($sqlInfo['data']['0'])) {
            $this->error('没有开通这项业务','');
        }
        $mysql_list = $this->btAction->GetSqlSize($this->hostInfo->sql->username);
        if($this->request->get('callback')){
            $list = $mysql_list['tables'];
            $count = count($list);
            return jsonp(['rows'=>$list,'total'=>$count]);
        }
        $this->view->assign('title', __('sqltools'));
        return $this->view->fetch('sqltools',[
            'mysql_list'=>$mysql_list,
        ]);
    }

    /**
     * Mysql工具箱操作
     *
     * @return void
     */
    public function sqlToolsAction(){
        $type = $this->request->post('type');
        $tables = $this->request->post('tables');
        if(!$tables){
            $this->error('请选择表名');
        }
        $tables = array_filter(explode(',',$tables));
        if($type=='retable'){
            // 修复表
            $re = $this->btAction->ReTable($this->hostInfo->sql->username,json_encode($tables));
            if($re&&isset($re['status'])&&$re['status']=='true'){
                $this->success($re['msg']);
            }elseif($re&&isset($re['msg'])){
                $this->error($re['msg']);
            }else{
                $this->error('失败');
            }
        }elseif($type=='optable'){
            // 优化表
            $op = $this->btAction->OpTable($this->hostInfo->sql->username,json_encode($tables));
            if($op&&isset($op['status'])&&$op['status']=='true'){
                $this->success($op['msg']);
            }elseif($op&&isset($op['msg'])){
                $this->error($op['msg']);
            }else{
                $this->error('失败');
            }
        }elseif($type=='aitable'||$type=='InnoDB'||$type=='MyISAM'){
            // 转换类型
            $table_type = $this->request->post('table_type')=='MyISAM'?'MyISAM':'InnoDB';
            $al = $this->btAction->AlTable($this->hostInfo->sql->username,json_encode($tables),$table_type);
            if($al&&isset($al['status'])&&$al['status']=='true'){
                $this->success($al['msg']);
            }elseif($al&&isset($al['msg'])){
                $this->error($al['msg']);
            }else{
                $this->error('失败');
            }
        }else{
            $this->error('请求类型错误');
        }
    }

    /**
     * 数据库备份生成
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sqlBackInc()
    {
        if (!isset($this->hostInfo->sql->username) || !$this->hostInfo->sql->username) {
            $this->error('没有开通该项业务','');
        }
        //获取数据库ID
        $WebSqlList = $this->btAction->WebSqlList($this->hostInfo->sql->username);
        if (!$WebSqlList || !isset($WebSqlList['data'][0])) {
            $this->error('没有开通这项业务');
        }
        //获取数据库备份列表
        $WebBackupList = $this->btAction->WebBackupList($WebSqlList['data'][0]['id'], '1', '5', '1');

        $securityArray = [];
        foreach ($WebBackupList['data'] as $key => $value) {
            $securityArray[$key] = $WebBackupList['data'][$key]['id'];
        }
        $post_str = $this->request->post();
        if (isset($post_str['to']) && $post_str['to'] == 'back') {
            $back_num = isset($this->hostInfo['sql_back_num']) ? $this->hostInfo['sql_back_num'] : 5;
            if ($back_num == 0 || count($WebBackupList['data']) < $back_num) {
                if ($modify_status = $this->btAction->SQLToBackup($WebSqlList['data'][0]['id'])) {
                    $this->success($modify_status['msg']);
                } else {
                    $this->error('备份失败：' . $modify_status['msg']);
                }
            } else {
                $this->error('无可用手动备份次数，请删除原有备份后重新执行');
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 数据库备份删除
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sqlBackDel()
    {
        if (!isset($this->hostInfo->sql->username) || !$this->hostInfo->sql->username) {
            $this->error('没有开通该项业务','');
        }
        //获取数据库ID
        $WebSqlList = $this->btAction->WebSqlList($this->hostInfo->sql->username);
        if (!$WebSqlList || !isset($WebSqlList['data'][0])) {
            $this->error('没有开通这项业务');
        }
        //获取数据库备份列表
        $WebBackupList = $this->btAction->WebBackupList($WebSqlList['data'][0]['id'], '1', '5', '1');

        $securityArray = [];
        foreach ($WebBackupList['data'] as $key => $value) {
            $securityArray[$key] = $WebBackupList['data'][$key]['id'];
        }
        $post_str = $this->request->post();
        if (in_array($post_str['del'], $securityArray)) {
            if ($modify_status = $this->btAction->SQLDelBackup($post_str['del'])) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('删除失败' . $modify_status['msg']);
            }
        } else {
            $this->error('非法操作');
        }
    }

    /**
     * 数据库备份下载
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sqlBackDown()
    {
        if (!isset($this->hostInfo->sql->username) || !$this->hostInfo->sql->username) {
            $this->error('没有开通该项业务','');
        }

        //获取数据库ID
        $WebSqlList = $this->btAction->WebSqlList($this->hostInfo->sql->username);
        if (!$WebSqlList || !isset($WebSqlList['data'][0])) {
            $this->error('没有找到数据库');
        }
        //获取数据库备份列表
        $WebBackupList = $this->btAction->WebBackupList($WebSqlList['data'][0]['id'], '1', '5', '1');

        if (isset($WebBackupList['data'][0])) {
            foreach ($WebBackupList['data'] as $key => $value) {
                $WebBackupList['data'][$key]['size'] = formatBytes($WebBackupList['data'][$key]['size']);
                // 下载备份文件
                if (input('get.down_back_sql') == $WebBackupList['data'][$key]['name']) {
                    $filePath = $WebBackupList['data'][$key]['filename'];
                    $fileName = $WebBackupList['data'][$key]['name'];
                    $down     = $this->btAction->download($filePath, $fileName);
                    if ($down && isset($down['status']) && $down['status'] == 'false') {
                        $this->success($down['msg']);
                    }
                    exit;
                }
            }
        }
    }

    /**
     * 数据库备份还原
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sqlInputSql()
    {
        //获取数据库ID
        $WebSqlList = $this->btAction->WebSqlList($this->hostInfo->sql->username);
        if (!$WebSqlList || !isset($WebSqlList['data'][0])) {
            $this->error('没有开通这项业务','');
        }
        //获取数据库备份列表
        $WebBackupList = $this->btAction->WebBackupList($WebSqlList['data'][0]['id'], '1', '5', '1');

        if (isset($WebBackupList['data'][0])) {
            foreach ($WebBackupList['data'] as $key => $value) {
                // 下载备份文件
                if (input('post.file') == $value['name']) {
                    if ($modify_status = $this->btAction->SQLInputSqlFile($value['filename'], $this->hostInfo->sql->username)) {
                        $this->success($modify_status['msg']);
                    } else {
                        $this->error('还原失败' . $modify_status['msg']);
                    }
                }
            }
        }else{
            $this->error('文件错误，请重试！');
        }
    }

    /**
     * SSl
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Ssl()
    {
        $GetSSL  = $this->btAction->GetSSL($this->siteName);
        $Domains = $this->btAction->GetSiteDomains($this->bt_id);
        // var_dump($GetSSL);var_dump($Domains);exit;
        //获取域名绑定列表
        $domainList = $this->btAction->WebDoaminList($this->bt_id);
        $this->view->assign('title', __('ssl'));
        return $this->view->fetch('ssl', [
            'Domains'    => $Domains,
            'domainList' => $domainList,
            'GetSSL'     => $GetSSL,
        ]);
    }

    /**
     * 强制https
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function toHttps()
    {
        $post_str = $this->request->post();
        if ($post_str['toHttps'] == '1') {
            if ($HttpToHttps = $this->btAction->HttpToHttps($this->siteName)) {
                $this->success($HttpToHttps['msg']);
            } else {
                $this->error('修改失败：' . $HttpToHttps['msg']);
            }
        } else {
            if ($HttpToHttps = $this->btAction->CloseToHttps($this->siteName)) {
                $this->success($HttpToHttps['msg']);
            } else {
                $this->error('修改失败：' . $HttpToHttps['msg']);
            }
        }
    }

    /**
     * SSL配置
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sslSet()
    {
        $key = input('post.key');
        $csr = input('post.csr');
        if (empty($key) || empty($csr)) {
            $this->error('内容不能为空');
        }
        if (preg_match($this->reg_rewrite, $key) || preg_match($this->reg_rewrite, $csr)) {
            $this->error('非法参数');
        }
        $modify_status = $this->btAction->SetSSL(1, $this->siteName, $key, $csr);
        if (isset($modify_status) && $modify_status['status'] == 'true') {
            $this->success($modify_status['msg']);
        } else {
            $this->error('修改失败' . $modify_status['msg']);
        }
    }

    /**
     * 关闭SSL
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sslOff()
    {
        $post_str = $this->request->post();
        if (isset($post_str['ssl']) && $post_str['ssl'] == 'off') {
            if ($modify_status = $this->btAction->CloseSSLConf(1, $this->siteName)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        }
    }

    /**
     * ssl证书一键申请
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sslApply()
    {
        set_time_limit(120);
        $domain = input('post.domain');
        if (!$domain) {
            $this->error('域名不能为空');
        }
        $domainFind = Db::name('domain')->where('vhost_id', $this->vhost_id)->where('domain', 'in', $domain)->find();
        if (!$domainFind) {
            $this->error('没有找到该域名');
        }
        $WebGetKey = $this->webRootPath;
        if (!$WebGetKey) {
            $this->error('获取网站根目录失败');
        }
        // var_dump($WebGetKey);exit();
        $GetDVSSL = $this->btAction->GetDVSSL($domain, $WebGetKey);
        // 提交ssl证书申请
        if ($GetDVSSL && isset($GetDVSSL['status']) && $GetDVSSL['status'] == 'true') {
            // 证书申请效验码
            $partnerOrderId = $GetDVSSL['data']['partnerOrderId'];
            // 检查中
            $Completed = $this->btAction->Completed($this->siteName, $partnerOrderId);
            if ($Completed && isset($Completed['status']) && $Completed['status'] == 'true') {
                // 设置域名证书
                $GetSSLInfo = $this->btAction->GetSSLInfo($this->siteName, $partnerOrderId);
                if ($GetSSLInfo && isset($GetSSLInfo['status']) && $GetSSLInfo['status'] == 'true') {
                    $this->success('申请成功，刷新查看');
                } elseif ($GetSSLInfo && isset($GetSSLInfo['status']) && $GetSSLInfo['status'] == 'false') {
                    $this->error($GetSSLInfo['msg']);
                } else {
                    $this->error('域名证书正在设置中，请稍等');
                }
            } elseif ($Completed && isset($Completed['status']) && $Completed['status'] == 'false') {
                $this->error('检测中，请确认域名解析正确并能访问');
            } else {
                $this->error('检测中，请确认域名解析正确并能访问');
            }
        } elseif ($GetDVSSL && isset($GetDVSSL['status']) && $GetDVSSL['status'] == 'false') {
            // 申请失败
            $this->error($GetDVSSL['msg']);
        } else {
            // 申请异常
            $this->error('申请异常');
        }
    }

    /**
     * lets证书一键申请
     * @Author   Youngxj
     * @DateTime 2019-12-05
     * @return   [type]     [description]
     */
    public function sslApplyLets()
    {
        set_time_limit(120);
        if (!$this->Config['email']) {
            $this->error('站长邮箱未配置，请提醒配置');
        }
        $domains = $this->request->post();
        if (!$domains) {
            $this->error('域名不能为空');
        }
        $domains_arr = $domains['domain'];
        $domain      = implode(',', $domains_arr);

        $domainFind = Db::name('domain')->where('vhost_id', $this->vhost_id)->where('domain', 'in', $domain)->find();
        if (!$domainFind) {
            $this->error('没有找到该域名');
        }
        $WebGetKey = $this->webRootPath;
        if (!$WebGetKey) {
            $this->error('获取网站根目录失败');
        }
        // 标记当前用户正在进行这项业务
        Session::set('is_lets', '1');
        $let = $this->btAction->CreateLet($this->siteName, json_encode($domains_arr), $this->Config['email']);

        if (isset($let['status']) && $let['status'] == true) {
            // 删除标记
            Session::set('is_lets', null);
            $this->success($let['msg']);
        } elseif (isset($let['status']) && $let['status'] == false) {
            // 删除标记
            Session::set('is_lets', null);
            $this->error($let['msg']['0']);
        } else {
            // 删除标记
            Session::set('is_lets', null);
            $this->error('申请失败');
        }
    }

    /**
     * 获取lets证书申请日志
     * @Author   Youngxj
     * @DateTime 2019-12-07
     * @return   [type]     [description]
     */
    public function getFileLog()
    {
        // 判断是否正在请求这项业务
        if (Session::get('is_lets')) {
            $num  = 10;
            $file = '/www/server/panel/logs/letsencrypt.log';
            $arr  = $this->btAction->getFileLog($file, $num);
            if ($arr && isset($arr['status']) && $arr['status'] == true) {
                $this->success($arr['msg']);
            } elseif (isset($arr['status']) && $arr['status'] == false) {
                $this->error($arr['msg']);
            } else {
                $this->error('请求失败');
            }
        } else {
            $this->error('意外的错误');
        }
    }

    /**
     * Lets域名证书续签
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function sslRenewLets()
    {
        set_time_limit(120);
        $renew = $this->btAction->RenewLets();
        if ($renew && isset($renew['status']) && $renew['status'] == 'true') {
            return ['code' => 1, 'msg' => '请求成功', $renew];
        } elseif ($renew && isset($renew['status']) && $renew['status'] == 'false') {
            return ['code' => 0, 'msg' => '续签失败', $renew];
        } else {
            $this->error('请求失败');
        }
    }

    /**
     * 网站防盗链
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Protection()
    {

        $GetSecurity = $this->btAction->GetSecurity($this->bt_id, $this->siteName);

        $this->view->assign('title', __('protection'));

        return view('protection', [
            'GetSecurity' => $GetSecurity,
        ]);
    }

    /**
     * 网站防盗链设置
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function ProtectionSet()
    {
        $post_str = $this->request->post();
        if (!empty($post_str['sec_fix']) || !empty($post_str['sec_domains'])) {
            if (preg_match($this->reg, $post_str['sec_fix']) || preg_match($this->reg, $post_str['sec_domains'])) {
                $this->error('非法参数');
            }
            
            $modify_status = $this->btAction->SetSecurity($this->bt_id, $this->siteName, $post_str['sec_fix'], $post_str['sec_domains']);

            
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('规则不能为空');
        }
    }

    /**
     * 防盗链关闭
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function ProtectionOff()
    {
        $GetSecurity = $this->btAction->GetSecurity($this->bt_id, $this->siteName);
        $post_str    = $this->request->post();
        if (isset($post_str['protection']) && $post_str['protection'] == 'off') {
            $modify_status = $this->btAction->SetSecurity($this->bt_id, $this->siteName, $GetSecurity['fix'], $GetSecurity['domains'], false);
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        }
    }

    /**
     * 网站日志
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Sitelog()
    {

        $logList = $this->btAction->GetSiteLogs($this->siteName);
        if ($logList['msg']) {
            if (isset($logList['status']) && $logList['status'] == 'true') {
                $logArr = explode("\n", $logList['msg']);
            } else {
                $logArr = '';
            }
        } else {
            $logArr = '';
        }
        if($this->request->get('down')){
            // 导出日志文件excel
            $logs = $this->btTend->getLogsFileName();
            if($logs===false){
                $this->error($this->btTend->_error);
            }
            // 拆分成数组
            // $arr = explode("\n",$logs);
            $text = $logs;
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control:must-revalidate, post-check=0, pre-check=0");
            header("Content-Type:application/force-download");
            header("Content-Type:application/octet-stream");
            header("Content-Type:application/download");
            header('Content-Disposition:attachment;filename="'.$this->siteName.'_'.date("Y/m/d_H:i:s").'_网站日志.log"');
            header("Content-Transfer-Encoding:binary");
            echo $text;
            exit;
        }

        $this->view->assign('title', __('sitelog'));

        return view('sitelog', [
            'logList' => $logArr,
        ]);
    }

    /**
     * 密码访问
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Httpauth()
    {
        if($this->hostInfo->server_os=='windows'){
            $this->error('当前主机不支持该功能','');
        }
        $vhost_url = $this->webRootPath;
        $setting   = $this->btAction->GetDirUserINI($this->bt_id, $vhost_url);

        $this->view->assign('title', __('httpauth'));
        return view('httpauth', [
            'pass_status' => $setting['pass'],
        ]);
    }

    /**
     * 密码访问配置
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function httpauthSet()
    {
        if($this->hostInfo->server_os=='windows'){
            $this->error('当前主机不支持该功能','');
        }
        $post_str = $this->request->post();
        if (!empty($post_str['username']) && !empty($post_str['password'])) {
            if (preg_match($this->reg, $post_str['username']) || preg_match($this->reg, $post_str['password'])) {
                $this->error('非法参数');
            }
            $modify_status = $this->btAction->SetHasPwd($this->bt_id, $post_str['username'], $post_str['password']);
            if (isset($modify_status) && $modify_status['status'] == 'true') {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('账号或密码为空');
        }
    }

    /**
     * 密码访问关闭
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function httpauthOff()
    {
        if($this->hostInfo->server_os=='windows'){
            $this->error('当前主机不支持该功能','');
        }
        $post_str = $this->request->post();
        if (isset($post_str['auth']) && $post_str['auth'] == 'off') {
            if ($modify_status = $this->btAction->CloseHasPwd($this->bt_id)) {
                $this->success($modify_status['msg']);
            } else {
                $this->error('设置失败：' . $modify_status['msg']);
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 目录保护
     *
     * @return void
     */
    public function dirAuth()
    {
        $list = $this->btAction->get_dir_auth($this->bt_id);
        if ($list && isset($list['status']) && $list['status'] == false) {
            $this->error($list['msg']);
        }

        $this->view->assign('title', __('dirauth'));
        return view('dirauth', [
            'dirAuthList' => isset($list[$this->siteName]) ? $list[$this->siteName] : '',
        ]);
    }

    /**
     * 添加目录保护
     *
     * @return void
     */
    public function setDirAuth()
    {
        $siteDir  = input('post.sitedir');
        $username = input('post.username', '', 'htmlspecialchars');
        $passwd   = input('post.passwd', '', 'htmlspecialchars');
        if (!$siteDir || !$username || !$passwd) {
            $this->error('内容不能为空');
        }
        $siteDir = preg_replace('/([\.|\/]){2,}/', '', $siteDir);

        if (!$this->path_safe_check($siteDir)) {
            $this->error('非法参数');
        }

        if (preg_match($this->reg, $siteDir) || preg_match($this->reg, $username) || preg_match($this->reg, $passwd)) {
            $this->error('非法参数');
        }
        // $randName = Random::alnum(6);

        if ($add = $this->btAction->set_dir_auth($this->bt_id, $username, $siteDir, $username, $passwd)) {
            $this->success($add['msg']);
        } else {
            $this->error('设置失败：' . $add['msg']);
        }
    }

    /**
     * 删除目录保护
     *
     * @author Youngxj <blog@youngxj.cn>
     */
    public function delDirAuth()
    {
        $delName = input('post.delname');
        if (!$delName) {
            $this->error('请求错误');
        }
        $del = $this->btAction->delete_dir_auth($this->bt_id, $delName);
        if (isset($del) && $del['status'] == 'true') {
            $this->success($del['msg']);
        } else {
            $this->error('设置失败：' . $del['msg']);
        }
    }

    /**
     * 获取网站运行目录
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function runPath()
    {
        $WebGetKey = $this->webRootPath;
        if (!$WebGetKey) {
            $this->error('获取网站根目录失败');
        }
        $path = $this->dirUserIni;
        if ($path && isset($path['runPath']) && $path['runPath'] != '') {
            if (isset($path['runPath']['dirs'])) {
                // 轮询过滤网站重要配置目录，防止意外错误
                foreach ($path['runPath']['dirs'] as $key => $value) {
                    if ($value == '/web_config') {
                        unset($path['runPath']['dirs'][$key]);
                    }
                    if($value == '/.well-known'){
                        unset($path['runPath']['dirs'][$key]);
                    }
                }
            }
            $this->view->assign('title', __('runPath'));
            return $this->view->fetch('path', [
                'runPath' => $path['runPath'],
            ]);
        } else {
            $this->error('获取运行目录失败');
        }
    }

    /**
     * 网站运行目录配置
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function setSiteRunPath()
    {
        switch ($this->hostInfo['status']) {
            case 'normal':
                break;
            case 'stop':
                $this->error('主机停止', '');
                break;
            case 'locked':
                $this->error('主机已被锁定','');
                break;
            case 'expired':
                $this->error('主机已到期','');
                break;
            case 'excess':
                $this->error('主机超量，已被停用','');
                break;
            case 'error':
                $this->error('主机异常','');
                break;
            default:
                $this->error('主机异常','');
                break;
        }
        if ($this->request->post()) {
            $dirs = input('post.dirs') ? preg_replace('/([\.]){2,}/', '', input('post.dirs')) : '';
            // 增加非法参数过滤
            if (preg_match($this->reg, $dirs) || preg_match($this->reg_rewrite, $dirs)) {
                $this->error('非法参数');
            }
            // 过滤危险目录
            $dir_arr = [
                '/.well-known',
                '/web_config',
                '//',
                '../',
                './/',
                '..//',
            ];
            
            if(in_array($dirs,$dir_arr)){
                $this->error('该目录被禁止使用');
            }

            $runPath = $dirs ? preg_replace('/([\.]){2,}/', '', $dirs) : '';
            $set     = $this->btAction->SetSiteRunPath($this->bt_id, $runPath);
            if ($set && isset($set['status']) && $set['status'] == 'true') {
                $this->success($set['msg']);
            } else {
                $this->error('设置失败:' . $set['msg']);
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 一键部署
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function deployment()
    {

        $deploymentList = $this->btAction->deployment();
        if (!$deploymentList || isset($deploymentList['status']) && $deploymentList['status'] == false) {
            $this->error('暂不支持该功能','');
        }
        //程序列表倒叙
        $deploymentList['data'] = array_reverse($deploymentList['data']);


        $this->view->assign('title', __('deployment'));
        return view('deployment', [
            'deploymentList' => $deploymentList,
        ]);
    }

    /**
     * 一键部署到网站(兼容老版和新版)
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function deploymentSet()
    {
        $post_str       = $this->request->post();
        $is_new         = input('post.is_new') ? input('post.is_new') : 0;
        $deploymentList = $is_new ? $this->btAction->GetList() : $this->btAction->deployment();

        if (!$deploymentList || isset($deploymentList['status']) && $deploymentList['status'] == false) {
            $is_new ? '' : $this->error('暂不支持该功能');
        }
        if ($dep = $post_str['dep']) {
            $is_inarray = false;
            $data       = $is_new ? $deploymentList['list'] : $deploymentList['data'];
            // var_dump($data);exit;
            foreach ($data as $key => $value) {
                if (in_array($dep, $data[$key])) {
                    $is_inarray = true;
                    break;
                }
            }
            if ($is_inarray) {
                // var_dump($dep, $this->siteName, $this->btTend->getSitePhpVer($this->siteName));exit;
                $SetupPackage = $is_new ? $this->btAction->SetupPackageNew($dep, $this->siteName, $this->btTend->getSitePhpVer($this->siteName)) : $this->btAction->SetupPackage($dep, $this->siteName, $this->btTend->getSitePhpVer($this->siteName));
                // var_dump($SetupPackage);exit;
                if ($SetupPackage && isset($SetupPackage['status']) && $SetupPackage['status'] == true) {
                    $this->success('一键部署成功');
                } else {
                    $this->error('部署失败，请稍后再试，如多次重试依然失败请联系管理员');
                }
            } else {
                $this->error('没有该程序可以安装');
            }
        } else {
            $this->error('部署任务为空');
        }
    }

    /**
     * 一键部署列表（新版）
     * @Author   Youngxj
     * @DateTime 2019-12-07
     * @return   [type]     [description]
     */
    public function deployment_new()
    {
        $deploymentList = $this->btAction->GetList();

        $this->view->assign('title', __('deployment_new'));
        return view('deployment_new', [
            'deploymentList' => $deploymentList,
        ]);
    }

    /**
     * 防篡改
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Proof()
    {
        $GetProof = $this->btAction->GetProof();
        if (isset($GetProof['open']) && $GetProof['open'] == 'true') {
            foreach ($GetProof['sites'] as $key => $value) {
                if ($GetProof['sites'][$key]['siteName'] == $this->siteName) {
                    $proofInfo = $GetProof['sites'][$key];
                    break;
                } else {
                    $proofInfo = '';
                }
            }
            if (!$proofInfo) {
                $this->error('没有找到该站点');
            }
        } else {
            $this->error('当前主机不支持该插件','');
        }
        $this->view->assign('title', __('proof'));
        return $this->view->fetch('proof', [
            'proofInfo' => $proofInfo,
        ]);
    }

    /**
     * 防篡改站点设置开关
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function proofStatus()
    {
        if ($this->request->post()) {
            $SiteProof = $this->btAction->SiteProof($this->siteName);
            if ($SiteProof && $SiteProof['status'] == 'true') {
                $this->success($SiteProof['msg']);
            } else {
                $this->error('修改失败：' . $SiteProof['msg']);
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 网站防篡改删除规则
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function delProof()
    {
        $post_str = $this->request->post();
        $name     = $post_str['name'];
        $type     = $post_str['type'];
        if (preg_match($this->reg, $name) || preg_match($this->reg, $type)) {
            $this->error('非法参数');
        }
        if ($type == 'protect') {
            $SiteProof = $this->btAction->DelprotectProof($this->siteName, $name);
        } elseif ($type == 'excloud') {
            $SiteProof = $this->btAction->DelexcloudProof($this->siteName, $name);
        } else {
            $this->error('非法请求');
        }

        if ($SiteProof && $SiteProof['status'] == 'true') {
            $this->success($SiteProof['msg']);
        } else {
            $this->error('修改失败：' . $SiteProof['msg']);
        }
    }

    /**
     * 网站防篡改添加规则
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function incProof()
    {
        $post_str = $this->request->post();
        $name     = $post_str['name'];
        $type     = $post_str['type'];
        if ($type == 'protect') {
            $SiteProof = $this->btAction->AddprotectProof($this->siteName, $name);
        } elseif ($type == 'excloud') {
            $SiteProof = $this->btAction->AddexcloudProof($this->siteName, $name);
        } else {
            $this->error('非法请求');
        }

        if ($SiteProof && $SiteProof['status'] == 'true') {
            $this->success($SiteProof['msg']);
        } else {
            $this->error('修改失败：' . $SiteProof['msg']);
        }
    }

    /**
     * 监控报表
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function total()
    {
        $Total = $this->btAction->GetTotal();
        $day = $this->request->get('time',date('Y-m-d', time()));
        if ($Total && @$Total['open'] == 'true') {
            $siteTotal = $this->btAction->SiteTotal($this->siteName, $day);
            if (!$siteTotal) {
                $this->error('意外的错误','');
            }
        } else {
            $this->error('当前主机不支持该插件','');
        }
        $Network = $this->btAction->SiteNetworkTotal($this->siteName);
        if (isset($Network['days']) && $Network['days'] != '') {
            foreach ($Network['days'] as $key => $value) {
                $Network['days'][$key]['size'] = formatBytes($Network['days'][$key]['size']);
            }
            $Network['total_size'] = formatBytes($Network['total_size']);
        }
        $Spider = $this->btAction->SiteSpiderTotal($this->siteName);

        $Client = $this->btAction->Siteclient($this->siteName);

        if (request()->isAjax()) {
            return ['network' => $Network, 'spider' => $Spider, 'client' => $Client];
        }

        $this->view->assign('title', __('total'));
        return $this->view->fetch('total', [
            'day'       => $day,
            'Client'    => $Client,
            'Spider'    => $Spider,
            'Network'   => $Network,
            'siteTotal' => $siteTotal,
        ]);
    }

    /**
     * 防火墙
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function Waf()
    {
        // 获取防火墙类型
        
        
        $isWaf = Cache::remember('getWaf',function(){
            return $this->btTend->getWaf();
        });
        if (!$isWaf) {
            $this->error('当前主机不支持该插件','');
        }
        // 获取防火墙插件
        $total = [];
        $waf = $this->btAction->Getwaf($isWaf);
        if (isset($waf['open']) && $waf['open'] == 'true') {
            $Sitewaf = $this->btAction->Sitewaf($isWaf, $this->siteName);
            if (!$Sitewaf) {
                $this->error('意外的错误','');
            }
            $SitewafConfig = $this->btAction->SitewafConfig($isWaf);
            if ($SitewafConfig) {
                foreach ($SitewafConfig as $key => $value) {
                    if ($SitewafConfig[$key]['siteName'] == $this->siteName) {
                        $total = $SitewafConfig[$key]['total'];
                        break;
                    } else {
                        $total = [];
                    }
                }
            }
        }elseif(isset($waf['msg'])&&Config('app_debug')){
            $this->error($waf['msg'],'');
        } else {
            $this->error('当前主机不支持该插件','');
        }
        
        // 获取四层防御状态
        $ip_stop = $isWaf!='free_waf'?$this->btTend->getIpstopStatus($isWaf):false;
        $GetLog  = $this->btAction->GetwafLog($isWaf, $this->siteName, date('Y-m-d', time()));

        $this->view->assign('title', __('waf'));
        
        return $this->view->fetch('waf', [
            'waf_type'=> $isWaf,
            'ip_stop' => $ip_stop,
            'GetLog'  => $GetLog,
            'Sitewaf' => $Sitewaf,
            'total'   => $total,
        ]);
    }

    /**
     * 修改waf功能开关
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function wafStatus()
    {
        $isWaf = $this->btTend->getWaf();
        if (!$isWaf) {
            $this->error('意外的情况');
        }
        $post_str = $this->request->post();
        if ($post_str && $post_str['type']) {
            $type   = $post_str['type'];
            $Status = $this->btAction->SitewafStatus($isWaf, $this->siteName, $type);
            if ($Status && $Status['status']) {
                $this->success($Status['msg']);
            } else {
                $this->error('请求失败');
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 修改wafcc
     * @Author   Youngxj
     * @DateTime 2019-12-03
     */
    public function setWafcc()
    {
        $isWaf = $this->btTend->getWaf();
        if (!$isWaf) {
            $this->error('意外的情况');
        }
        $post_str = $this->request->post();
        if ($post_str && $post_str['type']) {
            $type = $post_str['type'];

            if ($type == 'cc') {
                $cycle    = input('post.cycle/d');
                $limit    = input('post.limit/d');
                $endtime  = input('post.endtime/d');
                $increase = input('post.cc_mode')==4 ? 1 : 0;
                $cc_mode = input('post.cc_mode') ;
                $cc_increase_type=input('post.cc_increase_type');
                $increase_wu_heng = input('post.increase_wu_heng');
                $is_open_global = 0;
                
                $cc_four_defense = input('post.cc_four_defense')&&$cc_mode>2?input('post.cc_four_defense'):0;
                // 设置四层防御
                if($cc_four_defense){
                    // 开启
                    $this->btAction->SetIPStop($isWaf);
                }else{
                    $this->btAction->SetIPStopStop($isWaf);
                }
                $Setwafcc = $this->btAction->Setwafcc($isWaf, $this->siteName, $cycle, $limit, $endtime, $increase,$cc_mode,$cc_increase_type,$increase_wu_heng,$is_open_global);
            } elseif ($type == 'retry') {
                $retry       = input('post.retry/d');
                $retry_time  = input('post.retry_time/d');
                $retry_cycle = input('post.retry_cycle/d');
                $Setwafcc    = $this->btAction->SetwafRetry($isWaf, $this->siteName, $retry, $retry_time, $retry_cycle);
            } else {
                $this->error('非法请求');
            }
            if ($Setwafcc && $Setwafcc['status'] == 'true') {
                $this->success($Setwafcc['msg']);
            } else {
                $this->error($Setwafcc['msg']);
            }
            $Status = $this->btAction->SitewafStatus($isWaf, $this->siteName, $type);
            if ($Status && $Status['status']) {
                $this->success($Status['msg']);
            } else {
                $this->error('请求失败');
            }
        } else {
            $this->error('非法请求');
        }
    }

    /**
     * 反向代理
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function proxy()
    {
        if ($post_str = $this->request->post()) {
            if (preg_match($this->reg, input('post.proxyname')) || preg_match($this->reg, input('post.proxysite')) || preg_match($this->reg, input('post.todomain')) || preg_match($this->reg, input('post.subfiltera')) || preg_match($this->reg, input('post.subfilterb'))) {
                $this->error('非法参数');
            }
            if ($this->server_type == 'windows') {
                $cache     = isset($post_str['cache']) ? '1' : '0';
                $advanced  = isset($post_str['advanced']) ? '1' : '0';
                $type      = isset($post_str['type']) ? '1' : '0';
                $path_open = input('post.proxydir') ? '1' : '0';
                $data = [
                    'cache_open'   => $cache,
                    'path_open'    => $path_open,
                    'proxyname'    => input('post.proxyname') ? input('post.proxyname') : time(),
                    'root_path'    => input('post.proxydir') ? input('post.proxydir') : '/',
                    'proxydomains' => json_encode(input('post.proxydomains')),
                    'tourl'        => input('post.proxysite'),
                    'to_domian'    => input('post.todomain'),
                    'sitename'     => $this->siteName,
                    'sub1'         => '',
                    'sub2'         => '',
                    'open'         => 1,
                ];
                $CreateProxy = $this->btAction->CreateProxy_win($data);
                
                if ($CreateProxy && isset($CreateProxy['status'])) {
                    $this->success($CreateProxy['msg']);
                } else {
                    $this->error('失败：' . @$CreateProxy['msg']);
                }
            } else {
                $cachetime = input('post.cachetime/d');
                $cache     = isset($post_str['cache']) ? '1' : '0';
                $advanced  = isset($post_str['advanced']) ? '1' : '0';
                $type      = isset($post_str['type']) ? '1' : '0';
                $subfilter = '[{"sub1":"' . $post_str['subfiltera'] . '","sub2":"' . $post_str['subfilterb'] . '"},{"sub1":"","sub2":""},{"sub1":"","sub2":""}]';

                $CreateProxy = $this->btAction->CreateProxy($cache, $post_str['proxyname'], $cachetime, $post_str['proxydir'], $post_str['proxysite'], $post_str['todomain'], $advanced, $this->siteName, $subfilter, $type);
                if ($CreateProxy && isset($CreateProxy['status'])) {
                    $this->success($CreateProxy['msg']);
                } else {
                    $this->error('失败：' . @$CreateProxy['msg']);
                }
            }
        } else {
            if ($this->server_type == 'windows') {
                // 如果是iis需要判断是否安装插件
                $proxyList = $this->btTend->GetProxy($this->siteName);
                if ($proxyList === false) {
                    $this->error($this->btTend->_error, '');
                }
                // 可选域名列表
                $domainList = $this->btAction->Websitess($this->bt_id, 'domain');
            } else {
                $proxyList  = $this->btAction->GetProxyList($this->siteName);
                $domainList = '';
            }
            if ($this->server_type == 'linux') {
                $viewTheme = 'proxy';
            } else {
                $viewTheme = 'proxy_win';
            }

            $this->view->assign('title', __('proxy'));

            return $this->view->fetch($viewTheme, [
                'domainList' => $domainList,
                'proxyList'  => $proxyList,
            ]);
        }
    }

    /**
     * 反代删除
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]     [description]
     */
    public function proxyDel()
    {
        $proxyname = input('post.proxyname');
        if (!$proxyname) {
            $this->error('请求异常');
        }
        $del = $this->btAction->RemoveProxy($this->siteName, $proxyname);
        if ($del) {
            $this->success('删除成功');
        } else {
            $this->error('删除失败');
        }
    }

    /**
     * Nginx免费防火墙
     *
     * @return void
     */
    public function free_waf(){
        // 判断环境是否为nginx
        if(isset($this->btTend->serverConfig['webserver'])&&$this->btTend->serverConfig['webserver']=='nginx'){
            // 判断是否安装该插件
            $pluginInfo = $this->btTend->softQuery('free_waf');
            if(!$pluginInfo){
                $this->error('当前主机不支持该插件','');
            }
            // waf站点信息
            $waf = $this->btTend->free_waf_site_info();
            // waf站点日志
            $logs = $this->btAction->free_waf_get_logs_list($this->siteName);
            $this->view->assign('waf',$waf);
            $this->view->assign('logs',$logs);
            $this->view->assign('total',$waf['total']);
            $this->view->assign('title','防火墙');
        }else{
            $this->error('当前主机不支持该插件','');
        }
        
        // return $this->view->fetch();
    }

    /**
     * 文件路径安全检查
     * @Author   阿良
     * @DateTime 2019-12-03
     * @param    [type]     $path [description]
     * @return   [type]           [description]
     */
    private function path_safe_check($path)
    {
        $names = array("./", "%", "&", "*", "^", "!", "\\", ".user.ini");
        foreach ($names as $name) {
            if (strpos($path, $name) !== false) {
                return false;
            }
        }

        if ($this->server_type == 'windows') {
            // Windows下不能包含：< > / \ | :  * ?
            // 记录排除规则：\:
            if (!preg_match("/^[\x7f-\xff\w\s\.\/~,@#-]+$/i", $path)) {
                return false;
            }
        } else {
            // Linux下特殊字符如@、#、￥、&、()、-、空格等最好不要使用
            // 记录排除规则：@#-
            if (!preg_match("/^[\x7f-\xff\w\s\.\/~,-]+$/i", $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查根目录合法性
     * @Author   阿良
     * @DateTime 2019-12-03
     * @param    [type]     $path [description]
     * @param    [type]     $root [description]
     * @return   [type]           [description]
     */
    private function path_root_check($path, $root)
    {
        if (!$this->path_safe_check($path)) {
            return false;
        }

        $len = strlen($root);
        if ($root[$len - 1] === '/') {
            $root = substr($root, 0, $len - 1);
        }
        // Linux下特殊字符如@、#、￥、&、()、-、空格等最好不要使用
        // 记录排除规则：@#-
        $rep = "/^" . preg_quote($root, '/') . "\/[\x7f-\xff\w\s\.\/~,-]*$/i";
        if (!preg_match($rep, $path)) {
            return false;
        }

        return true;
    }
    
    // 资源检查并记录
    private function check(){
        if (Cookie('vhost_check_' . $this->vhost_id) < time()) {
            $list = $this->btTend->getResourceSize();
            $msg = $excess = '';
            if ($this->hostInfo['flow_max'] != 0 && $list['total_size'] > $this->hostInfo['flow_max']) {
                $msg.= '流量';$excess = 1;
            }
            if ($this->hostInfo['site_max'] != 0 && $list['websize'] > $this->hostInfo['site_max']) {
                $msg.= '空间';$excess = 1;
            }
            if ($this->hostInfo['sql_max'] != 0 && $list['sqlsize'] > $this->hostInfo['sql_max']) {
                $msg.= '数据库';$excess = 1;
            }
            $host_data = [
                'site_size'=>$list['websize'],
                'flow_size'=>$list['total_size'],
                'sql_size'=>$list['sqlsize'],
                'check_time'=>time(),
            ];
            if($excess){
                $host_data['status'] = 'excess';
            }elseif($this->hostInfo['status']=='excess'){
                // 恢复主机状态
                $host_data['status'] = 'normal';
            }
            
            // 连接宝塔停用站点
            if($excess){
                $this->btTend->webstop();
            }elseif($this->hostInfo['status']=='excess'){
                // 恢复主机状态
                $this->btTend->webstart();
            }
            $this->hostModel->save($host_data,['id'=>$this->vhost_id]);
            if($msg){
                $this->_error  = $msg . ($excess?'超出，资源已停用':'');
                return false;
            }
            Cookie('vhost_check_' . $this->vhost_id, time() + $this->check_time, 3600);
            
        }
        return true;
    }
}