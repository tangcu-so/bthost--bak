<?php

namespace app\common\library;

use btpanel\Btpanel;
use think\Cache;
use fast\Random;

class Btaction
{

    public $_error = '';
    public $btAction  = null;
    protected $api_url = 'http://192.168.191.129';
        // protected $api_url = 'http://139.9.222.32';
    protected $api_token = '00nL2eLQCxDfk06AQ15w1VQ93O3A94HV';
        // protected $api_token = 'RMCknu6nSiBYcif8S2beSi7ar8vN7phs';
    public $bt_id = '';
    public $bt_name = '';
    public $ftp_name = '';
    public $sql_name = '';
    public $webRootPath = '';
    public $siteInfo = '';
    public $dirUserIni = null; // 网站三项配置开关
    public $userini = 1; // 是否强制打开跨站锁
    public $iis_locking = 1; // 是否强制锁定iis配置
    public $userini_status = false; // 跨站锁状态
    public function __construct($os = 'linux')
    {
        $this->port = '8888';
        $this->api_url = $this->api_url.':'.$this->port;
        $this->btAction = new Btpanel($this->api_url, $this->api_token);
        $this->os = $os;
    }


    public function test()
    {
        if ($this->os == 'windows') {
            return $this->tests_win();
        } else {
            return $this->tests();
        }
    }

    // 获取网站全部分类
    public function getsitetype(){
        $list = $this->btAction->Webtypes();
        if($list){
            return $list;
        }else{
            return false;
        }
    }

    // 站点初始化
    public function webInit(){
        // 连接测试
        if(!$this->test()){
            $this->setError($this->_error);
            return false;
        }
        // 查找站点是否存在
        $siteInfo = $this->getSiteInfo($this->bt_name);
        if (!$siteInfo) {
            $this->setError($this->_error);
            return false;
        }
        if (($siteInfo['name'] != $this->bt_name) || ($siteInfo['id'] != $this->bt_id)) {
            $this->setError('该站点信息有误，请联系管理员');
            return false;
        }
        // 验证网站目录信息
        $this->webRootPath = $siteInfo['path'];
        if (empty($this->webRootPath) || !$this->webRootPath) {
            $this->setError('该站点根目录有误，请联系管理员');
            return false;
        }
        
        // 检查运行目录及跨站锁
        if(!$this->examineDir()){
            $this->setError($this->_error);
            return false;
        }
        
        return true;
    }

    // 检查运行目录及跨站锁
    public function examineDir(){
        $this->dirUserIni = $this->btAction->GetDirUserINI($this->bt_id, $this->webRootPath);
        // 检查运行目录
        if (isset($this->dirUserIni['runPath']['dirs'])) {
            $path = $this->dirUserIni['runPath']['runPath'];
            // 修正网站停止时目录变更
            if (!in_array($path, $this->dirUserIni['runPath']['dirs']) && $this->dirUserIni['runPath']['runPath'] != '/www/server/stop/') {
                // 还原运行目录为/
                $this->btAction->SetSiteRunPath($this->bt_id, '/');
            }
        } else {
            $this->setError('网站目录错误，请联系管理员');
            return false;
        }
        // 检查防跨站锁
        if (isset($this->dirUserIni['userini']) && $this->dirUserIni['userini'] != true) {
            $this->userini_status = false;
            if($this->userini){
                // 当防跨站锁丢失时，强制打开跨站锁
                $setUserIni = $this->btAction->SetDirUserINI($this->webRootPath);
                if (!$setUserIni || $setUserIni['status'] != true) {
                    $this->setError('当前站点安全锁打开失败');
                    return false;
                }
            }
        }else{
            $this->userini_status = true;
        }
        // windows专属iis锁
        if($this->os=='windows'){
            if($this->dirUserIni['locking'] != 'true'){
                if($this->iis_locking){
                    // 锁定IIS配置文件(windows)
                    $this->btAction->SetConfigLocking($this->bt_name);
                }
            }
        }
        return true;
    }

    // 获取资源大小：流量、数据库、站点
    public function getResourceSize(){
        set_time_limit(60);
        // 流量
        $Total = $this->btAction->GetTotal();
        if ($Total && isset($Total['open']) && $Total['open'] == 'true') {
            $total_size = $this->getNetNumber_month($this->bt_name);
            if (!$total_size || !isset($total_size['total_size'])) {
                $total_size['total_size'] = 0;
            }
        } else {
            $total_size['total_size'] = 0;
        }
        // 实际使用流量
        $total_size = is_numeric($total_size['total_size']) ? bytes2mb($total_size['total_size']) : 0;
        // 空间大小
        $websize = bytes2mb($this->getWebSizes($this->bt_name));
        // 数据库
        $sqlsize = $this->sql_name?bytes2mb($this->getSqlSizes($this->sql_name)):0;
        return ['sqlsize'=>$sqlsize,'websize'=>$websize,'total_size'=>$total_size];
    }

    /**
     * 修改数据库密码
     *
     * @param [type] $id            数据库id
     * @param [type] $name          数据库名
     * @param [type] $newpassword   数据库新密码
     * @return void
     */
    public function resetSqlPass($id, $name, $newpassword){
        $reset = $this->btAction->ResDatabasePass($id, $name, $newpassword);
        if(isset($reset['status'])&&$reset['status']==true){
            return true;
        }elseif(isset($reset['status'])&&isset($reset['msg'])){
            $this->setError($reset['msg']);
            return false;
        }else{
            $this->setError('error');
            return false;
        }
    }

    /**
     * 修改FTP密码
     *
     * @param [type] $id
     * @param [type] $name
     * @param [type] $newpassword
     * @return void
     */
    public function resetFtpPass($id, $name, $newpassword){
        $reset = $this->btAction->SetUserPassword($id, $name, $newpassword);
        if(isset($reset['status'])&&$reset['status']==true){
            return true;
        }elseif(isset($reset['status'])&&isset($reset['msg'])){
            $this->setError($reset['msg']);
            return false;
        }else{
            $this->setError('error');
            return false;
        }
    }

    /**
     * 获取通信时长
     * @Author   Youngxj
     * @DateTime 2019-12-14
     * @return   [type]     [description]
     */
    public function getRequestTime()
    {
        try {
            $time = $this->getRequestTimes($this->api_url, 10);
        } catch (\Exception $e) {
            return false;
        }

        if (!$time) {
            return false;
        }
        return $time . 'ms';
    }

    public function tests()
    {
        $config = $this->getServerConfig();
        if ($config && isset($config['status']) && $config['status'] == true) {
            return true;
        } else if (isset($config['status']) && $config['status'] == false) {
            $this->setError($config['msg'] . $this->getRequestTime());
            return false;
        } else {
            $this->setError('服务器连接失败' . $this->getRequestTime());
            return false;
        }
    }

    public function tests_win()
    {
        $config = $this->getServerConfig();
        if ($config && isset($config['status']) && $config['status'] === 0) {

            return true;
        } else if (isset($config['status']) && $config['status'] === false) {

            $this->setError($config['msg'] . $this->getRequestTime());
            return false;
        } else {
            $this->setError('服务器连接失败' . $this->getRequestTime());
            return false;
        }
    }

    /**
     * 新建网站
     *
     * @param [type] $hostSetInfo
     * @return void
     */
    public function btBuild($hostSetInfo)
    {
        //使用宝塔创建网站
        $btInfo = $this->btAction->AddSite($hostSetInfo);

        if (isset($btInfo['status']) && $btInfo['status'] != true) {
            $this->setError('主机创建失败->' . @$btInfo['msg'] . '|' . json_encode($hostSetInfo));
            return false;
        }
        if (isset($btInfo['siteStatus']) && @$btInfo['siteStatus'] != true) {
            $this->setError('主机创建失败->' . @$btInfo['msg'] . '|' . json_encode($hostSetInfo));
            return false;
        }

        if (!isset($btInfo['siteId']) || empty($btInfo['siteId'])) {
            $this->setError('网站创建失败|' . json_encode(['btinfo' => $btInfo]));
            return false;
        }
        return $btInfo;
    }

    // 获取默认建站目录
    public function getSitePath(){
        $path = $this->getServerConfig('sites_path');
        return $path?$path:'/www/wwwroot';
    }

    // 获取默认备份目录
    public function getBackupPath(){
        $path = $this->getServerConfig('backup_path');
        return $path?$path:'/www/backup';
    }

    // 获取运行服务类型nginx、apache
    public function getWebServer(){
        $type = $this->getServerConfig('webserver');
        return $type;
    }

    // 构建创建站点需要的参数
    public function setInfo($params,$plans=''){
        // 获取网站建站目录
        // 如果资源组中设定了，那么读取资源组中的，如果没有就默认读取配置接口中的
        if(isset($plans['sites_path'])&&$plans['sites_path']){
            $defaultPath = $plans['sites_path'].'/';
        }else{
            $defaultPath = $this->getSitePath().'/';
        }
        
        // 站点随机域名
        $userRandId = strtolower(Random::alnum(6));
        // 站点域名
        if(isset($params['username'])&&$params['username']){
            // 自定义
            $set_domain = strtolower($params['username']);
        }else{
            // 随机
            $set_domain = $userRandId;
        }
        // 测试语句，正式环境注释
        $set_domain = $userRandId;
        // 拼接默认域名 6.8.18+版官方强转小写 2019-03-09
        $defaultDomain = strtolower($set_domain . '.' . $plans['domain']);
        // php版本
        $phpversion    = isset($plans['phpver'])?$plans['phpver']:'00';
        // mysql
        $sqlType    = isset($plans['sql'])?$plans['sql']:'none';

        $site_max = isset($plans['site_max'])&&$plans['site_max']?$plans['site_max']:'无限制';
        $sql_max = isset($plans['sql_max'])&&$plans['sql_max']?$plans['sql_max']:'无限制';
        $flow_max = isset($plans['flow_max'])&&$plans['flow_max']?$plans['flow_max']:'无限制';
        
        $rand_password = Random::alnum(12);
        
        // 构建数据
        $hostSetInfo = array(
            'webname'      => '{"domain":"' . $defaultDomain . '","domainlist":[],"count":0}',
            'path'         => $defaultPath . $userRandId,
            'type_id'      => $params['sort_id']?$params['sort_id']:'0',
            'type'         => 'PHP',
            'version'      => $phpversion ? $phpversion : '00',
            'port'         => isset($plans['port']) ? $plans['port'] : '80',
            'ps'           => 'Site:' . $site_max . ' Sql:' . $sql_max . ' Flow:' . $flow_max,
            'ftp'          => $plans['ftp'] ? 'true' : 'false',
            'ftp_username' => $set_domain,
            'ftp_password' => $rand_password,
            // 'sql'          => $plans['sql'] ? 'true' : 'false',
            'sql'          => $sqlType!='none' ? $sqlType : 'false', // 新版传递的sql不是true/false而是具体的软件程序
            'codeing'      => 'utf8',
            'datauser'     => $set_domain,
            'datapassword' => $rand_password,
            'check_dir'    => 1, //该参数是win独有
            // 以下非宝塔使用，个人记录
            'bt_name'      => $defaultDomain,
            'domain'       => $set_domain,
        );

        return $hostSetInfo;
    }

    public function presetProcedure($dname, $btName, $defaultPhp){
        $setUp = $this->btAction->SetupPackageNew($dname, $btName, $defaultPhp);
        if ($setUp && isset($setUp['status']) && $setUp['status'] != 'true') {
            $setMsg = isset($setUp['msg']) ? $setUp['msg'] : '';
            // 有错误，记录，防止开通被打断
            $this->setError($setMsg);
            return false;
            // return false;
        }elseif(isset($setUp['msg']['admin_username'])&&$setUp['msg']['admin_username']!=''){
            // $setUp['msg']['admin_username']
            // $setUp['msg']['admin_password']
            // $defaultDomain.$setUp['msg']['success_url']
            // 获取安装程序后获得的默认账号密码
        }
        return true;
    }

    /**
     * 设置并发、网络限制
     *
     * @param [type] $btid  宝塔ID
     * @param [type] $data  并发限制参数
     * @param string $os    环境linux/windows
     * @return void
     */
    public function setLimit($btid, $data)
    {
        if ($this->os == 'linux') {
            $modify_status = $this->btAction->SetLimitNet($btid, $data['perserver'], '25', $data['limit_rate']);
            if (isset($modify_status) && $modify_status['status'] != 'true') {
                // 有错误，记录，防止开通被打断
                $this->setError($modify_status['msg'] . '|' . json_encode(['info' => [$btid, $data['perserver'], '25', $data['limit_rate']]]));
                return false;
            }
        } else {
            $modify_status = $this->btAction->SetLimitNet_win($btid, $data['perserver'], '120', $data['limit_rate']);
            if (isset($modify_status) && $modify_status['status'] != 'true') {
                // 有错误，记录，防止开通被打断
                $this->setError($modify_status['msg'] . '|' . json_encode(['info' => [$btid, $data['perserver'], '120', $data['limit_rate']]]));
                return false;
            }
        }

        return true;
    }

    /**
     * 检查并打开跨站锁
     *
     * @param [type] $btid      宝塔ID
     * @param [type] $rootpath  网站根目录
     * @return void
     */
    public function set_open_basedir($btid, $rootpath)
    {
        // 获取网站目录信息
        $dirUserIni = $this->btAction->GetDirUserINI($btid, $rootpath);
        if (isset($dirUserIni['userini']) && $dirUserIni['userini'] != true) {
            // 当防跨站锁丢失时，强制打开跨站锁
            $setUserIni = $this->btAction->SetDirUserINI($rootpath);
            if (!$setUserIni || $setUserIni['status'] != true) {
                return false;
            }
        }
    }

    /**
     * 获取服务器配置信息
     * @Author   Youngxj
     * @DateTime 2019-12-05
     * @return   [type]     [description]
     */
    public function getServerConfig($value='')
    {
        $config = $this->btAction->GetConfig();
        if ($config) {
            if($value){
                return isset($config[$value])?$config[$value]:false;
            }else{
                return $config;
            }
        } else {
            return false;
        }
    }

    /**
     * 获取主机信息
     * @Author   Youngxj
     * @DateTime 2019-12-14
     * @param    [type]     $siteName 站点名
     * @return   [type]               [description]
     */
    public function getSiteInfo($siteName, $btId = '')
    {
        $siteInfo = $this->btAction->Websites($siteName);
        if (isset($siteInfo['status']) && $siteInfo['status'] === false) {

            $this->setError($siteInfo['msg']);
            return false;
        } elseif (!$siteInfo) {
            $this->setError('服务器连接失败');
            return false;
        } elseif (isset($siteInfo['data']) && !empty($siteInfo['data'])) {
            $siteArr = '';
            if ($siteName && $btId) {
                foreach ($siteInfo['data'] as $value) {
                    if ($value['name'] == $siteName && $value['id'] == $btId) {
                        $siteArr = $value;
                        continue;
                    }
                }
            } elseif ($siteName || $btId) {
                foreach ($siteInfo['data'] as $value) {
                    if ($value['name'] == $siteName || $value['id'] == $btId) {
                        $siteArr = $value;
                        continue;
                    }
                }
            } else {
                $siteArr = $siteInfo['data'][0];
            }
            if (!$siteArr) {
                $this->setError('获取站点信息失败');
                return false;
            }
            return $siteArr;
        } else {
            $this->setError('获取站点信息失败');
            return false;
        }
    }

    /**
     * 获取站点php版本
     * @Author   Youngxj
     * @DateTime 2019-11-30
     * @param    [type]     $siteName 站点名
     * @return   [type]               [description]
     */
    public function getSitePhpVer($siteName)
    {
        $phpver = $this->btAction->GetSitePHPVersion($siteName);
        if (!$phpver) {
            return false;
        }
        if (isset($phpver['status']) && $phpver['status'] == 'false') {
            return $phpver['msg'];
        }
        return isset($phpver['phpversion']) ? $phpver['phpversion'] : '00';
    }

    /**
     * 获取站点状态
     * @Author   Youngxj
     * @DateTime 2019-11-30
     * @return   [type]               [description]
     */
    public function getSiteStatus()
    {
        $webInfo = $this->getSiteConfig($this->bt_name);
        if ($webInfo && $webInfo['data']['0']['status'] == 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取全部站点列表
     *
     * @param integer $maxNum
     * @return void
     */
    public function getSiteList($maxNum = 999)
    {
        $list = $this->btAction->Websites('', 1, $maxNum);
        if (isset($list['data']) && !empty($list['data'])) {
            return $list;
        } else {
            return false;
        }
    }

    /**
     * 获取全部数据库列表
     *
     * @param integer $maxNum
     * @return void
     */
    public function getSqlList($maxNum = 999)
    {
        $list = $this->btAction->WebSqlList('', 1, $maxNum);
        if (isset($list['data']) && !empty($list['data'])) {
            return $list;
        } else {
            return false;
        }
    }

    /**
     * 获取站点信息
     * @Author   Youngxj
     * @DateTime 2019-04-18
     * @param    [type]     $siteName 站点名
     * @return   [type]             [description]
     */
    public function getSiteConfig($siteName)
    {
        $webInfo = $this->btAction->Websites($siteName);
        if (isset($webInfo['data']) && !empty($webInfo['data'])) {
            return $webInfo;
        } else {
            return false;
        }
    }

    /**
     * 获取域名绑定列表
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]           [description]
     */
    public function getSiteDomain()
    {
        $domainList = $this->btAction->WebDoaminList($this->bt_id);
        if ($domainList) {
            return $domainList;
        } else {
            return false;
        }
    }

    /**
     * 获取ftp信息
     * @Author   Youngxj
     * @DateTime 2019-12-05
     * @return   [type]               [description]
     */
    public function getFtpInfo()
    {
        $ftp = $this->btAction->WebFtpList($this->ftp_name);
        if ($ftp && isset($ftp['data'][0])) {
            return $ftp['data'][0];
        } else {
            return false;
        }
    }

    /**
     * 获取sql信息
     * @Author   Youngxj
     * @DateTime 2019-12-05
     * @return   [type]               [description]
     */
    public function getSqlInfo()
    {
        $sql = $this->btAction->WebSqlList($this->sql_name);
        if ($sql && isset($sql['data'][0])) {
            return $sql['data'][0];
        } else {
            return false;
        }
    }

    /**
     * 获取子目录绑定信息
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @return   [type]           [description]
     */
    public function getSiteDirBinding()
    {
        $domainList = $this->btAction->GetDirBinding($this->bt_id);
        if ($domainList) {
            return $domainList;
        } else {
            return false;
        }
    }

    /**
     * 绑定域名
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @param    [type]     $domain_str 域名数组
     * @param    [type]     $btId       宝塔ID
     * @param    [type]     $siteName   站点名（为目录是填写目录名）
     * @param    integer    $is_dir     是否为目录
     */
    public function addDomain($domain_str, $btId, $siteName, $is_dir = 0)
    {
        // 绑定网站
        if ($is_dir) {
            $add = $this->btAction->AddDirBinding($btId, $domain_str, $siteName);
        } else {
            // 绑定目录
            $add = $this->btAction->WebAddDomain($btId, $siteName, $domain_str);
        }
        if ($add) {
            return $add;
        } else {
            return false;
        }
    }

    /**
     * 删除域名绑定
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @param    [type]     $btId    宝塔ID
     * @param    [type]     $webname 网站名
     * @param    [type]     $domain  删除的域名
     * @param    [type]     $port    端口
     * @return   [type]              [description]
     */
    public function delDomain($btId, $webname, $domain, $port)
    {
        $del = $this->btAction->WebDelDomain($btId, $webname, $domain, $port);
        if ($del) {
            return $del;
        } else {
            return false;
        }
    }

    /**
     * 删除绑定目录的域名
     * @Author   Youngxj
     * @DateTime 2019-12-03
     * @param    [type]     $id 列表ID
     * @return   [type]         [description]
     */
    public function delDomainDir($id)
    {
        $del = $this->btAction->DelDirBinding($id);
        if ($del) {
            return $del;
        } else {
            return false;
        }
    }

    /**
     * 删除指定站点
     *
     * @param [type] $id        站点ID
     * @param [type] $webname   站点名称
     * @param integer $ftp      删除ftp
     * @param integer $database 删除数据库
     * @param integer $path     删除文件
     * @return void
     */
    public function siteDelete($id, $webname, $ftp = 1, $database = 1, $path = 1){
        $del = $this->btAction->WebDeleteSite($id, $webname, $ftp = 1, $database = 1, $path = 1);
        if($del&&isset($del['status'])&&$del['status']=='true'){
            return true;
        }elseif(isset($del['status'])){
            $this->setError($del['msg']);
            return false;
        }else{
            return false;
        }
    }

    /**
     * 获取安装的php列表
     *
     * @param integer $is_all   是否显示全部php
     * @return void
     */
    public function getphplist($is_all = 0){
        $list = $this->btAction->GetSoftList('php是');
        if($list&&isset($list['list']['data'])&&$list['list']['data']){
            $arr = [];
            $arr[]['id'] = '00';
            $arr[]['name'] = '纯静态';
            // 将数据处理成合适的数组
            foreach ($list['list']['data'] as $key => $value) {
                // 判断是否安装
                if($value['setup']||$is_all){
                    $number = preg_replace('/[^\d]*/','',$value['name']);
                    if($number){
                        $arr[]['id'] = $number;
                        $arr[]['name'] = $number;
                    }else{
                        continue;
                    }
                }else{
                    continue;
                }
            }
            return $arr;
        }else{
            return [];
        }
        
    }

    // 获取一键部署列表
    public function getdeploymentlist($search=''){
        $list = $this->btAction->GetList($search);
        if($list&&isset($list['list'])&&$list['list']){
            $arr = [];
            foreach ($list['list'] as $key => $value) {
                $arr[$key]['id'] = $value['name'];
                $arr[$key]['name'] = $value['title'].$value['version'];
            }
            return $arr;
        }else{
            return [];
        }
        
        // return Cache::remember('deploymentlist',function(){
            // 缓存器
        // });
    }

    /**
     * 获取防火墙
     * @Author   Youngxj
     * @DateTime 2019-12-05
     * @return   [type]     [description]
     */
    public function getWaf()
    {
        $GetSoftList = $this->btAction->GetSoftList();
        $isWaf       = '';
        if ($GetSoftList) {
            foreach ($GetSoftList['list']['data'] as $key => $value) {
                if ($GetSoftList['list']['data'][$key]['name'] == 'btwaf_httpd' && $GetSoftList['list']['data'][$key]['setup'] == 'true') {
                    $isWaf = '&name=btwaf_httpd';
                    break;
                }
                if ($GetSoftList['list']['data'][$key]['name'] == 'btwaf' && $GetSoftList['list']['data'][$key]['setup'] == 'true') {
                    $isWaf = '&name=btwaf';
                    break;
                }
                if ($GetSoftList['list']['data'][$key]['name'] == 'waf_nginx' && $GetSoftList['list']['data'][$key]['setup'] == 'true') {
                    $isWaf = '&name=waf_nginx';
                    break;
                }
                if ($GetSoftList['list']['data'][$key]['name'] == 'waf_iis' && $GetSoftList['list']['data'][$key]['setup'] == 'true') {
                    $isWaf = '&name=waf_iis';
                    break;
                }
                if ($GetSoftList['list']['data'][$key]['name'] == 'waf_apache' && $GetSoftList['list']['data'][$key]['setup'] == 'true') {
                    $isWaf = '&name=waf_apache';
                    break;
                }
            }
            if ($isWaf != '') {
                return $isWaf;
            } else {
                return false;
            }
        }
    }

    /**
     * 获取反代信息
     * @Author   Youngxj
     * @DateTime 2019-12-12
     * @param    [type]     $sitename 站点名
     */
    public function GetProxy($sitename)
    {
        $fx = $this->btAction->GetProxyList($sitename);
        if ($fx && isset($fx['status']) && $fx['status'] == false) {
            $this->setError($fx['msg']);
            return false;
        } elseif ($fx) {
            return $fx;
        } else {
            return false;
        }
    }

    /**
     * 获取统计报表中站点流量信息
     * @Author   Youngxj
     * @DateTime 2019-05-06
     * @param    [type]     $domain [description]
     * @return   [type]             [description]
     */
    public function getNetNumber($domain)
    {
        $SiteNetworkTotal = $this->btAction->SiteNetworkTotal($domain);
        if ($SiteNetworkTotal) {
            return $SiteNetworkTotal;
        } else {
            return false;
        }
    }

    /**
     * 获取统计报表中站点流量信息（月）
     *
     * @param [type] $domain    站点名
     * @return void
     */
    public function getNetNumber_month($domain)
    {
        $size             = 0;
        $SiteNetworkTotal = $this->btAction->SiteNetworkTotal($domain);
        if (isset($SiteNetworkTotal['status']) && $SiteNetworkTotal['status'] == false) {
            return $SiteNetworkTotal;
        }
        if ($SiteNetworkTotal && isset($SiteNetworkTotal['total_size']) && $SiteNetworkTotal['total_size'] != 0) {
            // 月初时间戳
            $beginThismonth = mktime(0, 0, 0, date('m'), 1, date('Y'));
            // 月末时间戳
            $endThismonth   = mktime(23, 59, 59, date('m'), date('t'), date('Y'));
            //var_dump($beginThismonth);var_dump($endThismonth);exit();
            $SiteNetworkTotal['month_total'] = 0;
            $SiteNetworkTotal = array_diff_key($SiteNetworkTotal, ['total_request' => "xy", "total_size" => "xy"]);
            // echo json_encode($SiteNetworkTotal);exit();
            foreach ($SiteNetworkTotal['days'] as $key => $value) {
                $new = strtotime($SiteNetworkTotal['days'][$key]['date']);
                if ($beginThismonth < $new && $new < $endThismonth) {
                    $size += $SiteNetworkTotal['days'][$key]['size'];
                }
            }
            //var_dump($size);
            $SiteNetworkTotal['month_total'] = $size;
            return $SiteNetworkTotal;
        } else {
            return false;
        }
    }

    /**
     * 获取站点大小
     * @param  [type] $domain 站点名
     * @return [type]         空间大小(字节)
     */
    public function getWebSizes($domain)
    {
        $search = $this->btAction->Websites($domain);
        if (isset($search['data']['0']['path'])) {
            $pathSize = $this->btAction->GetWebSize($search['data']['0']['path']);
            if ($pathSize) {
                return toBytes($pathSize['size']);
            } else {
                return '0';
            }
        } else {
            return '0';
        }
    }

    /**
     * 获取服务器中的数据库大小
     * @param  [type] $sqlname 数据库账号
     * @return [type]          [description]
     */
    public function getSqlSizes($sqlname)
    {
        $sqlSize = $this->btAction->GetSqlSize($sqlname);
        if ($sqlSize && isset($sqlSize['data_size']) && $sqlSize['data_size'] != "") {
            $size = strtolower($sqlSize['data_size']);
            return $size ? toBytes($size) : '0';
        } else {
            return '0';
        }
    }

    /**
     * 站点稽核检查
     *
     * @param [type] $domain    站点名
     * @param [type] $flow      是否检查流量（月）
     * @param [type] $sqlname   数据库名
     * @return void
     */
    public function resource($domain, $sqlname = null, $flow = true)
    {
        $data = [];
        $data['site'] = $this->getWebSizes($domain);

        if($flow){
            $f = $this->getNetNumber_month($domain);
            $data['flow'] = isset($f['month_total']) ? $f['month_total']:false;
        }else{
            $data['flow'] = false;
        }

        $data['sql'] = $sqlname ? $this->getSqlSizes($sqlname) : false;

        return $data;
    }

    /**
     * 站点停止
     *
     * @param [type] $bt_id     站点ID
     * @param [type] $domain    站点名
     * @return void
     */
    public function webstop()
    {
        // 先判断站点状态，防止多次重复操作
        $siteStatus = $this->getSiteStatus($this->bt_name);
        if (!$siteStatus) {
            return true;
        }
        $stop = $this->btAction->WebSiteStop($this->bt_id, $this->bt_name);
        if ($stop && isset($stop['status']) && $stop['status'] == true) {
            return true;
        } elseif ($stop && isset($stop['msg'])) {
            return $stop['msg'];
        } else {
            return false;
        }
    }

    /**
     * 站点开启
     *
     * @param [type] $bt_id     站点ID
     * @param [type] $domain    站点名
     * @return void
     */
    public function webstart()
    {
        $siteStatus = $this->getSiteStatus($this->bt_name);
        if ($siteStatus) {
            return true;
        }
        $start = $this->btAction->WebSiteStart($this->bt_id, $this->bt_name);
        if ($start && isset($start['status']) && $start['status'] == true) {
            return true;
        } elseif ($start && isset($start['msg'])) {
            return $start['msg'];
        } else {
            return false;
        }
    }

    // 宝塔面板日志
    public function panelLogs(){
        $los = $this->btAction->getPanelLogs();
        if(!$los){
            return false;
        }
        return isset($los['data'])?$los['data']:false;
    }

    // 获取四层防御状态
    public function getIpstopStatus($wafType){
        $get = $this->btAction->GetIPStop($wafType);
        if(isset($get['status'])&&$get['status']){
            return true;
        }else{
            return false;
        }
    }

    /**
     * 站点设置php版本
     *
     * @param [type] $ver       版本号
     * @return void
     */
    public function setPhpVer($ver){
        $set = $this->btAction->SetPHPVersion($this->bt_name,$ver);
        if($set&&isset($set['status'])&&$set['status']=='true'){
            return true;
        }elseif($set&&isset($set['msg'])){
            $this->setError($set['msg']);
            return false;
        }else{
            $this->setError('请求失败');
            return false;
        }
    }

    /**
     * 获取服务器连接时间
     *
     * @param [type] $url
     * @param string $data
     * @param integer $timeout
     * @param integer $time
     * @return void
     */
    public function getRequestTimes($url, $timeout = 120)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        $request = curl_getinfo($ch);
        curl_close($ch);
        return isset($request['connect_time']) ? $request['connect_time'] : false;
    }

    /**
     * 设置错误信息
     *
     * @param $error 错误信息
     * @return Auth
     */
    public function setError($error)
    {
        $this->_error = $error;
        return $this;
    }

    /**
     * 获取错误信息
     * @return string
     */
    public function getError()
    {
        return $this->_error ? __($this->_error) : '';
    }
}