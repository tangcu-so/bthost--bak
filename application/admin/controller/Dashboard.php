<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\library\Btaction;
use think\Config;

/**
 * 控制台
 *
 * @icon fa fa-dashboard
 * @remark 用于展示当前系统中的统计数据、统计报表及重要实时数据
 */
class Dashboard extends Backend
{

    /**
     * 查看
     */
    public function index()
    {
        // 数据表预设内容
        $paylist = $createlist = [];
        $day = date("H:i:s", time());
        $createlist[$day] = mt_rand(20, 200);
        $paylist[$day] = mt_rand(1, mt_rand(1, $createlist[$day]));
        
        $hooks = config('addons.hooks');
        $uploadmode = isset($hooks['upload_config_init']) && $hooks['upload_config_init'] ? implode(',', $hooks['upload_config_init']) : 'local';
        $addonComposerCfg = ROOT_PATH . '/vendor/karsonzhang/fastadmin-addons/composer.json';
        Config::parse($addonComposerCfg, "json", "composer");
        $config = Config::get("composer");
        $addonVersion = isset($config['version']) ? $config['version'] : __('Unknown');

        // 今日登陆
        $todayusersignup = model('User')->whereTime('logintime', 'today')->count();
        // 待审核域名
        $auditCount = model('Domainlist')->where('status',0)->count();
        // 即将到期7天
        $endtime7Count = Model('Host')->where('endtime','<=',strtotime('-7 day',time()))->count();
        // 已到期
        $endCount = Model('Host')->where('endtime','<=',time())->count();

        if(empty(Config::get('site.api_token'))||Config::get('site.api_token')==''){
            $this->error(__('System not initialize'),'');
        }

        $btpanel = new Btaction();
        if(!$btpanel->test()){
            $this->error($btpanel->_error,'');
        }
        $type    = $this->request->post('type');

        if ($type == 'clearlogs') {
            $del = $this->delcache();
            if ($del == true) {
                $this->success('清理完成');
            } else {
                $this->error('清理失败，请检查权限及防篡改.' . $del);
            }
        } elseif ($type == 'getGetNetWork') {
            return $btpanel->btAction->GetNetWork();
        } elseif ($type == 're_panel') {
            if ($btpanel->btAction->RepPanel() == 'true') {
                $this->success('修复成功，请刷新当前页面');
            } else {
                $this->error('修复失败');
            }
        } elseif ($type == 'reweb') {
            if ($btpanel->btAction->ReWeb()) {
                $this->success('重启面板成功，请刷新当前页面');
            } else {
                $this->error('重启失败');
            }
        } elseif ($type == "CloseLogs") {
            if ($fileSize = $btpanel->btAction->CloseLogs()) {
                $this->success('剩余日志大小：' . $fileSize);
            } else {
                $this->error('失败');
            }
        } elseif ($type == "Close_Recycle_bin") {
            if ($Close_Recycle = $btpanel->btAction->Close_Recycle_bin()) {
                $this->success('清理成功');
            } else {
                $this->error('失败');
            }
        } elseif ($type == "reboot") {
            if ($btpanel->btAction->RestartServer()) {
                $this->success('服务器已重启，请等待服务器启动');
            } else {
                $this->error('重启失败');
            }
        } elseif ($type == "getfile") {
            // 这里要考虑到windows与linux不同文件路径
            $file = input('post.file');
            switch ($file) {
                case 'default':
                    $file = '/www/server/panel/data/defaultDoc.html';
                    break;
                case '404':
                    $file = '/www/server/panel/data/404.html';
                    break;
                case 'nosite':
                    $file = '/www/server/nginx/html/index.html';
                    break;
                case 'stop':
                    $file = '/www/server/stop/index.html';
                    break;
                default:
                    $this->error('请求错误');
                    break;
            }
            if ($fileBody = $btpanel->btAction->GetFileBodys($file)) {
                return ['code' => 200, 'msg' => '获取成功', 'fileBody' => $fileBody];
            } else {
                $this->error('获取失败');
            }
        } elseif ($type == "savefile") {
            // 这里要考虑到windows与linux不同文件路径
            $file  = input('post.file');
            $value = input('post.value', '', null);
            switch ($file) {
                case 'default':
                    $file = '/www/server/panel/data/defaultDoc.html';
                    break;
                case '404':
                    $file = '/www/server/panel/data/404.html';
                    break;
                case 'nosite':
                    $file = '/www/server/nginx/html/index.html';
                    break;
                case 'stop':
                    $file = '/www/server/stop/index.html';
                    break;
                default:
                    $this->error('请求错误');
                    break;
            }
            if ($fileBody = $btpanel->btAction->SaveFileBodys($value, $file)) {
                $this->success('保存成功');
            } else {
                $this->error('保存失败');
            }
        } elseif ($type == 'checkUp') {
            $checkUp = $btpanel->btAction->UpdatePanel('true');
            if ($checkUp && isset($checkUp['status']) && $checkUp['status'] == 'true') {
                $this->success($checkUp['msg']['updateMsg']);
            } else {
                $this->error('当前版本：' . $checkUp['msg']["version"] . '.暂无更新');
            }
        } elseif ($type == 'update') {
            $UpdatePanels = $btpanel->btAction->UpdatePanels();
            if ($UpdatePanels && isset($UpdatePanels['status']) && $UpdatePanels['status'] == 'true') {
                //重启面板
                $btpanel->btAction->ReWeb();
                $this->success($UpdatePanels['msg']);
            } else {
                $this->error('升级失败');
            }
        } elseif ($type == "install") {
            $sName   = input('post.sName');
            $version = input('post.version');
            if (!$sName) {
                $this->error('软件名不能为空');
            }

            $install = $btpanel->btAction->InstallPlugin($sName, $version);
            //dump($install);exit();
            if ($install && isset($install['status']) && $install['status'] == 'true') {
                $this->success($install['msg']);
            } elseif ($install && isset($install['status'])) {
                $this->error($install['msg']);
            } else {
                $this->error('安装失败');
            }
        } elseif ($type == "uninstall") {
            $version = input('post.version');
            $sName   = input('post.sName');
            if (!$sName || !$version) {
                $this->error('软件名和版本号不能为空');
            }

            $uninstall = $btpanel->btAction->UnInstallPlugin($sName, $version);
            if ($uninstall && isset($uninstall['status']) && $uninstall['status'] == 'true') {
                $this->success($uninstall['msg']);
            } elseif ($uninstall && isset($uninstall['status'])) {
                $this->error($uninstall['msg']);
            } else {
                $this->error('卸载失败');
            }
        } elseif ($type == "softUp") {
            $sName = input('post.sName');
            if (!$sName) {
                $this->error('软件名不能为空');
            }

            $uninstall = $btpanel->btAction->InstallPlugin($sName, '', '', 1);
            if ($uninstall && isset($uninstall['status']) && $uninstall['status'] == 'true') {
                $this->success($uninstall['msg']);
            } elseif ($uninstall && isset($uninstall['status'])) {
                $this->error($uninstall['msg']);
            } else {
                $this->error('升级失败');
            }
        }

        $dirSize = $this->dirsize(ROOT_PATH . 'logs');


        $GetDiskInfo    = $btpanel->btAction->GetDiskInfo(); //获取硬盘及分区大小
        $GetSystemTotal = $btpanel->btAction->GetSystemTotal(); //获取系统信息

        $hostCount      = $btpanel->btAction->Websites('', '1', '999'); //获取站点数量
        $ftpCount       = $btpanel->btAction->WebFtpList('', '1', '999'); //获取ftp数量
        $sqlCount       = $btpanel->btAction->WebSqlList('', '1', '999'); //获取sql数量

        if (isset($GetSystemTotal['system']) && mb_stristr($GetSystemTotal['system'], 'windows')) {
            $isWindows = 1;
        } else {
            $isWindows = 0;
        }


        $this->view->assign("GetDiskInfo", $GetDiskInfo);
        $this->view->assign("GetSystemTotal", $GetSystemTotal);

        $this->view->assign("isWindows", $isWindows);

        $this->view->assign("hostCount", isset($hostCount['data']) ? count($hostCount['data']) : '0');
        $this->view->assign("ftpCount", isset($ftpCount['data']) ? count($ftpCount['data']) : '0');
        $this->view->assign("sqlCount", isset($sqlCount['data']) ? count($sqlCount['data']) : '0');

        // 面板操作日志
        $logsList = $btpanel->panelLogs();
        // $logsList = $btpanel->btAction->getPanelLogs();
        
        // 获取总数及分页数
        // preg_match('/共(.*?)条/',$logsList['page'],$str);
        // // 总数
        // $logsCount = isset($str[1])?$str[1]:'';
        
        $this->view->assign('logsList',$logsList);
        
        $this->view->assign([
            'totaluser'        => model('User')->count(),
            'totalviews'       => 219390,
            'totalorder'       => 32143,
            'totalorderamount' => 174800,
            'todayuserlogin'   => 321,
            'todayusersignup'  => $todayusersignup,
            'todayorder'       => 2324,
            'unsettleorder'    => 132,
            'sevendnu'         => '80%',
            'sevendau'         => '32%',
            'paylist'          => $paylist,
            'auditCount'       => $auditCount,
            'endCount'         => $endCount,
            'endtime7Count'     => $endtime7Count,
            'createlist'       => $createlist,
            'addonversion'     => $addonVersion,
            'uploadmode'       => $uploadmode,
            'logsSize'         => $dirSize,
        ]);

        return $this->view->fetch();
    }

    // 软件管理
    public function soft(){
        $page           = input('get.page') ? input('get.page') : 1;
        $softType       = input('get.softType/d') ? input('get.softType/d') : 5;

        $btpanel = new Btaction();

        $GetSoftList    = $btpanel->btAction->GetSoftList('', $page, $softType); //获取软件运行环境列表

        $this->view->assign("GetSoftList", $GetSoftList);
        return $this->view->fetch();
    }

    // 面板日志
    public function panelLogs(){

        
        $btpanel = new Btaction();
        
        return $this->view->fetch();
    }

    /**
     * 删除上传临时文件
     * @Author   Youngxj
     * @DateTime 2019-07-30
     * @return   [type]     [description]
     */
    public function delcache()
    {
        $dirName = ROOT_PATH . 'logs';
        try {
            if (file_exists($dirName) && $handle = opendir($dirName)) {
                while (false !== ($item = readdir($handle))) {
                    if ($item != "." && $item != "..") {
                        if (file_exists($dirName . '/' . $item) && is_dir($dirName . '/' . $item)) {
                            delFiles($dirName . '/' . $item);
                        } else {
                            if (@unlink($dirName . '/' . $item)) {
                            }
                        }
                    }
                }
                closedir($handle);
            }
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 获取目录大小
     * @Author   Youngxj
     * @DateTime 2019-08-27
     * @param    [type]     $dir 目录路径
     * @return   [type]          [description]
     */
    private function dirsize($dir)
    {
        @$dh  = opendir($dir);
        $size = 0;
        while ($file = @readdir($dh)) {
            if ($file != "." and $file != "..") {
                $path = $dir . "/" . $file;
                if (is_dir($path)) {
                    $size += $this->dirsize($path);
                } elseif (is_file($path)) {
                    $size += filesize($path);
                }
            }
        }
        @closedir($dh);
        return $size;
    }
}